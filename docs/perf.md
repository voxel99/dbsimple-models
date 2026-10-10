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

Первый замер (один запуск, PHP 8.4.26 CLI, opcache on, медиана из 9 прогонов; для сравнения с «после» см. A/B ниже):

```
     scenario                                                        median ms     min ms    peak KB
a    new Post($row) ×85, полная вложенность                             521.17     470.59      30543
b    ModelList::fromArray ×85                                           582.84     505.48      30544
c    ModelList::toArray(':dc', with: поля/exclude/pivot)                564.63     514.62      18979
c2   ModelList::toArray() по умолчанию                                  561.74     510.36      18307
d    100k __get/__set/__isset на одной модели                           274.69     250.78         23
e    Model::map($rows) ×85 (путь collection() без SQL)                  488.21     456.26      30560
```

## После

База (worktree на `main`) и ветка запускались поочерёдно, 6 раундов × 5 прогонов, чтобы дрейф
окружения (общая ВМ, разброс одиночного замера ±15%) одинаково влиял на обе стороны. В таблице —
медиана CPU-времени процесса (getrusage) по всем раундам и минимум wall-времени.

| | сценарий | CPU, мс (медиана) до → после | | wall min, мс до → после | | пик памяти, КБ |
|---|---|---:|---:|---:|---:|---:|
| a | `new Post($row)` ×85 | 481 → 228 | **×2.11** | 435 → 194 | ×2.25 | 30543 → 29620 |
| b | `ModelList::fromArray` ×85 | 495 → 221 | **×2.24** | 432 → 204 | ×2.11 | 30544 → 29621 |
| c | `toArray(':dc', with)` | 521 → 257 | **×2.03** | 474 → 211 | ×2.25 | 18979 → 18734 |
| c2 | `toArray()` по умолчанию | 570 → 251 | **×2.27** | 500 → 233 | ×2.15 | 18307 → 18068 |
| d | 100k `__get`/`__set`/`__isset` | 276 → 108 | **×2.55** | 244 → 92 | ×2.65 | 23 → 3 |
| e | `Model::map($rows)` | 532 → 260 | **×2.05** | 484 → 225 | ×2.15 | 30560 → 29636 |

**(a)+(c): CPU 1002 → 484 мс (×2.07), wall min 910 → 404 мс (×2.25).**

Профиль после (a+c, self, Excimer, период 0.5 мс):

```
== self (samples: 4928)
   676  13.7%  Traits\Internal\ConvertableHelperToArray::convertTraceFields
   676  13.7%  Model::__set
   411   8.3%  Model::findChangePropertyMethod
   348   7.1%  Traits\Internal\ConvertableHelperFromArray::convertProp
   255   5.2%  Model::__get
   150   3.0%  Cast\CastClosure::sharedForField
   144   2.9%  Traits\Internal\ConvertableHelperToArray::getTraceFields
   139   2.8%  Traits\Internal\ConvertableHelperToArray::processAfterConvert
   132   2.7%  {closure:/home/user/dbsimple-models/src/ModelList.php(148)}
   127   2.6%  ModelAbstract::__construct
   119   2.4%  Utils\CamelCase::to
   113   2.3%  Traits\Internal\ConvertableHelperToArray::processCast
   104   2.1%  Model::__isset
   103   2.1%  Model::getUpdatable
    95   1.9%  Traits\Internal\ConvertableHelperToArray::setFlags
    81   1.6%  Model::isRelationProp
```

## Что изменено и почему поведение то же

Общий принцип: всё, что зависит только от класса модели, вычисляется один раз на класс (или на вызов
`toArray()`), а горячие `__get`/`__set`/`fromArray`/`toArray` не делают лишних вызовов. Публичные методы,
которые модель может переопределить (`getFields`/`getComputed`/.../`getCast`, `getFlag`,
`getAllRelations`/`getRelationByAlias`/`isRelationProp`, `getRelationClasses`, `getRelationAliases`),
обходятся напрямую только если класс их **не** переопределяет — это проверяется через Reflection один
раз на класс; иначе используется прежний путь через метод.

1. **Разбор строк описаний** (`ArrayHelper::stringCommasToArray`): кеш по `[$delimiter][$data]` вместо
   склейки ключа на каждый вызов; `lazyFieldList` больше не разбирает заново пустое описание на каждое
   обращение (у большинства моделей нет computed/explicit/extra). Результаты те же: та же функция разбора,
   пустая строка давала `[]`.
2. **`__set`**: убран `getRelationClasses()` (замыкание на каждое отношение + `getPivotClass()`) на каждое
   присваивание — класс отношения определяется только для присваиваемого свойства тем же правилом
   (`@pivot` → pivot-класс, иначе `Relation::getClass($this)`), а карта строится только для текста
   исключения. Шаги `__set` записаны в теле метода (было ~35 вызовов приватных хелперов на присваивание),
   порядок проверок и вызовов публичных геттеров прежний. Индекс полей `[поле => true]` на класс вместо
   `in_array` по ~60 полям (только если все имена — нечисловые строки: тогда `isset` ≡ нестрогий `in_array`;
   список полей экземпляра после `cutFields`/`excludeFields` проверяется по идентичности массива).
   Правила JSON-ключей 1 уровня — один раз на описание кастов вместо перебора всех кастов.
   `forgetComputedValues()` без `array_diff`, когда кешированных computed нет.
