# Jam DbSimple Models

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://php.net/)
[![License: LGPL 2.1](https://img.shields.io/badge/License-LGPL_2.1-blue.svg)](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html)

A lightweight, high-performance Active Record and Model persistence layer for PHP 8.4+, built specifically on top of [`jam/dbsimple`](https://github.com/voxel99/dbsimple).

---

## Why

Most ORMs load relations imperatively: you call something, it fetches something, maybe lazily, maybe N+1.
Here the data graph is **described**, not coded. `with()` takes a compact expression that says which
relations, which columns, under which conditions, in which order and in which output shape you need —
and the ORM turns every relation into exactly one `WHERE fk IN (...)` query for the whole result set.

```php
Post::instance()
    ->with('author(id, name):c, tags!:o(name), comments[is_approved = 1]:o(id).author(id, name)')
    ->all();
```

Because the description is just a string (or an array), it composes. A model can publish a **with-profile** —
a function that builds the expression from parameters — and every caller decides, at the call site,
*who* gets *what* data and *when*: a list page, a detail page, a logged-in viewer, a nested context.
See [With-profiles](#with-profiles-describe-the-data-let-the-caller-choose) below.

---

## Features

- **Declarative eager loading (no lazy loading, no N+1)**:
  - One expression describes the whole graph: `alias[condition](fields)^exclude^?!:flags.nested`.
  - Per-relation SQL conditions with references to the parent row (`[user_id <> this(user_id)]`), column projection, ordering, limits, `keyBy`, pivot rows, soft-deleted rows.
  - Output shape is part of the description: computed fields (`:c`), excluded fields (`^...^`), `{}`/`[]` instead of `null` (`!`).
  - Expressions merge by path and are plain strings, so they compose into reusable, parameterized **with-profiles**.
- **Active Record Pattern**:
  - Expressive CRUD operations: `first()`, `all()`, `collection()`, `value()`, `column()`, `save()`, `update()`, `delete()`.
  - Fluent Query Builder methods (`where()`, `join()`, `leftJoin()`, `limit()`, `offset()`, `groupBy()`, `orderBy()`) with DbSimple placeholders (`?`, `?d`, `?a`, `?#`, `?s`) and optional `{...}` blocks.
- **Rich Relations**:
  - `hasOne`, `hasMany`, `belongs`, many-to-many (`hasMany` through a pivot model), polymorphic and in-code (`DummyModel`) relations.
- **Data Casting & Transformations**:
  - Typed fields (`bool`, `int`, `uint`, `float`, `date`, `datetime`, `json`, `delim`, `flags`, `ip`).
  - Automatic JSON sub-property mapping and bi-directional casting.
- **Computed & Dynamic Properties**:
  - Declarative `$computed` properties with lazy evaluation.
  - Flag-based array/object conversions via `toArray(':c')` and `toObject()`.
- **Master / Replica (Slave) Support**:
  - Multiple named connections; `->db(Model::DB_SLAVE)` switches a query (and its eager-loaded relations) to a replica.
- **Model Events Lifecycle**:
  - Hooks for `retrieved`, `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`.

---

## Requirements

- PHP 8.4+
- [`jam/dbsimple`](https://github.com/voxel99/dbsimple) (MySQL via PDO is the primary target; the generated SQL uses the MySQL dialect)

---

## Installation

```bash
composer require jam/dbsimple-models
```

---

## Quick Setup

### 1. Initialize Database Connections

Register your `Jam\DbSimple\DatabaseInterface` instances with `Model::initDbSimple()`:

```php
use Jam\DbSimple\Connect;
use Jam\Models\Model;

$master = new Connect('mypdo://user:pass@127.0.0.1/app_db?enc=utf8mb4');

Model::initDbSimple([
    Model::DB_MASTER => $master,
]);
```

### 2. Define a Model

```php
use Jam\Models\Model;
use Jam\Models\Traits\Timestamps;

class User extends Model
{
    use Timestamps;

    protected string $fields = 'id, email, first_name, last_name, is_active, tags, created_at, updated_at';
    protected string $computed = 'full_name';
    protected $types = [
        'bool' => 'is_active',
        'delim' => 'tags',
    ];

    public function full_name(): string
    {
        return trim($this->first_name . ' ' . $this->last_name);
    }
}
```

### 3. Usage Examples

#### Finding & Querying

Queries start from a builder instance: `Model::instance()`.

```php
// Find by primary key (first() returns an empty model, not null, when nothing is found)
$user = User::instance()->id(42)->first();
if ($user->isEmpty()) { /* 404 */ }

// Query with conditions
$activeUsers = User::instance()
    ->where('is_active = ?d', 1)
    ->where('email LIKE ?', '%@example.com')
    ->orderBy('created_at DESC')
    ->limit(10)
    ->collection();

// Eager load relations (one query per relation, no N+1) and export with computed fields
$users = User::instance()->with('posts(id, title):o(id DESC), profile')->all();
$usersArray = $users->toArray(':cd');
```

#### Creating & Updating

```php
$user = new User([
    'email' => 'alex@example.com',
    'first_name' => 'Alex',
    'last_name' => 'Voxel',
    'is_active' => true,
    'tags' => ['developer', 'php'],
]);
$user->save();

// Update (save() writes all loaded fields; update() — only the listed ones)
$user->first_name = 'Alexander';
$user->update('first_name');
```

---

## Storage backends

A model delegates reading and writing rows to a storage (`Jam\Models\Storage\StorageInterface`:
`select`, `aggregate`, `insert`, `update`, `increment`, `delete`). Fields, casting, relations,
events and `toArray()` stay in the model.

| Storage | Used by | Conditions |
|---------|---------|------------|
| `SqlStorage` | every model by default (DbSimple: MySQL, PostgreSQL, SQLite) | array conditions and SQL fragments |
| `MemoryStorage` | `DummyModel` (read-only lists defined in code) | array conditions, simple SQL (`=`, `IN`) |
| your own | a model overriding `createStorage()` | array conditions |

Array conditions are storage-independent:

```php
Post::instance()->where([
    'user_id' => [1, 2],                      // IN
    'views' => ['>=' => 10],
    'deleted_at' => null,                     // IS NULL
    Criteria::OR => [['pinned' => 1], ['title' => ['like' => 'PHP%']]],
])->all();
```

Eager loading only needs "rows where key IN (...)" from each storage, so relations work across
storages (a SQL model can `hasMany` models kept elsewhere and vice versa) — see
[`examples/11-custom-storage.php`](examples/11-custom-storage.php) and the reference in-memory
implementation in [`tests/Support/ArrayStorage.php`](tests/Support/ArrayStorage.php).
SQL fragments, joins, `GROUP BY` and locks remain SQL-only.
## With-profiles: describe the data, let the caller choose

`with()` is a description of a data graph, so the natural unit of reuse is a function that **builds**
that description. Put it on the model: the model knows what can be loaded around it, the caller knows
what is needed right now.

```php
class Post extends BaseModel
{
    public static function commonWithString(
        string $name = '',          // attach the profile to a path: '', 'posts:o(id)', 'subject', ...
        int $viewerId = 0,          // who is looking — conditions may depend on it
        bool $comments = false,     // detail page only
        bool $commentsDeep = false,
        bool $full = false,
    ): string {
        // Always: the author (only needed columns + computed) and tags in a stable order
        $with = [
            'author(id, name, email, role_id):c',
            'author.role(id, code)',
            'tags!:o(name)',                 // ! — [] instead of null in toArray()
        ];

        if ($comments) {
            // Guests see approved comments, a signed-in user also sees their own pending ones
            $with[] = $viewerId
                ? sprintf('comments[is_approved = 1 OR user_id = %d]!:o(id)', $viewerId)
                : 'comments[is_approved = 1]!:o(id)';
            if ($commentsDeep) {
                // The same path again: nodes are merged, condition and flags are declared once above
                $with[] = 'comments.author(id, name)';
                $with[] = 'comments.author.profile(id, user_id, bio)';
            }
        }

        if ($full) {
            $with[] = 'category(id, parent_id, title).parent(id, title)';
            $with[] = 'author.profile!';
        }

        // Prefix every path with $name: 'author' -> 'posts:o(id).author', '[x]' -> 'posts[x]'
        return implode(', ', array_unique(ArrayHelper::extendsWithName($with, $name)));
    }
}
```

The call site reads like a specification — named arguments tell exactly what is fetched and for whom:

```php
// List page
Post::published()->with(Post::commonWithString())->all();

// Post page for the current user
Post::instance()
    ->with(Post::commonWithString(viewerId: $me->id, comments: true, commentsDeep: true, full: true))
    ->id($id)
    ->first();
```

Profiles nest. The same post profile is attached under another model's relation by passing a path:

```php
class User extends BaseModel
{
    public static function commonWithString(string $name = '', int $viewerId = 0, bool $posts = false): string
    {
        $with = ArrayHelper::extendsWithName(['profile!', 'role(id, code, title)'], $name);
        if ($posts) {
            // Pass the FULL path into the nested profile: extendsWithName() applied to an already
            // joined string would prefix only its first element
            [$postsPath] = ArrayHelper::extendsWithName(['posts[status = 1]:o(id DESC)'], $name);
            $with[] = Post::commonWithString($postsPath, $viewerId);
        }
        return implode(', ', array_unique($with));
    }
}

User::instance()->with(User::commonWithString(posts: true))->id(1)->first();
Comment::instance()->with(User::commonWithString('author', posts: true))->all();   // two levels down
```

What this gives you:

- **One place per model** describes what can be loaded around it; pages don't copy-paste `with` strings.
- **Control at the call site**: parameters switch whole branches of the graph on and off, so a list page
  never pays for detail-page data.
- **Viewer-dependent data** is just a condition in the description (`rates[user_id = %d]`,
  `comments[is_approved = 1 OR user_id = %d]`), still one query per relation for the whole page.
- **Stable API shape**: `!` gives `{}`/`[]` instead of `null`, `(fields)` and `^exclude^` limit what leaks out.
- **Polymorphic and heterogeneous paths**: `?` marks a relation that only some classes have
  (`subject.tags?` when `subject` is a `Post` or a `Comment`) — no exception for the others.
- **Readable**: the profile is a plain string — dump it, log it, diff it.

Runnable version: [`examples/11-with-profiles.php`](examples/11-with-profiles.php);
the full `with()` syntax: [`examples/06-eager-loading.php`](examples/06-eager-loading.php)
and the cheat sheet in [`examples/README.md`](examples/README.md#with).

### `with()` syntax at a glance

```
alias[condition](fields)^exclude^?!:flags.nested, other
```

| Part | Example | Meaning |
|------|---------|---------|
| `[...]` | `comments[is_approved = 1]` | SQL condition for the related table; `this(field)` — a field of the parent row |
| `(...)` | `author(id, name)` | columns to select (relation keys are added automatically) |
| `^...^` | `author^email^` | exclude fields from `toArray()` |
| `?` | `subject.tags?` | unknown alias is ignored instead of throwing |
| `!` | `profile!` | `{}` / `[]` in `toArray()` instead of a missing relation |
| `:o(...)` | `:o(views DESC\|id)` | ORDER BY (a comma inside a flag argument is written as `\|`) |
| `:l(N)`, `:f(N)` | `:l(10)` | LIMIT / OFFSET of the relation query (for the whole query, not per parent) |
| `:k(field)` | `:k(type)` | key the relation list by a field in `toArray()` |
| `:c` | `author:c` | computed fields of the relation |
| `:p` | `tags:p` | pivot rows in `@pivot` |
| `:r` | `posts:r` | include soft-deleted rows |

A path may be mentioned many times — `'author(id, name)', 'author.role', 'author.profile'` — the nodes
are merged (fields, conditions and flags are combined).

---

## Documentation by example

The [`examples/`](examples) directory contains runnable scripts (in-memory SQLite, nothing to set up)
covering model definition, CRUD, the query builder, casting, relations, eager loading syntax,
with-profiles, saving object graphs, API output flags, events / timestamps / soft deletes and master-replica setups,
plus a list of best practices and pitfalls: see [`examples/README.md`](examples/README.md).

```bash
php examples/06-eager-loading.php
php examples/11-with-profiles.php
```

## Tests

```bash
composer install
vendor/bin/phpunit                         # unit + integration tests on in-memory SQLite

# the same integration suite (plus MySQL-only features) on MySQL / MariaDB
DBSIMPLE_TEST_MYSQL=mysql://user:pass@127.0.0.1/dbsimple_test vendor/bin/phpunit
```

`jam/dbsimple` is resolved from a sibling `../dbsimple` checkout (see `repositories` in `composer.json`).

---

## License

This library is licensed under the [GNU Lesser General Public License v2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html).
