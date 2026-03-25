<?php namespace Dmdev\FixTailorImport\Services;

use Str;
use Tailor\Models\EntryRecord;

/**
 * SlugFixer — генерирует человекочитаемый slug из title.
 *
 * Вызывается из хука model.beforeValidate на EntryRecord,
 * т.е. ДО того, как EntryRecord::beforeValidate() поставит
 * технический slug-<random>.
 *
 * Безопасно работает и при импорте, и при ручном редактировании.
 */
class SlugFixer
{
    /**
     * Паттерн технического slug, который ставит OctoberCMS при отсутствии slug.
     * Формат: slug-[32 hex-символа].
     */
    const TECH_SLUG_PATTERN = '/^slug-[a-z0-9]{32}$/';

    // -------------------------------------------------------------------------
    // Публичный API
    // -------------------------------------------------------------------------

    /**
     * maybeSetSlug — точка входа из boot-хука.
     *
     * Выставляет slug из title если:
     *  - slug пустой, или
     *  - slug является техническим (slug-XXXX).
     *
     * Если title тоже пуст — пропускает (валидация сама вернёт ошибку).
     * Если slug уже выглядит нормально — не трогает.
     */
    public static function maybeSetSlug(EntryRecord $model): void
    {
        // Нет blueprint — ничего не делаем, модель ещё не инициализирована
        if (empty($model->blueprint_uuid)) {
            return;
        }

        $title = (string) $model->title;
        if ($title === '') {
            return;
        }

        $slug = (string) $model->slug;

        // Slug уже задан и не является техническим — не трогаем
        if ($slug !== '' && !self::isTechnicalSlug($slug)) {
            return;
        }

        $model->slug = self::generateUniqueSlug($model, $title);
    }

    /**
     * isTechnicalSlug — определяет, является ли slug техническим авто-заглушкой.
     */
    public static function isTechnicalSlug(string $slug): bool
    {
        return (bool) preg_match(self::TECH_SLUG_PATTERN, $slug);
    }

    /**
     * generateUniqueSlug — генерирует уникальный slug из title.
     *
     * Использует Str::slug() с транслитерацией через voku/portable-ascii.
     * Если ASCII-конвертация даёт пустую строку (редкий случай) —
     * fallback на первые 12 символов md5 от title.
     *
     * Добавляет суффикс -2, -3, … чтобы обеспечить уникальность в секции.
     */
    public static function generateUniqueSlug(EntryRecord $model, string $title): string
    {
        $base = Str::slug($title);

        if ($base === '') {
            // Fallback для полностью не-ASCII заголовков, которые portable-ascii
            // не смог транслитерировать (крайне редкий случай).
            $base = substr(md5($title), 0, 12);
        }

        $slug     = $base;
        $attempts = 0;

        while (self::slugExists($model, $slug)) {
            $slug = $base . '-' . rand(1000, 9999);

            // Защита от бесконечного цикла (100 попыток)
            if (++$attempts >= 100) {
                $slug = $base . '-' . substr(md5($title . microtime()), 0, 6);
                break;
            }
        }

        return $slug;
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    /**
     * slugExists — проверяет, занят ли slug в таблице данной секции.
     *
     * Намеренно используем newQueryWithoutScopes() чтобы не пропустить
     * slug, занятый черновиком или записью другого сайта в той же таблице.
     */
    protected static function slugExists(EntryRecord $model, string $slug): bool
    {
        try {
            $query = $model->newQueryWithoutScopes()->where('slug', $slug);

            // При обновлении существующей записи — исключаем саму себя
            if ($model->exists && ($key = $model->getKey())) {
                $query->where($model->getKeyName(), '!=', $key);
            }

            return $query->exists();
        } catch (\Throwable $e) {
            // Таблица ещё не создана или другая ранняя ошибка —
            // считаем, что slug свободен.
            return false;
        }
    }
}
