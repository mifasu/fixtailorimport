<?php namespace Dmdev\FixTailorImport\Console;

use Illuminate\Console\Command;
use Dmdev\FixTailorImport\Services\StructurePostProcessor;
use Exception;

/**
 * RepairStructure — постобработка structure-секции после CSV-импорта.
 *
 * Применяет правила категорий (category → parent_id) из конфига плагина,
 * затем штатно пересобирает nested tree.
 *
 * Пример использования:
 *
 *   # Полная обработка с выводом что изменится (без сохранения)
 *   php artisan tailor:fix-structure "Catalog\Category" --dry-run
 *
 *   # Применить правила и пересобрать дерево
 *   php artisan tailor:fix-structure "Catalog\Category"
 *
 *   # Только пересобрать дерево, без применения rules
 *   php artisan tailor:fix-structure "Catalog\Category" --rebuild-only
 */
class RepairStructure extends Command
{
    protected $signature = 'tailor:fix-structure
        {section : Handle секции (например "Catalog\\Category")}
        {--dry-run    : Показать что будет сделано, ничего не сохранять}
        {--rebuild-only : Не применять rules, только пересобрать дерево}';

    protected $description = 'Применяет правила категорий и пересобирает nested tree для Tailor structure-секции.';

    public function handle(): int
    {
        $section     = $this->argument('section');
        $dryRun      = (bool) $this->option('dry-run');
        $rebuildOnly = (bool) $this->option('rebuild-only');

        if ($dryRun) {
            $this->warn('═══ DRY-RUN: изменения не сохраняются ═══');
        }

        $this->info("Секция: {$section}");
        $this->line('');

        try {
            $stats = StructurePostProcessor::processSection(
                $section,
                $dryRun,
                $rebuildOnly,
                $this
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return Command::FAILURE;
        }

        $this->line('');
        $this->info('═══ Готово ═══');
        $this->info("Обновлено:  {$stats['updated']}");
        $this->info("Пропущено:  {$stats['skipped']}");

        if (!empty($stats['errors'])) {
            $this->warn("Ошибок:     " . count($stats['errors']));
            foreach ($stats['errors'] as $err) {
                $this->error("  {$err}");
            }
        }

        return Command::SUCCESS;
    }
}
