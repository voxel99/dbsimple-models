<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

/** Базовый класс вложения: наследники переопределяют поля, типы и computed */
abstract class Upload extends Model
{
    protected $table = 'perf_uploads';
    protected string $fields = 'id, user_id, type, path, name, mime, size, width, height, duration, data, hash, status, created_at';
    protected string $computed = 'url';
    protected $types = [
        'int' => 'id, user_id, size, width, height, duration, status',
        'json' => 'data',
        'datetime' => 'created_at',
    ];

    public function url(): string
    {
        return 'https://cdn.example.com/' . $this->path;
    }
}
