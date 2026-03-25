# Dmdev.FixTailorImport

Плагин для [OctoberCMS](https://octobercms.com) 4.x, исправляет распространённые проблемы при импорте Tailor-записей из Json/CSV.
Проставляет автоматом id, проставялет slug/fullslug на основе title, исправляет позиционирование structure сущностей (nest_left, nest_right, nest_depth) + возможность пробежаться по таблицам и исправить ранее добавленные кривые импорты.

**Что исправляет:**
- Slug генерируется из `title` вместо технического `slug-abc123…`
- Суффикс при конфликте slug — случайные цифры, а не `-2, -3`
- Строки без `id` в CSV создаются (а не пропускаются)
- `nest_left`, `nest_right`, `nest_depth` заполняются при создании записи в structure-секции
- `fullslug` строится автоматически после каждого сохранения

---

## Установка

```bash
# Скопируйте папку в plugins/dmdev/fixtailorimport/
php artisan october:migrate
```

---

## Команды

### `tailor:fix-nulls` — починить уже импортированные записи

Если данные были загружены **до** установки плагина — в таблице могут лежать пустые slug, нулевые nest-поля, пустой fullslug. Эта команда исправит всё за один проход:

```bash
php artisan tailor:fix-nulls "Catalog\Category" --dry-run
php artisan tailor:fix-nulls "Catalog\Category"
```

Что делает:
1. Исправляет пустые / технические slug → генерирует из `title`
2. Для structure-секций: пересобирает `nest_left / nest_right / nest_depth`
3. Для structure-секций: пересобирает `fullslug` по всему дереву

---

### `tailor:fix-structure` — применить правила категорий и пересобрать дерево

```bash
php artisan tailor:fix-structure "Catalog\Category" --dry-run
php artisan tailor:fix-structure "Catalog\Category"
php artisan tailor:fix-structure "Catalog\Category" --rebuild-only
```

Правила `category → parent_id` описываются в конфиге (см. ниже).
`--rebuild-only` — только пересобрать дерево, без применения правил.

---

### `tailor:fix-slugs` — ретроспективно исправить технические slug

```bash
php artisan tailor:fix-slugs --dry-run
php artisan tailor:fix-slugs "Catalog\Category"
php artisan tailor:fix-slugs
```

---

## Конфигурация (для `fix-structure`)

Файл: `plugins/dmdev/fixtailorimport/config/fixtailorimport.php`

```php
'sections' => [
    'Catalog\Category' => [
        'structure' => [
            'category_field' => 'category', // поле записи с именем категории из CSV
            'parent_resolve' => 'title',    // искать родителя по этому полю
            'rules' => [
                'Ювелирные украшения' => ['parent' => null],        // в корень
                'Кольца'             => ['parent' => 'Ювелирные украшения'],
                'Серьги'             => ['parent' => 'Ювелирные украшения'],
            ],
        ],
    ],
],
```

| Параметр | Описание |
|---|---|
| `parent` | Значение поля `parent_resolve` родителя. `null` — в корень. |
| `set` | Дополнительные атрибуты для записи (необязательно). |

---

## Как работает

Плагин не правит ядро, `modules/*` и `vendor/*`. Работает через:

- `App::bind()` — подмена `RecordImport` нашей версией (разрешает авто-ID)
- `EntryRecord::extend()` — хуки `model.beforeValidate` (slug), `model.beforeCreate` (nest), `model.afterSave` (fullslug)
- `resetTreeOrphans()` + `resetTreeNesting()` — штатный NestedTree OctoberCMS

---

**Автор:** [Denis Mishin](https://dmdev.ru) — [github.com/mifasu/fixtailorimport](https://github.com/mifasu/fixtailorimport)

