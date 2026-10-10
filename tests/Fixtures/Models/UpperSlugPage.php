<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Fixtures\Models;

use Jam\Models\Utils\Translit\CallableTransliterator;
use Jam\Models\Utils\Translit\TransliteratorInterface;

/**
 * Своя транслитерация слагов у отдельной модели.
 */
class UpperSlugPage extends SlugPage
{
    protected static function slugTransliterator(): TransliteratorInterface
    {
        return new CallableTransliterator(
            static fn(string $text, string $delimiter) => str_replace(' ', $delimiter, 'page ' . strtolower($text))
        );
    }
}
