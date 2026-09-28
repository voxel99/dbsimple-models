<?php

namespace Jam\Models\Traits;

use Jam\Models\Utils\Strings;

trait SlugEvaluatable
{
    protected string $columnTitle = 'title';
    protected string $columnSlug = 'slug';
    protected string $columnSlugIndex = 'slug_index';
    protected string $columnSlugHash = 'slug_hash';
    protected array $slugDeny = [];
    protected bool $resolveSlugConflicts = false;

    public function bootSlugEvaluatable()
    {
        $this->on(self::EVENT_CREATING, function (array $ins) {
            return $this->onSave($ins);
        });

        $this->on(self::EVENT_UPDATING, function ($id, array $upd) {
            return $this->onSave($upd);
        });
    }

    public function onSave(array $data): array
    {
        if (isset($data[$this->columnTitle]) || isset($data[$this->columnSlug])) {
            $slugData = $this->evaluateSlugData();
            if ($slugData->slug) {
                foreach ((array)$slugData as $k => $v) {
                    $data[$k] = $v;
                }
            }
        }
        return $data;
    }

    // Правило преобразования заголовка в транслит
    public static function translit($title): string
    {
        $translit = Strings::translit((string) $title, "-");
        $translit = preg_replace('#[-]{2,}#', '-', $translit);
        $translit = trim($translit, "-");
        if ($translit && !preg_match('#[^\d]+#', $translit)) {
            $translit = 'p-' . $translit;
        }
        return $translit;
    }

    // Slug = транслит + число (2,3 и т.д.)
    public static function slug(string $translit, int $countSlugHashes = 0): string
    {
        return $translit . ($countSlugHashes > 0 ? "-" . ($countSlugHashes + 1) : '');
    }

    // Хэш для проверки уникальности
    public static function computeSlugHash(string $translit)
    {
        return md5($translit);
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- matches DB column name via $computed
    public function slug_canonical($slugDeny = [])
    {
        if ($this->{$this->columnSlug}) {
            $translit = $this->{$this->columnSlug};
            /*
            if ($this->{$this->columnSlugIndex}) {
                $slugIndex = (string)($this->{$this->columnSlugIndex} + 1);
                $lengthIndex = strlen($slugIndex) + 1;
                if (substr($this->slug, -$lengthIndex) === "-" . $slugIndex) {
                    $translit = substr($this->{$this->columnSlug}, 0, -$lengthIndex);
                }
            }
            */
        } else {
            $translit = static::translit($this->{$this->columnTitle});
        }

        if (preg_match('#(.*)-\d+$#', $translit, $m) && !empty($m[1])) {
            $translit = $m[1];
        }

        if ($this->slugDeny && in_array($translit, $this->slugDeny)) {
            $translit = "-" . $translit;
        }
        return $translit;
    }

    // phpcs:ignore PSR1.Methods.CamelCapsMethodName.NotCamelCaps -- matches DB column name via $computed
    public function slug_hash()
    {
        return static::computeSlugHash($this->slug_canonical());
    }

    public function resolveSlugConflicts(bool $resolve = true): static
    {
        $this->resolveSlugConflicts = $resolve;
        return $this;
    }

    private function nextSlugIndex(string $hash, string $translit): int
    {
        if ($hash === '') {
            return 0;
        }

        $sql = sprintf(
            '
            SELECT %s
            FROM %s
            WHERE %s = ? {AND %s <> ?d}
            ORDER BY %s ASC',
            $this->columnSlugIndex,
            '?_' . $this->table(),
            $this->columnSlugHash,
            $this->pk(),
            $this->columnSlugIndex
        );

        $usedIndexes = $this->db->selectCol(
            $sql,
            $hash,
            !empty($this->{$this->pk()}) ? $this->{$this->pk()} : DBSIMPLE_SKIP
        ) ?: [];

        $nextIndex = 0;
        foreach ($usedIndexes as $usedIndex) {
            $usedIndex = (int) $usedIndex;
            if ($usedIndex < $nextIndex) {
                continue;
            }
            if ($usedIndex > $nextIndex) {
                break;
            }
            $nextIndex++;
        }

        if ($this->resolveSlugConflicts) {
            $usedIndexes = array_map('intval', $usedIndexes);
            while (
                in_array($nextIndex, $usedIndexes, true) ||
                $this->slugExists(static::slug($translit, $nextIndex))
            ) {
                $nextIndex++;
            }
        }

        return $nextIndex;
    }

    private function slugExists(string $slug): bool
    {
        $sql = sprintf(
            'SELECT %s FROM %s WHERE %s = ? {AND %s <> ?d} LIMIT 1',
            $this->pk(),
            '?_' . $this->table(),
            $this->columnSlug,
            $this->pk()
        );

        return (bool) $this->db->selectCell(
            $sql,
            $slug,
            !empty($this->{$this->pk()}) ? $this->{$this->pk()} : DBSIMPLE_SKIP
        );
    }

    public function evaluateSlugData()
    {
        $translit = $this->slug_canonical();
        $hash = self::computeSlugHash($translit);
        $slugIndex = $this->nextSlugIndex($hash, $translit);

        return (object)[
            $this->columnSlugHash => $hash,
            $this->columnSlugIndex => (int) $slugIndex,
            $this->columnSlug => static::slug($translit, $slugIndex)
        ];
    }
}
