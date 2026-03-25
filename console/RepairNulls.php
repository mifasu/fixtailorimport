<?php namespace DMdev\FixTailorImport\Console;

use DB;
use Log;
use Illuminate\Console\Command;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Classes\Blueprint\StructureBlueprint;
use DMdev\FixTailorImport\Services\SlugFixer;
use DMdev\FixTailorImport\Services\StructurePostProcessor;

/**
 * RepairNulls — одна команда для исправления уже импортированных записей.
 *
 * Используется когда данные были загружены до установки плагина и в таблице:
 *  - slug пустой или технический (slug-<random>)
 *  - nest_left / nest_right / nest_depth = null (для structure-секций)
 *  - fullslug пустой (для structure-секций)
 *
 * Пример:
 *
 *   php artisan tailor:fix-nulls "Catalog\Category" --dry-run
 *   php artisan tailor:fix-nulls "Catalog\Category"
 */
class RepairNulls extends Command
{
    protected $signature = 'tailor:fix-nulls
        {section  : Handle секции (например "Catalog\\Category")}
        {--dry-run : Показать что будет сделано, ничего не сохранять}';

    protected $description = 'Исправляет NULL-поля (slug, nest_left/right/depth, fullslug) в уже импортированных записях.';

    public function handle(): int
    {
        $sectionHandle = $this->argument('section');
        $dryRun        = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('═══ DRY-RUN: изменения не сохраняются ═══');
        }

        $blueprint = BlueprintIndexer::instance()->findSectionByHandle($sectionHandle);

        if (!$blueprint) {
            $this->error("Секция '{$sectionHandle}' не найдена.");
            return Command::FAILURE;
        }

        $this->info("Секция: {$blueprint->handle}");
        $this->line('');

        // 1. Slug из title для пустых / технических значений
        $this->fixSlugs($blueprint, $dryRun);

        // 2. Для structure-секций: nested tree + fullslug
        if ($blueprint instanceof StructureBlueprint) {
            $this->line('');
            try {
                // rebuildOnly=true — применяем только пересборку дерева и fullslug,
                // без применения rules из конфига
                StructurePostProcessor::processSection($sectionHandle, $dryRun, true, $this);
            } catch (\Throwable $e) {
                $this->error($e->getMessage());
                return Command::FAILURE;
            }
        }

        $this->line('');
        $this->info('═══ Готово ═══');

        return Command::SUCCESS;
    }

    // -------------------------------------------------------------------------

    protected function fixSlugs($blueprint, bool $dryRun): void
    {
        $this->info('Проверка slug...');

        $model = $blueprint->newModelInstance();
        $model->extendWithBlueprint($blueprint->uuid);
        $table = $model->getTable();

        $records = $model->newQueryWithoutScopes()
            ->where(function ($q) {
                $q->whereNull('slug')
                  ->orWhere('slug', '')
                  ->orWhere('slug', 'LIKE', 'slug-%');
            })
            ->get(['id', 'slug', 'title', 'blueprint_uuid']);

        // Точный фильтр: только пустые или технические slug-<32hex>
        $needFix = $records->filter(fn($r) =>
            $r->slug === null
            || (string) $r->slug === ''
            || SlugFixer::isTechnicalSlug((string) $r->slug)
        );

        if ($needFix->isEmpty()) {
            $this->line('  Нет записей с проблемным slug.');
            return;
        }

        $this->line("  Найдено {$needFix->count()} записей с пустым или техническим slug.");

        $fixed  = 0;
        $errors = 0;

        foreach ($needFix as $record) {
            $title = trim((string) ($record->title ?? ''));

            if ($title === '') {
                $this->line("  <comment>Пропуск [{$record->id}]</comment> — нет title.");
                $errors++;
                continue;
            }

            try {
                $newSlug = SlugFixer::generateUniqueSlug($record, $title);
                $this->line("  [{$record->id}] «{$title}» → {$newSlug}");

                if (!$dryRun) {
                    DB::table($table)
                        ->where($record->getKeyName(), $record->getKey())
                        ->update(['slug' => $newSlug]);
                }

                $fixed++;
            } catch (\Throwable $e) {
                $this->line("  <error>[{$record->id}] {$e->getMessage()}</error>");
                Log::warning("FixTailorImport RepairNulls slug [{$record->id}]: " . $e->getMessage());
                $errors++;
            }
        }

        $this->line("  ✓ slug исправлено: {$fixed}" . ($errors ? ", ошибок: {$errors}" : '') . '.');
    }
}
