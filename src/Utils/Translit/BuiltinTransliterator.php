<?php

namespace Jam\Models\Utils\Translit;

use Jam\Models\Utils\Strings;

/**
 * Встроенная транслитерация: Strings::translit().
 */
final class BuiltinTransliterator implements TransliteratorInterface
{
    public function translit(string $text, string $delimiter = '-'): string
    {
        return Strings::translit($text, $delimiter);
    }
}
