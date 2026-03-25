<?php namespace DMdev\FixTailorImport\Models;

use Site;
use Tailor\Models\RecordImport;

/**
 * FixedRecordImport — расширение импорта для Tailor.
 *
 * Единственное отличие от родительского RecordImport:
 * строки без поля id не пропускаются (logSkipped), а создаются как новые
 * записи с автоинкрементным id от базы данных.
 *
 * Активируется через IoC-биндинг в Plugin::boot(), заменяя оригинальный
 * RecordImport без изменений ядра.
 */
class FixedRecordImport extends RecordImport
{
    /**
     * importData — дублирует логику родителя, убирая проверку "нет id → пропустить".
     *
     * Если id пуст: удаляем его из массива данных, чтобы:
     *   а) findDuplicateRecord искал по title/slug (дедупликация),
     *   б) decodeModelAttribute не выставлял $record->id = null (ошибка БД),
     *   в) БД заполнила id автоинкрементом.
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

            // Записать атрибуты из CSV
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
