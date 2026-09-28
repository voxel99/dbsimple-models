# Jam DbSimple Models

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://php.net/)
[![License: LGPL 2.1](https://img.shields.io/badge/License-LGPL_2.1-blue.svg)](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html)

A lightweight, high-performance Active Record and Model persistence layer for PHP 8.4+, built specifically on top of [`jam/dbsimple`](https://github.com/voxel99/dbsimple).

---

## Features

- **Active Record Pattern**:
  - Expressive CRUD operations: `first()`, `all()`, `collection()`, `value()`, `column()`, `save()`, `update()`, `delete()`.
  - Fluent Query Builder methods (`where()`, `join()`, `leftJoin()`, `limit()`, `offset()`, `groupBy()`, `orderBy()`) with DbSimple placeholders (`?`, `?d`, `?a`, `?#`, `?s`) and optional `{...}` blocks.
- **Rich Relations (explicit eager loading, no N+1)**:
  - `hasOne`, `hasMany`, `belongs`, many-to-many (`hasMany` through a pivot model), polymorphic and in-code (`DummyModel`) relations.
  - Multi-level relation resolution with custom field projections and conditions (`with('items(id,title):c')`).
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

## Documentation by example

The [`examples/`](examples) directory contains runnable scripts (in-memory SQLite, nothing to set up)
covering model definition, CRUD, the query builder, casting, relations, eager loading syntax,
saving object graphs, API output flags, events / timestamps / soft deletes and master-replica setups,
plus a list of best practices and pitfalls: see [`examples/README.md`](examples/README.md).

```bash
php examples/06-eager-loading.php
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
