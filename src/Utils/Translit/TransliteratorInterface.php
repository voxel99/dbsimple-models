<?php

namespace Jam\Models\Utils\Translit;

/**
 * Транслитерация заголовка в фрагмент URL (используется SlugEvaluatable для слагов).
 *
 * Реализацию подменяют глобально через Strings::setTransliterator() или для отдельной
 * модели — переопределив SlugEvaluatable::slugTransliterator(). Слаги и slug_hash
 * уже сохранённых записей зависят от результата: меняйте реализацию осознанно.
 */
interface TransliteratorInterface
{
    /**
     * @param string $delimiter Чем заменяются символы, не попавшие в [a-z0-9]
     */
    public function translit(string $text, string $delimiter = '-'): string;
}
