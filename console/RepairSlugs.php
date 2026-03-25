<?php namespace Dmdev\FixTailorImport\Console;

use DB;
use Log;
use Str;
use Illuminate\Console\Command;
use Tailor\Classes\BlueprintIndexer;
use Tailor\Classes\Blueprint\EntryBlueprint;
use Dmdev\FixTailorImport\Services\SlugFixer;
use Exception;

/**
 * RepairSlugs — ретроспективная замена технических slug-<random> на нормальные.
 *
 * Используется когда записи уже были сохранены с техническим slug (например,
 * импорт был выполнен до установки плагина).
 *
 * Пример использования:
 *
 *   # Показать сколько записей нужно исправить
 *   php artisan tailor:fix-slugs --dry-run
 *
 *   # Исправить только конкретную секцию
 *   php artisan tailor:fix-slugs "Catalog\Category"
 *
 *   # Исправить все секции
 *   php artisan tailor:fix-slugs
 */
class RepairSlugs extends Command
{
    protected $signature = 'tailor:fix-slugs
        {section?    : Handle секции (опционально, если пусто — все секции)}
        {--dry-run   : Показать что будет сделано, ничего не сохранять}';

    protected $description = 'Заменяет технические slug-<random> на нормальные slugs из title.';

    /** @var array Статистика по всем секциям */
    protected array $globalStats = ['fixed' => 0, 'errors' => []];

    public function handle(): int
    {
        $sectionHandle = $this->argument('section');
        $dryRun        = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('═══ DRY-RUN: изменения не сохраняются ═══');
        }

        $indexer = BlueprintIndexer::instance();

        if ($sectionHandle) {
            $blueprints = array_filter(
                [$indexer->findSectionByHandle($sectionHandle)],
                fn($b) => $b !== null
            );
            if (empty($blueprints)) {
                $this->error("Секция '{$sectionHandle}' не найдена.");
                return Command::FAILURE;
            }
        } else {
            $blueprints = array_filter(
                iterator_to_array($indexer->listSections()),
                fn($b) => $b instanceof EntryBlueprint
            );
        }

        foreach ($blueprints as $blueprint) {
            $this->processBlueprint($blueprint, $dryRun);
        }

        $this->line('');
        $this->info('═══ Итого по всем секциям ═══');
        $this->info("Исправлено slug: {$this->globalStats['fixed']}");
        if (!empty($this->globalStats['errors'])) {
            $this->warn('Ошибок: ' . count($this->globalStats['errors']));
        }

        return Command::SUCCESS;
    }

    protected function processBlueprint(EntryBlueprint $blueprint, bool $dryRun): void
    {
        $this->line("<info>Секция:</info> {$blueprint->handle}");

        $model = $blueprint->newModelInstance();
        $model->extendWithBlueprint($blueprint->uuid);
        $table = $model->getTable();

        // Ищем только записи с техническим slug (slug-[a-z0-9]{32})
        // Используем LIKE для быстрого первичного фильтра, regex для точного
        $records = $model->newQueryWithoutScopes()
            ->where('slug', 'LIKE', 'slug-%')
            ->get(['id', 'slug', 'title', 'blueprint_uuid']);

        $technicalRecords = $records->filter(
            fn($r) => SlugFixer::isTechnicalSlug((string) $r->slug)
        );

        $count = $technicalRecords->count();
        if ($count === 0) {
            $this->line('  Нет записей с техническим slug.');
            return;
        }

        $this->line("  Найдено {$count} записей с техническим slug.");

        foreach ($technicalRecords as $record) {
            try {
                $title = (string) $record->title;
                if ($title === '') {
                    $this->line("  <comment>Пропуск [{$record->id}]</comment> — нет title.");
                    $this->globalStats['errors'][] = "[{$record->id}] нет title";
                    continue;
                }

                // Генерируем slug через тот же сервис.
                // Для generateUniqueSlug нужна полноценная модель с blueprint.
                $newSlug = SlugFixer::generateUniqueSlug($record, $title);

                $this->line("  [{$record->id}] «{$title}» → {$newSlug}");

                if (!$dryRun) {
                    DB::table($table)
                        ->where($record->getKeyName(), $record->getKey())
                        ->update(['slug' => $newSlug]);
                }

                $this->globalStats['fixed']++;
            } catch (\Throwable $e) {
                $msg = "[{$record->id}]: " . $e->getMessage();
                $this->line("<error>{$msg}</error>");
                $this->globalStats['errors'][] = $msg;
                Log::warning("FixTailorImport RepairSlugs: {$msg}");
            }
        }
    }
}
