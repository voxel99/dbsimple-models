# Производительность гидрации и сериализации

Бенчмарк: `composer bench` (`php -d opcache.enable_cli=1 bench/hydrate.php [--runs=9] [--only=a,c]`).
Профиль: `php bench/profile.php --only=a,c` (нужен `ext-excimer`, только для разработки).

Фикстуры — `tests/Fixtures/Perf`: Post (~60 полей, 25 отношений, одно с pivot, полиморфные вложения,
computed/explicit/extra/hidden/guarded, касты json/int/float/date/flags/bool/delim, ~220 методов),
User → avatar (Image) → 15 Thumb, Tag (через PostTag), Selection → cover, Attach → UploadImage/UploadVideo
с крупным json, Preview → Image → Thumb, Comment → User. 85 постов.

Сценарии:

| | |
|---|---|
| a | `new Post($row)` ×85 с полной вложенностью |
| b | `new ModelList(Post::class, $rows)` (ModelList::fromArray) |
| c | `$list->toArray(':dc', null, $with)` — with-выражение с полями, exclude, pivot, флагами |
| c2 | `$list->toArray()` по умолчанию |
| d | 100k `__get`/`__set`/`__isset` на одной модели |
| e | `(new Post)->map($rows)` — путь `collection()` без SQL |

## База (до оптимизаций)

PHP 8.4.26 CLI, opcache on, медиана из 9 прогонов:

```
     scenario                                                        median ms     min ms    peak KB
a    new Post($row) ×85, полная вложенность                             521.17     470.59      30543
b    ModelList::fromArray ×85                                           582.84     505.48      30544
c    ModelList::toArray(':dc', with: поля/exclude/pivot)                564.63     514.62      18979
c2   ModelList::toArray() по умолчанию                                  561.74     510.36      18307
d    100k __get/__set/__isset на одной модели                           274.69     250.78         23
e    Model::map($rows) ×85 (путь collection() без SQL)                  488.21     456.26      30560
```
