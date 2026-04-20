<?php namespace DMdev\FixTailorImport\Models;

use Site;
use Tailor\Models\RecordImport;

/**
 * FixedRecordImport — расширение импорта для Tailor.
 *
 * Исправляет два бага ядра OctoberCMS:
 *
 * 1. БАГИ update_existing: после extendWithBlueprint() метод addFillable() выставляет
 *    непустой $fillable с полями блюпринта. Laravel's isFillable('update_existing')
 *    начинает возвращать false, и fill(['update_existing' => '1']) в actionImport
 *    молча игнорируется — флаг никогда не устанавливается, запись пропускается
 *    даже когда пользователь поставил галку "Обновить существующие записи".
 *    Фикс: переопределяем extendWithBlueprint() и добавляем update_existing в fillable.
 *
 * 2. БАГ пустого id: строки без поля id пропускаются ("Missing entry ID"),
 *    хотя должны создаваться как новые записи с автоинкрементным id.
 *    Фикс: убираем id из $data если он пуст, чтобы БД выставила автоинкремент.
 *
 * Активируется через IoC-биндинг в Plugin::register(), заменяя оригинальный
 * RecordImport без изменений ядра.
 */
class FixedRecordImport extends RecordImport
{
    /**
     * extendWithBlueprint — расширяем модель блюпринтом и явно добавляем
     * update_existing в fillable, чтобы fill() в actionImport не игнорировал его.
     *
     * Корневая причина бага: applyModelExtensions() вызывает addFillable() с полями
     * блюпринта, после чего $fillable становится непустым. Laravel's isFillable()
     * возвращает false для любого атрибута, не входящего в этот список.
     * update_existing — служебный атрибут модели импорта, а не поле блюпринта,
     * поэтому его нужно добавить вручную.
     */
    public function extendWithBlueprint()
    {
        parent::extendWithBlueprint();

        // Добавляем служебные атрибуты модели импорта в fillable,
        // чтобы actionImport мог их заполнять через fill($optionData).
        $this->addFillable(['update_existing']);
    }

    /**
     * importData — дублирует логику родителя, убирая проверку "нет id → пропустить".
     *
     * Если id пуст: удаляем его из массива данных, чтобы:
     *   а) findDuplicateRecord искал по title/slug (дедупликация),
     *   б) decodeModelAttribute не выставлял $record->id = null (ошибка БД),
     *   в) БД заполнила id автоинкрементом.
     *
     * Если id указан: findDuplicateRecord находит запись по id.
     *   Если запись найдена и update_existing = true — обновляем только
     *   те колонки, которые были в CSV (не трогаем остальные).
     */
    public function importData($results, $sessionKey = null)
    {
        foreach ($results as $row => $data) {
            // Если id пуст — убираем ключ, создаём новую запись
            $id = array_get($data, 'id');
            if (!$id) {
                unset($data['id']);
            }

            // Найти дубль или создать новый экземпляр модели
            $record = $this->findDuplicateRecord($data) ?: $this->resolveBlueprintModel();
            $exists = $record->exists;

            if ($exists) {
                if (!$this->update_existing) {
                    $this->logSkipped($row, "Record already exists");
                    continue;
                }

                if ($record->site_id && $record->site_id !== Site::getSiteIdFromContext()) {
                    $this->logSkipped($row, "Record ID exists in another site");
                    continue;
                }
            }

            // Записать только те атрибуты, которые пришли из CSV
            foreach ($data as $attr => $value) {
                $this->decodeModelAttribute($record, $attr, $value, $sessionKey);
            }

            $record->forceSave(null, $sessionKey);

            if ($exists) {
                $this->logUpdated();
            } else {
                $this->logCreated();
            }
        }
    }
}
