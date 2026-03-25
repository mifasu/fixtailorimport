<?php namespace Dmdev\FixTailorImport;

use DB;
use System\Classes\PluginBase;
use Tailor\Models\EntryRecord;
use Dmdev\FixTailorImport\Models\FixedRecordImport;
use Dmdev\FixTailorImport\Services\SlugFixer;

/**
 * Plugin registration file for Dmdev.FixTailorImport
 *
 * Fixes slug generation and structure parent assignment during Tailor CSV imports.
 * Works without modifying core, modules/* or vendor/*.
 */
class Plugin extends PluginBase
{
    public function pluginDetails(): array
    {
        return [
            'name'        => 'Fix Tailor Import',
            'description' => 'Fixes slug auto-generation and structure tree rebuilding for Tailor CSV imports.',
            'author'      => 'Dmdev',
            'icon'        => 'icon-wrench',
        ];
    }

    /**
     * register — регистрируем artisan-команды.
     */
    public function register(): void
    {
        // Заменяем RecordImport нашей версией через IoC, чтобы не редактировать ядро.
        // App::make('Tailor\Models\EntryRecordImport') вернёт FixedRecordImport.
        $this->app->bind(
            'Tailor\Models\EntryRecordImport',
            FixedRecordImport::class
        );

        $this->registerConsoleCommand(
            'tailor.fix-nulls',
            \Dmdev\FixTailorImport\Console\RepairNulls::class
        );

        $this->registerConsoleCommand(
            'tailor.fix-structure',
            \Dmdev\FixTailorImport\Console\RepairStructure::class
        );

        $this->registerConsoleCommand(
            'tailor.fix-slugs',
            \Dmdev\FixTailorImport\Console\RepairSlugs::class
        );
    }

    /**
     * boot — подключаем slug-фикс через расширение EntryRecord.
     *
     * Хук model.beforeValidate вызывается ДО EntryRecord::beforeValidate(),
     * что позволяет нам выставить нормальный slug раньше, чем система
     * выставит технический slug-<random>.
     * Это безопасно и для ручного редактирования, и для импорта.
     */
    public function boot(): void
    {
        $this->bootExtensions();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    protected function bootExtensions(): void
    {
        EntryRecord::extend(function ($model) {

            // 1. Slug из title до того, как OctoberCMS поставит технический slug-XXXX
            $model->bindEvent('model.beforeValidate', function () use ($model) {
                SlugFixer::maybeSetSlug($model);
            });

            // 2. Nest-значения для structure-записей, создаваемых через импорт.
            //    При импорте модель — EntryRecord (не StructureRecord), поэтому
            //    трейт NestedTreeModel не активен и nest_left/right/depth = null.
            //    StructureRecord через свой initializeNestedTreeModel() ставит эти
            //    значения раньше нас → наша проверка «!== null» предотвращает
            //    двойную запись.
            $model->bindEvent('model.beforeCreate', function () use ($model) {
                if (empty($model->blueprint_uuid)) {
                    return;
                }

                try {
                    if (!$model->isEntryStructure()) {
                        return;
                    }
                } catch (\Throwable) {
                    return;
                }

                // Уже выставлено NestedTreeModel или CSV-колонкой — не трогаем
                if ($model->getAttribute('nest_left') !== null) {
                    return;
                }

                $maxRight = (int) DB::table($model->getTable())
                    ->whereNotNull('nest_right')
                    ->max('nest_right');

                $model->setAttribute('nest_left',  $maxRight + 1);
                $model->setAttribute('nest_right', $maxRight + 2);
                $model->setAttribute('nest_depth', 0);
            });

            // 3. fullslug для structure-записей.
            //    RecordIndexer::processFullSlug() вызывается только из
            //    backend-форм (Entries::formAfterSave), но не во время импорта.
            //    Используем прямой DB-запрос для чтения родительского fullslug,
            //    чтобы не зависеть от relations NestedTreeModel.
            $model->bindEvent('model.afterSave', function () use ($model) {
                if (empty($model->blueprint_uuid)) {
                    return;
                }

                try {
                    if (!$model->isEntryStructure()) {
                        return;
                    }
                } catch (\Throwable) {
                    return;
                }

                $key = $model->getKey();
                if (!$key) {
                    return;
                }

                $slug     = (string) ($model->slug ?? '');
                $parentId = $model->getAttribute('parent_id');

                if ($parentId) {
                    $parentFullslug = DB::table($model->getTable())
                        ->where($model->getKeyName(), $parentId)
                        ->value('fullslug');

                    $fullslug = $parentFullslug
                        ? rtrim((string) $parentFullslug, '/') . '/' . $slug
                        : $slug;
                } else {
                    $fullslug = $slug;
                }

                // Пропускаем, если значение не изменилось (защита от рекурсии
                // при повторном save() из RecordIndexer)
                if ($model->getAttribute('fullslug') === $fullslug) {
                    return;
                }

                DB::table($model->getTable())
                    ->where($model->getKeyName(), $key)
                    ->update(['fullslug' => $fullslug]);

                $model->setAttribute('fullslug', $fullslug);
            });
        });
    }
}
