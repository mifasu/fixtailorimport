# Dmdev.FixTailorImport

An [OctoberCMS](https://octobercms.com) 4.x plugin that fixes common issues when importing Tailor entries from JSON/CSV:
auto-assigns IDs, generates readable slugs from title, corrects nested-tree positioning (`nest_left`, `nest_right`, `nest_depth`), and rebuilds `fullslug` for structure sections.

**What it fixes:**
- Slug is generated from `title` instead of the technical `slug-abc123…` format
- Conflict suffix uses random digits instead of `-2, -3` sequence
- CSV rows without an `id` column are created (not skipped)
- `nest_left`, `nest_right`, `nest_depth` are populated when a structure-section entry is created via import
- `fullslug` is rebuilt automatically after every save
- `update_existing` in Tailor import is preserved after blueprint extension, so updating existing records by `id` works again

---

## Changelog

### v1.0.1

- Fixed the Tailor import bug where `update_existing` was silently ignored after `extendWithBlueprint()`
- Restored updating existing records by `id`
- Updated release notes for OctoberCMS publication

---

## Installation

```bash
# Copy the folder to plugins/dmdev/fixtailorimport/
php artisan october:migrate
```

Or install directly from a remote source:

```bash
php artisan plugin:install Dmdev.FixTailorImport --from=https://github.com/mifasu/fixtailorimport-plugin.git
```

---

## Artisan Commands

### `tailor:fix-nulls` — repair already-imported records

If data was loaded **before** the plugin was installed, the table may contain empty slugs, zero nest fields, or blank fullslugs. This command fixes everything in a single pass:

```bash
php artisan tailor:fix-nulls "Catalog\Category" --dry-run
php artisan tailor:fix-nulls "Catalog\Category"
```

What it does:
1. Fixes empty/technical slugs — generates them from `title`
2. For structure sections: rebuilds `nest_left / nest_right / nest_depth`
3. For structure sections: rebuilds `fullslug` across the entire tree

---

### `tailor:fix-structure` — apply category rules and rebuild the tree

```bash
php artisan tailor:fix-structure "Catalog\Category" --dry-run
php artisan tailor:fix-structure "Catalog\Category"
php artisan tailor:fix-structure "Catalog\Category" --rebuild-only
```

`category → parent_id` rules are defined in the config file (see below).  
`--rebuild-only` — rebuild the tree only, without applying category rules.

---

### `tailor:fix-slugs` — retroactively fix technical slugs

```bash
php artisan tailor:fix-slugs --dry-run
php artisan tailor:fix-slugs "Catalog\Category"
php artisan tailor:fix-slugs
```

---

## Configuration (for `fix-structure`)

File: `plugins/dmdev/fixtailorimport/config/fixtailorimport.php`

```php
'sections' => [
    'Catalog\Category' => [
        'structure' => [
            'category_field' => 'category', // entry field containing the category name from CSV
            'parent_resolve' => 'title',    // find the parent entry by this field
            'rules' => [
                'Jewellery'  => ['parent' => null],        // top-level (no parent)
                'Rings'      => ['parent' => 'Jewellery'],
                'Earrings'   => ['parent' => 'Jewellery'],
            ],
        ],
    ],
],
```

| Parameter | Description |
|---|---|
| `category_field` | The model attribute that holds the category value from CSV. |
| `parent_resolve` | Field used to look up the parent entry (`slug` or `title`). |
| `parent` | Value of `parent_resolve` for the desired parent. `null` — places the entry at root level. |
| `set` | Optional. Extra attributes to set on the entry. |

---

## How It Works

The plugin never modifies core, `modules/*`, or `vendor/*`. It works through:

- `App::bind()` — replaces `RecordImport` with our version via the IoC container, allowing auto-ID assignment.
- `EntryRecord::extend()` — attaches event hooks:
  - `model.beforeValidate` — slug fix (runs before OctoberCMS assigns a technical slug)
  - `model.beforeCreate` — nest fields fix for structure sections
  - `model.afterSave` — fullslug rebuild
- `resetTreeOrphans()` + `resetTreeNesting()` — OctoberCMS built-in NestedTree methods.

---

## Publishing to OctoberCMS

Use the version defined in `updates/version.yaml` when publishing or updating the plugin.
After code and release-note changes, run migrations:

```bash
php artisan october:migrate
```

---

## Requirements

- OctoberCMS 4.x (Laravel 12, PHP 8.2+)
- Tailor module (included in OctoberCMS 4.x)

---

## License

MIT © [dmdev.ru](https://dmdev.ru)
