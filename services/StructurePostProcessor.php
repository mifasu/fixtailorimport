<?php namespace DMdev\FixTailorImport\Services;

use DB;
use Log;
use Config;
use Tailor\Models\EntryRecord;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Classes\RecordIndexer;
use Tailor\Classes\Blueprint\StructureBlueprint;
use SystemException;

/**
 * StructurePostProcessor — постобработка structure-секций после импорта.
 *
 * Применяет правила категорий (category → parent) из конфига плагина,
 * обновляет parent_id через прямой DB-запрос (без событий модели),
 * затем вызывает штатное пересторение дерева через resetTreeOrphans()
 * и resetTreeNesting().
 *
 * Используется командой `php artisan tailor:fix-structure`.
 */
class StructurePostProcessor
{
    /** @var \Tailor\Classes\Blueprint\StructureBlueprint */
    protected StructureBlueprint $blueprint;

    /** @var \Tailor\Models\StructureRecord */
    protected EntryRecord $model;

    /** @var array Объединённая конфигурация секции */
    protected array $config;

    /** @var array<int, int> Кэш: значение_категории → parent_id */
    protected array $parentCache = [];

    /** @var \Illuminate\Console\Command|null */
    protected $command = null;

    /** @var bool Dry-run режим — ничего не сохранять */
    protected bool $dryRun = false;

    // -------------------------------------------------------------------------
    // Статическая точка входа
    // -------------------------------------------------------------------------

    /**
     * processSection — полный цикл постобработки секции.
     *
     * @param string $sectionHandle Например: 'Catalog\Category'
     * @param bool   $dryRun        Только показать что будет сделано
     * @param bool   $rebuildOnly   Не применять rules, только пересобрать дерево
     * @param \Illuminate\Console\Command|null $command  Командная строка для вывода
     */
    public static function processSection(
        string $sectionHandle,
        bool $dryRun = false,
        bool $rebuildOnly = false,
        $command = null
    ): array {
        $processor = new self($sectionHandle, $command, $dryRun);
        return $processor->run($rebuildOnly);
    }

    // -------------------------------------------------------------------------
    // Constructor & run
    // -------------------------------------------------------------------------

    public function __construct(string $sectionHandle, $command = null, bool $dryRun = false)
    {
        $this->command = $command;
        $this->dryRun  = $dryRun;
        $this->initSection($sectionHandle);
    }

    protected function initSection(string $handle): void
    {
        $blueprint = BlueprintIndexer::instance()->findSectionByHandle($handle);

        if (!$blueprint) {
            throw new SystemException("Секция '{$handle}' не найдена в blueprints.");
        }

        if (!($blueprint instanceof StructureBlueprint)) {
            throw new SystemException("Секция '{$handle}' не является секцией типа structure.");
        }

        $this->blueprint = $blueprint;

        // Получаем правильный экземпляр модели (StructureRecord) через blueprint
        $this->model = $blueprint->newModelInstance();
        $this->model->extendWithBlueprint($blueprint->uuid);

        // Читаем конфигурацию плагина
        $this->config = Config::get(
            'dmdev.fixtailorimport::sections.' . $handle,
            []
        );
    }

    /**
     * run — основной процесс.
     * Возвращает статистику: ['updated' => N, 'skipped' => N, 'errors' => [...]]
     */
    protected function run(bool $rebuildOnly): array
    {
        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        if (!$rebuildOnly) {
            $structureConfig = $this->config['structure'] ?? null;

            if (!$structureConfig) {
                $this->line(
                    "<comment>Нет секции 'structure' в конфиге для {$this->blueprint->handle}.</comment> "
                    . "Выполняю только пересборку дерева."
                );
            } else {
                $stats = $this->applyRules($structureConfig);
            }
        }

        $this->rebuildTree();

        return $stats;
    }

    // -------------------------------------------------------------------------
    // Применение правил категорий
    // -------------------------------------------------------------------------

