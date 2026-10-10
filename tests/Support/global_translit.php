<?php

// Глобальная translit() приложения (подключается только в отдельном процессе теста)

use Jam\Models\Tests\Support\LegacyTranslit;

function translit($str, $dummySymbol = '', $exceptSymbols = '', $protectedSymbols = [])
{
    LegacyTranslit::$calls++;
    return LegacyTranslit::translit($str, $dummySymbol, $exceptSymbols, $protectedSymbols);
}
