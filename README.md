# Jam DbSimple Models

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.4-8892BF.svg)](https://php.net/)
[![License: LGPL 2.1](https://img.shields.io/badge/License-LGPL_2.1-blue.svg)](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html)

A lightweight, high-performance Active Record and Model persistence layer for PHP 8.4+, built specifically on top of [`jam/dbsimple`](https://github.com/voxel99/dbsimple).

---

## Features

- **Active Record Pattern**:
  - Expressive CRUD operations: `first()`, `all()`, `find()`, `save()`, `delete()`, `insert()`.
  - Fluent Query Builder methods (`where()`, `whereIn()`, `join()`, `limit()`, `offset()`, `groupBy()`, `orderBy()`).
- **Rich Relations (Eager & Lazy loading)**:
  - `hasOne`, `hasMany`, `belongsTo`, `manyToMany` relations.
  - Multi-level relation resolution with custom field projections and conditions (`with('items(id,title):c')`).
- **Data Casting & Transformations**:
  - Typed fields (`bool`, `int`, `uint`, `float`, `date`, `datetime`, `json`, `delim`, `flags`, `ip`).
  - Automatic JSON sub-property mapping and bi-directional casting.
- **Computed & Dynamic Properties**:
  - Declarative `$computed` properties with lazy evaluation.
  - Flag-based array/object conversions via `toArray(':c')` and `toObject()`.
- **Master / Replica (Slave) Support**:
  - First-class support for multiple database connections and seamless read/write splitting.
- **Model Events Lifecycle**:
  - Hooks for `retrieved`, `creating`, `created`, `updating`, `updated`, `deleting`, `deleted`.

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

```php
// Find by primary key
$user = User::find(42);

// Query with conditions
$activeUsers = User::where('is_active = ?d', 1)
    ->orderBy('created_at DESC')
    ->limit(10)
    ->collection();

// Eager load relations with flags (:c for computed)
$usersArray = $activeUsers->toArray(':c');
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

// Update
$user->first_name = 'Alexander';
$user->save();
```

---

## License

This library is licensed under the [GNU Lesser General Public License v2.1](https://www.gnu.org/licenses/old-licenses/lgpl-2.1.html).