    /**
     * applyRules — для каждой записи секции определяет parent_id по правилам конфига.
     */
    protected function applyRules(array $structureConfig): array
    {
        $categoryField  = $structureConfig['category_field'] ?? null;
        $parentResolve  = $structureConfig['parent_resolve'] ?? 'title';
        $rules          = $structureConfig['rules'] ?? [];

        if (!$categoryField) {
            $this->line('<error>category_field не задан в конфиге structure.</error>');
            return ['updated' => 0, 'skipped' => 0, 'errors' => ['category_field не задан']];
        }

        if (empty($rules)) {
            $this->line('<comment>Нет правил rules в конфиге structure.</comment>');
            return ['updated' => 0, 'skipped' => 0, 'errors' => []];
        }

        $stats = ['updated' => 0, 'skipped' => 0, 'errors' => []];

        $records = $this->model
            ->newQueryWithoutScopes()
            ->get();

        $this->line("Обрабатываю {$records->count()} записей...");

        foreach ($records as $record) {
            try {
                $result = $this->processOneRecord($record, $categoryField, $parentResolve, $rules);

                if ($result === 'updated') {
                    $stats['updated']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (\Throwable $e) {
                $msg = "[{$record->id}] {$record->title}: " . $e->getMessage();
                $stats['errors'][] = $msg;
                Log::warning("FixTailorImport: {$msg}");
                $this->line("<error>{$msg}</error>");
            }
        }

        return $stats;
    }

    /**
     * processOneRecord — обрабатывает одну запись.
     * Возвращает 'updated' или 'skipped'.
     */
    protected function processOneRecord(
        EntryRecord $record,
        string $categoryField,
        string $parentResolve,
        array $rules
    ): string {
        $categoryValue = (string) ($record->$categoryField ?? '');

        if ($categoryValue === '' || !array_key_exists($categoryValue, $rules)) {
            $this->line(
                "  <comment>Пропуск [{$record->id}] «{$record->title}»</comment>"
                . " — категория «{$categoryValue}» нет в rules."
            );
            return 'skipped';
        }

        $rule      = $rules[$categoryValue];
        $parentRef = $rule['parent'] ?? null;
        $extraSet  = $rule['set'] ?? [];

        // Определяем parent_id
        $newParentId = null;
        if ($parentRef !== null) {
            $newParentId = $this->resolveParentId($parentRef, $parentResolve);
            if ($newParentId === null) {
                $this->line(
                    "  <comment>Пропуск [{$record->id}] «{$record->title}»</comment>"
                    . " — родитель «{$parentRef}» не найден, устанавливаю в корень."
                );
                // Fallback: помещаем в корень (null parent_id)
            }
        }

        $updates = ['parent_id' => $newParentId] + $extraSet;

        $changed = array_filter($updates, fn($v, $k) => $record->$k !== $v, ARRAY_FILTER_USE_BOTH);

        if (empty($changed)) {
            return 'skipped';
        }

        $parentLabel = $newParentId ? "parent_id={$newParentId}" : 'корень';
        $this->line("  [{$record->id}] «{$record->title}» → {$parentLabel}");

        if (!$this->dryRun) {
            // Обновляем через DB, не через модель, чтобы не триггерить
            // события NestedTree (moveToNewParent и т.д.) — дерево
            // восстановим единоразово через resetTreeNesting().
            DB::table($this->model->getTable())
                ->where($this->model->getKeyName(), $record->getKey())
                ->update($updates);
        }

        return 'updated';
    }

    /**
     * resolveParentId — ищет родительскую запись по slug или title.
     */
    protected function resolveParentId(string $ref, string $field): ?int
    {
        $cacheKey = "{$field}:{$ref}";

        if (array_key_exists($cacheKey, $this->parentCache)) {
            return $this->parentCache[$cacheKey];
        }

        $parent = $this->model
            ->newQueryWithoutScopes()
            ->where($field, $ref)
            ->first();

        $id = $parent ? (int) $parent->getKey() : null;
        $this->parentCache[$cacheKey] = $id;

        return $id;
    }

    // -------------------------------------------------------------------------
    // Пересборка дерева
    // -------------------------------------------------------------------------

    /**
     * rebuildTree — штатная пересборка nested set через модель.
     *
     * Последовательность:
     * 1. resetTreeOrphans() — сбрасывает parent_id у «сирот» (чей родитель удалён).
     * 2. resetTreeNesting() — полностью перестраивает nest_left, nest_right, nest_depth
     *    на основе фактических parent_id. Работает в транзакции.
     */
    protected function rebuildTree(): void
    {
        $this->line('Пересборка nested tree...');

        if ($this->dryRun) {
            $this->line('<comment>[dry-run] Пересборка пропущена.</comment>');
            return;
        }

        try {
            $this->model->resetTreeOrphans();
            $this->line('  ✓ resetTreeOrphans выполнен.');
        } catch (\Throwable $e) {
            $this->line('<error>resetTreeOrphans: ' . $e->getMessage() . '</error>');
            Log::error('FixTailorImport resetTreeOrphans: ' . $e->getMessage());
        }

        try {
            $this->model->resetTreeNesting();
            $this->line('  ✓ resetTreeNesting выполнен.');
        } catch (\Throwable $e) {
            $this->line('<error>resetTreeNesting: ' . $e->getMessage() . '</error>');
            Log::error('FixTailorImport resetTreeNesting: ' . $e->getMessage());
        }

        $this->rebuildFullSlugs();
    }

    /**
     * rebuildFullSlugs — пересборка fullslug для всех записей секции.
     *
     * Использует RecordIndexer::processFullSlug(), который рекурсивно
     * обходит дерево от корневых записей. Требует StructureRecord
     * с relations parent/children — $this->model уже является таковым.
     */
    protected function rebuildFullSlugs(): void
    {
        $this->line('Пересборка fullslug...');

        if ($this->dryRun) {
            $this->line('<comment>[dry-run] Пересборка fullslug пропущена.</comment>');
            return;
        }

        try {
            $roots = $this->model
                ->newQueryWithoutScopes()
                ->whereNull('parent_id')
                ->get();

            foreach ($roots as $root) {
                RecordIndexer::instance()->processFullSlug($root);
            }

            $this->line('  ✓ fullslug пересобран.');
        } catch (\Throwable $e) {
            $this->line('<error>fullslug rebuild: ' . $e->getMessage() . '</error>');
            Log::error('FixTailorImport fullslug rebuild: ' . $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    protected function line(string $message): void
    {
        if ($this->command) {
            $this->command->line($message);
        }
    }
}
