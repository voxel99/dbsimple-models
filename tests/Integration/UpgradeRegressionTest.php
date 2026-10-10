<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\Storage\Criteria;
use Jam\Models\Tests\Fixtures\Models\AuthorNote;
use Jam\Models\Tests\Fixtures\Models\Document;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\SlugPage;
use Jam\Models\Tests\Fixtures\Models\Tag;
use Jam\Models\Tests\Fixtures\Models\TranslatedAttach;
use Jam\Models\Tests\Fixtures\Models\ZeroIdAuthor;
use Jam\Models\Tests\Support\ArrayStorage;
use Jam\Models\Tests\Support\DatabaseTestCase;
use Jam\Models\Tests\Fixtures\Models\UpperSlugPage;
use Jam\Models\Tests\Support\LegacyTranslit;
use Jam\Models\Utils\Strings;
use Jam\Models\Utils\Translit\BuiltinTransliterator;
use Jam\Models\Utils\Translit\CallableTransliterator;
use Jam\Models\Utils\Translit\TransliteratorInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Регрессии, найденные при обновлении приложения со старой версии (1dc70de).
 */
final class UpgradeRegressionTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ArrayStorage::reset();
    }

    protected function tearDown(): void
    {
        Strings::setTransliterator(null);
        parent::tearDown();
    }

    // 1. Поле из computed + extra: присвоенное значение не сбрасывается

    public function testComputedExtraFieldSurvivesOtherAssignments(): void
    {
        $doc = new Document(['description' => 'X', 'body' => 'Long body text']);
        $doc->id = 1;
        $doc->title = 'Title';
        $doc->body = 'Another body';

        $this->assertSame('X', $doc->description);
        $doc->title = 'Changed';
        $this->assertSame('X', $doc->description);
    }

    public function testComputedExtraFieldSurvivesSaveAndCreatedEvent(): void
    {
        $doc = new Document(['description' => 'X', 'body' => 'Long body text']);
        $seen = null;
        $doc->on(Model::EVENT_CREATED, function ($id, array $row) use ($doc, &$seen) {
            $doc->title = 'set in created';
            $seen = $doc->description;
            return $row;
        });

        $doc->save();
        $this->assertSame('X', $seen);
        $this->assertSame('X', $doc->description);
        $this->assertSame(1, $doc->id);

        $doc->body = 'Updated body';
        $doc->save();
        $this->assertSame('X', $doc->description);
    }

    public function testComputedExtraValueSurvivesAssigningIdFieldAndRelation(): void
    {
        $m = new TranslatedAttach(['description' => 'X']);
        $this->assertSame('X', $m->description);

        $m->id = 1;
        $this->assertSame('X', $m->description);
        $m->post_id = 2;
        $this->assertSame('X', $m->description);
        $m->tag = new Tag(['id' => 3, 'name' => 'php']);
        $this->assertSame('X', $m->description);
        $m->tag = ['id' => 4, 'name' => 'orm'];
        $this->assertSame('X', $m->description);
    }

    public function testComputedExtraValueSurvivesLaterFieldsInConstructorAndFromArray(): void
    {
        $this->assertSame('X', (new TranslatedAttach(['description' => 'X', 'id' => 1, 'post_id' => 2]))->description);

        $m = new TranslatedAttach();
        $m->fromArray(['description' => 'Y', 'id' => 1, 'post_id' => 2]);
        $this->assertSame('Y', $m->description);

        // Повторный fromArray без description значение не теряет
        $m->fromArray(['post_id' => 5]);
        $this->assertSame('Y', $m->description);
    }

    public function testComputedExtraValueIsAvailableInCreatedHandler(): void
    {
        $m = new TranslatedAttach(['description' => 'X', 'post_id' => 2]);
        $seen = [];
        $m->on(Model::EVENT_CREATED, function ($id, array $row) use ($m, &$seen) {
            // Как в приложении: хук проставляет id и сохраняет переводы
            $m->id = $id;
            $seen[] = [$id, $m->id, $m->description];
            return $row;
        });

        $m->save();
        $this->assertSame([[1, 1, 'X']], $seen);
        $this->assertSame('X', $m->description);

        $seen = [];
        $m2 = new TranslatedAttach(['description' => 'Z', 'post_id' => 3]);
        $m2->on(Model::EVENT_CREATED, function ($id, array $row) use ($m2, &$seen) {
            $seen[] = $m2->description;
            return $row;
        });
        $m2->insert();
        $this->assertSame(['Z'], $seen);
        $this->assertSame(2, $m2->id);
        $this->assertSame('Z', $m2->description);
    }

    public function testComputedExtraFieldWithoutAssignedValueIsComputed(): void
    {
        $this->assertSame('', (new TranslatedAttach(['id' => 1]))->description);
    }

    public function testComputedValuesAreStillRecalculated(): void
    {
        $doc = new Document(['title' => 'abc', 'body' => 'First body text']);
        $this->assertSame('ABC', $doc->title_upper);
        $this->assertSame('First body', $doc->description);

        $doc->title = 'xyz';
        $doc->body = 'Second body text';
        $this->assertSame('XYZ', $doc->title_upper);
        $this->assertSame('Second bod', $doc->description, 'not assigned: computed from body');

        $doc->description = 'Y';
        $doc->body = 'Third body text';
        $this->assertSame('Y', $doc->description);
    }

    // 2. Транслитерация слагов совпадает с глобальной translit() приложения

    /** @return array<string, array{0: string}> */
    public static function titles(): array
    {
        return [
            'spanish' => ['niño español'],
            'cyrillic' => ['Ёлка, щука, йогурт, цапля, эхо, объём!'],
            'quotes and dots' => ['Don\'t "stop" v1.2.3'],
            'french/german' => ['Ça été Über Größe à côté'],
            'spanish punctuation' => ['¿Qué tal? ¡Olé!'],
            'outside the table' => ['Straße Øresund Łódź 東京'],
            'digits only' => ['2024'],
            'upper case' => ['ÑANDÚ ÀÉÎÕÜ'],
        ];
    }

    #[DataProvider('titles')]
    public function testSlugMatchesLegacyGlobalTranslit(string $title): void
    {
        $this->assertFalse(function_exists('translit'), 'this test checks the fallback');

        $legacy = preg_replace('#[-]{2,}#', '-', LegacyTranslit::translit($title, '-'));
        $legacy = trim($legacy, '-');
        if ($legacy && !preg_match('#[^\d]+#', $legacy)) {
            $legacy = 'p-' . $legacy;
        }

        $this->assertSame($legacy, SlugPage::translit($title));
        $this->assertSame(md5($legacy), SlugPage::computeSlugHash(SlugPage::translit($title)));
    }

    public function testLatinWithDiacritics(): void
    {
        $this->assertSame('nino-espanol', SlugPage::translit('niño español'));
        $this->assertSame('yolka-shchuka', SlugPage::translit('Ёлка «Щука»'));
    }

    #[RunInSeparateProcess]
    public function testGlobalTranslitFunctionIsPreferred(): void
    {
        require_once __DIR__ . '/../Support/global_translit.php';
        LegacyTranslit::$calls = 0;

        $this->assertInstanceOf(CallableTransliterator::class, Strings::transliterator());
        $this->assertSame('nino-espanol', SlugPage::translit('niño español'));
        $this->assertSame(1, LegacyTranslit::$calls);

        // Явно заданная реализация важнее глобальной функции
        Strings::setTransliterator(new BuiltinTransliterator());
        $this->assertSame('nino-espanol', SlugPage::translit('niño español'));
        $this->assertSame(1, LegacyTranslit::$calls);
    }

    public function testTransliteratorCanBeReplacedGlobally(): void
    {
        $this->assertInstanceOf(BuiltinTransliterator::class, Strings::transliterator());

        Strings::setTransliterator(new class implements TransliteratorInterface {
            public function translit(string $text, string $delimiter = '-'): string
            {
                return 'custom' . $delimiter . strlen($text);
            }
        });
        $this->assertSame('custom-5', SlugPage::translit('hello'));

        Strings::setTransliterator(new CallableTransliterator([LegacyTranslit::class, 'translit']));
        $this->assertSame('yolka', SlugPage::translit('Ёлка'));

        Strings::setTransliterator(null);
        $this->assertInstanceOf(BuiltinTransliterator::class, Strings::transliterator());
        $this->assertSame('yolka', SlugPage::translit('Ёлка'));
    }

    public function testTransliteratorCanBeReplacedPerModel(): void
    {
        $this->assertSame('page-hello-world', UpperSlugPage::translit('Hello World'));
        $this->assertSame('hello-world', SlugPage::translit('Hello World'));
    }

    // 3. delete() без значения pk — no-op, а не TypeError

    public function testDeleteWithoutPrimaryKeyIsNoop(): void
    {
        (new Tag(['name' => 'keep']))->save();
        $events = [];
        $tag = new Tag(['name' => 'new']);
        $tag->on(Model::EVENT_DELETING, function (Model $m) use (&$events) {
            $events[] = 'deleting';
            return $m;
        });
        $this->resetQueryLog();

        $this->assertSame(0, $tag->delete());
        $this->assertSame(0, $tag->forceDelete());
        $this->assertQueryCount(0);
        $this->assertSame([], $events);
        $this->assertSame(1, Tag::instance()->count());
    }

    public function testSoftDeleteWithoutPrimaryKeyIsNoop(): void
    {
        $post = new Post(['title' => 'Draft']);
        $this->resetQueryLog();

        $this->assertFalse((bool) $post->delete());
        $this->assertFalse($post->forceDelete());
        $this->assertQueryCount(0);
    }

    // 4. Пустой insert id не присваивается pk модели и внешним ключам

    public function testEmptyInsertIdIsNotAssignedToPrimaryKey(): void
    {
        $author = new ZeroIdAuthor(['name' => 'Ann']);
        $this->assertSame(0, $author->save());
        $this->assertNull($author->id);

        // Следующий save() — снова INSERT, а не UPDATE ... WHERE id = 0
        $author->name = 'Bob';
        $author->save();
        $this->assertNull($author->id);
        $this->assertCount(2, ArrayStorage::$tables['zero_authors']);

        $this->assertSame(0, (new ZeroIdAuthor(['name' => 'Eve']))->insertI());
    }

    public function testCancelledInsertLeavesPrimaryKeyEmpty(): void
    {
        $tag = new Tag(['name' => 'nope']);
        $tag->on(Model::EVENT_CREATING, fn(array $row) => false);

        $this->assertSame(0, $tag->save());
        $this->assertNull($tag->id);
    }

    public function testEmptyInsertIdIsNotAssignedToBelongsForeignKey(): void
    {
        $note = new AuthorNote(['author_id' => 7, 'text' => 'hello']);
        $note->author = new ZeroIdAuthor(['name' => 'Ann']);
        $note->save();

        $this->assertSame(7, $note->author_id);
        $this->assertSame(7, AuthorNote::instance()->where(['text' => 'hello'])->value('author_id'));
    }

    public function testEmptyParentKeyIsNotAssignedToHasManyForeignKeys(): void
    {
        $author = new ZeroIdAuthor(['name' => 'Ann']);
        $author->notes = [['author_id' => 5, 'text' => 'child']];
        $author->save();

        $this->assertSame(5, $author->notes[0]->author_id);
        $this->assertSame(5, AuthorNote::instance()->where(['text' => 'child'])->value('author_id'));
    }

    // 5. Контракт для наследников, описанный в CHANGELOG

    public function testModelContractForSubclasses(): void
    {
        $types = new ReflectionProperty(Model::class, 'types');
        $this->assertFalse($types->hasType(), 'subclasses declare `protected $types = [...]`');

        $param = (new ReflectionMethod(Model::class, 'where'))->getParameters()[0];
        $actual = array_map('strval', $param->getType()->getTypes());
        sort($actual);
        $expected = ['array', Criteria::class, 'null', 'string'];
        sort($expected);
        $this->assertSame($expected, $actual);
    }
}
