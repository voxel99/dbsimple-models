# Changelog

## Unreleased

### Breaking changes (upgrading from 1dc70de and earlier)

These affect application models that extend `Jam\Models\Model`; fix them before
updating.

- **PHP >= 8.4** is required (was `>= 8.1`).
- **`Model::$types` is now declared in `Model`, without a type**:
  `protected $types = [];`. PHP forbids a child class from adding a type to an
  inherited untyped property, so a model with

  ```php
  protected array $types = ['int' => 'id'];   // Fatal error: Type of App\Post::$types must not be defined (as in class Jam\Models\Model)
  ```

  fails at class load. Declare it without a type:

  ```php
  protected $types = ['int' => 'id'];
  ```

- **`where()` is typed** as `where(string|array|Criteria|null $condition): static`
  (was `where(string|null $condition)`). Array conditions (`['id' => 5]`,
  `['id' => [1, 2]]`, `['deleted_at' => null]`) and `Criteria` objects are
  accepted. A model that overrides `where()` must accept at least these types
  (parameter types may only be widened in a child class), e.g.

  ```php
  public function where(string|array|Criteria|null $condition): static
  ```

### Added

- Replaceable transliteration for slugs: `Jam\Models\Utils\Translit\TransliteratorInterface`
  (`translit(string $text, string $delimiter = '-'): string`) with
  `BuiltinTransliterator` (`Strings::translit()`) and `CallableTransliterator`
  (any `fn(string $text, string $delimiter): string`).
  - globally: `Strings::setTransliterator(new MyTransliterator())`
    (`null` restores the default);
  - per model using `SlugEvaluatable`: override
    `protected static function slugTransliterator(): TransliteratorInterface`.

  Default resolution (`Strings::transliterator()`): the one set via
  `setTransliterator()`, else the application's global `translit()` function if it
  exists, else `Strings::translit()`.

### Behaviour changes

- `Model::delete()` on a model without a primary-key value is a no-op: it returns
  `0` without a query and without `deleting`/`deleted` events (previously it ran
  `DELETE ... WHERE id = NULL`; on main after 1dc70de it threw a `TypeError`).
  To delete by condition use `deleteWhere(...)` or `->where(...)->collection()->delete()`.

### Fixed

- A relation requested via `with()` that has no related rows is left unset again,
  as in 1dc70de: `$model->rel === null`, `empty($model->rel)` is `true`,
  `isset($model->rel)` is `false` (on main after 1dc70de an empty hasMany/pivot
  relation became an empty `ModelList`, which inverted every
  `empty()`/`isset()`/`if ($model->rel)` check). The "loaded but empty" state is
  tracked separately and read by `isRelationLoaded()`; it is also set by manual
  assignment (`$model->rel = ...`, including `null`) and survives `clone` and
  `serialize()`/`unserialize()` (nested models included). `toArray()` output is
  unchanged.
- A field declared in both `$computed` and `$extra` (an assigned value takes
  priority over the computed one) lost its assigned value on any later
  assignment, `save()` or `EVENT_CREATED` handler. Only values actually computed
  by the field method are now dropped when model data changes.
- `SlugEvaluatable::translit()` uses the application's global `translit()`
  function again when it exists, so slugs and `slug_hash` match the stored ones.
  Without it, `Strings::translit()` is used; its table now matches that function
  (`ё` → `yo`, `й` → `j`, `ц` → `c`, `щ` → `shch`, `э` → `eh`, quotes and dots
  removed, `ñ ç à á â ã ä å è é ê ë ì í î ï ò ó ô õ ö ù ú û ü` → ASCII), so
  `«niño español»` gives `nino-espanol` (was `ni-o-espa-ol`) and the slugs are
  the same with or without the global function. Characters outside the table
  (`ß`, `ø`, `ł`, ...) are still replaced with the delimiter, as before.
- An empty insert id returned by the storage (`0`, `'0'`, `''`, `false`, `null`:
  `INSERT IGNORE` that inserted nothing, a cancelled `creating` event) is no
  longer assigned to the model's primary key, so the next `save()` inserts
  again instead of running `UPDATE ... WHERE id = 0`. Empty keys are not
  propagated to related models (belongs foreign key, has-many/has-one foreign
  keys, pivot rows) when saving relations either.