3. **`__get`**: то же — шаги в теле метода, без лишних вызовов.
4. **Поиск отношения**: `getRelationByAlias()` без `array_merge` всех отношений; порядок как у
   `getAllRelations()` (belongs > hasOne > hasMany при совпадении алиасов). Числовые алиасы (их
   `array_merge` перенумеровывает) — прежним путём.
5. **Касты**: `CastClosure::sharedForField()` (@internal) — один экземпляр встроенного каста на поле
   класса вместо `new CastJson/CastFlags/...` на каждое чтение/запись/поле `toArray`. Встроенные касты
   не меняют состояние после создания. Запись проверяется по идентичности объекта-описания и классу в
   реестре `$factoryClasses`. Пользовательские (`closure`) касты создаются на каждый вызов, как раньше;
   `fromCast()` не изменён.
6. **`fromArray`**: скаляр в поле (не отношение) записывается сразу — это ровно то, что возвращали
   `makeNode(getPropClassData())`, без промежуточного объекта и 5 вызовов; `makeNode` создаёт узел через
   `new $class(...$args)` вместо `ReflectionClass::newInstanceArgs()` (несуществующий класс — прежним
   путём, с тем же исключением); `Code::isImplements` кеширует `class_implements()` по классу.
7. **`toArray`**: значения 13 флагов вычисляются один раз на вызов (через `getFlag()` модели, если он
   переопределён), а не через вызов `getFlag()` на каждое поле; списки computed/hidden/extra/updatable —
   один раз на вызов; булевы флаги для связанных моделей — один раз на хелпер; цикл по полям без двух
   вызовов методов на поле; в `processCast` свойство читается один раз вместо двух подряд.

### Допущения (явно)

- Списки описаний модели (computed/hidden/extra/updatable/explicit, отношения) снимаются один раз на вызов
  `toArray()`, а не перечитываются на каждом поле. Отличие возможно, только если computed-метод во время
  сериализации меняет описания самой модели.
- Объекты-описания кастов из `getCast()` считаются неизменяемыми (так и задумано: строятся из `$types`
  один раз на класс). Изменение их полей «на месте» после первого использования кеш не увидит.
- В `processCast` значение поля читается через `__get` один раз вместо двух; отличие возможно только при
  переопределённом `__get` с побочными эффектами.

### Тесты

- `tests/Unit/PerfGoldenTest.php` — снимок `toArray()`/`toObject()`/`map()`/`serialize`/`__debugInfo` и
  чтений/записей свойств на фикстурах бенчмарка (`golden.json.gz`, снят на `main` до оптимизаций)
  совпадает байт в байт.
- `tests/Unit/DescriptorCachingTest.php` — наследование описаний, описания конкретного экземпляра
  (cutFields/excludeFields, computed из конструктора), полиморфные отношения, pivot, порядок алиасов,
  касты и реестр, change*/changeThis и guard, `with()` на экземпляре, переопределённые
  `getFlag`/`getAllRelations`/`getRelationClasses`, JSON-ключи по классам. Все поведенческие тесты
  проходят и на `main`.

## Что осталось и куда копать

- **`Model::findChangePropertyMethod`** + `CamelCase::to` + `__getClassMethods` — ~16% (a+c) и около
  пятой части гидрации: линейный `in_array` по ~220 методам и сборка имени на каждое присваивание.
  Под это заведена отдельная задача (кеш по `[class][prop]`), в этом PR метод не трогался.
- Постоянные расходы на каждую вложенную модель в `toArray()` (хелпер, флаги, `array_unique` полей
  трассировки, `processCast`) — ~8 тыс. моделей на прогон, из них ~6.8 тыс. миниатюр.
- Пара `__isset` + `__get` на каждое поле в `toArray()` — нужен внутренний способ прочитать значение
  за один вызов.
- Предложения, меняющие поведение (не сделаны): ленивое создание pivot-модели и её `ModelList` в
  `Relation::setPivot()` (сейчас на каждый экземпляр модели с pivot-отношением; изменится момент вызова
  конструктора pivot-модели); регистрация boot-обработчиков (`bootWithable`, `bootTimestamps`, ...) на
  класс, а не замыканиями на каждый экземпляр; описание отношений на уровне класса вместо `hasMany()`
  в конструкторе (25 объектов `Relation` на каждый `Post`).
- Не на горячем пути, но строят карту замыканий по всем отношениям: `Model::clearExcessFields()` и
  `getRelationModelByAlias()`.
