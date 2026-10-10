<?php

namespace Jam\Models\Utils\Translit;

use Closure;

/**
 * Транслитерация функцией с сигнатурой fn(string $text, string $delimiter): string,
 * например глобальной translit() приложения: new CallableTransliterator('translit').
 */
final class CallableTransliterator implements TransliteratorInterface
{
    private Closure $callback;

    public function __construct(callable $callback)
    {
        $this->callback = Closure::fromCallable($callback);
    }

    public function translit(string $text, string $delimiter = '-'): string
    {
        return (string) ($this->callback)($text, $delimiter);
    }
}
