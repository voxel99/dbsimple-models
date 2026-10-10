<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Perf;

use Jam\Models\Model;

class UploadImage extends Upload
{
    protected string $computed = 'url, ratio';

    public function ratio(): ?float
    {
        return $this->height ? round($this->width / $this->height, 3) : null;
    }
}
