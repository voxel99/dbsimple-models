<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\ModelException;
use Jam\Models\Tests\Fixtures\Models\Category;
use Jam\Models\Tests\Fixtures\Models\Comment;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\PostTag;
use Jam\Models\Tests\Fixtures\Models\Profile;
use Jam\Models\Tests\Fixtures\Models\Tag;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\DatabaseTestCase;

/**
 * save() сохраняет граф: сначала belongs-связи (чтобы получить внешние ключи),
 * затем саму модель, затем has-связи с проставленными ключами.
 */
final class SavingRelationsTest extends DatabaseTestCase
{
    public function testSaveGraphWithHasOneAndHasMany(): void
    {
        $user = new User([
            'email' => 'alice@x.io',
            'profile' => ['bio' => 'Hello'],
            'posts' => [
                ['title' => 'First'],
                ['title' => 'Second', 'comments' => [['body' => 'Nice']]],
            ],
        ]);
        $id = $user->save();

        $this->assertSame(1, $id);
        $this->assertSame(1, Profile::instance()->value('user_id'));
        $this->assertSame([1, 1], Post::instance()->column('user_id'));
        $this->assertSame(2, Comment::instance()->value('post_id'));
        // ключи проставлены и в объектах в памяти
        $this->assertSame(1, $user->posts[1]->user_id);
        $this->assertSame(2, $user->posts[1]->comments[0]->post_id);
    }

    public function testSaveBelongsRelationFirst(): void
    {
        $post = new Post([
            'title' => 'With category',
            'category' => ['title' => 'News'],
            'author' => ['email' => 'bob@x.io'],
        ]);
        $post->save();

        $this->assertSame(1, $post->category_id);
        $this->assertSame(1, $post->user_id);
        $this->assertSame(
            'INSERT INTO posts (`title`, `user_id`, `category_id`, `created_at`)',
            substr($this->queries()[2], 0, 67)
        );
    }

    public function testAttachExistingBelongsModel(): void
    {
        (new Category(['title' => 'News']))->save();
        $post = new Post(['title' => 'P']);
        $post->category = Category::instance()->id(1)->first();
        $post->save();

        $this->assertSame(1, Post::instance()->id(1)->value('category_id'));
        $this->assertSame(1, Category::instance()->count());
    }

    public function testExistingModelsInConstructorData(): void
    {
        (new User(['email' => 'a@x.io']))->save();
        $author = User::instance()->id(1)->first();
        $tag = new Tag(['name' => 'php']);

        $post = new Post(['title' => 'P', 'author' => $author, 'tags' => [$tag, ['name' => 'orm']]]);
        $post->save();

        $this->assertSame($author, $post->author);
        $this->assertSame(1, $post->user_id);
        $this->assertSame(['php', 'orm'], Post::instance()->with('tags:o(id)')->id(1)->first()->tags->column('name'));
    }

    public function testSaveManyToManyCreatesPivotRows(): void
    {
        $post = new Post(['title' => 'Tagged', 'tags' => [['name' => 'php'], ['name' => 'orm']]]);
        $post->save();

        $this->assertSame(['php', 'orm'], Tag::instance()->orderBy('id')->column('name'));
        $this->assertEquals(
            [['post_id' => 1, 'tag_id' => 1], ['post_id' => 1, 'tag_id' => 2]],
            PostTag::instance()->orderBy('tag_id')->collectionRaw('post_id, tag_id')
        );
    }

    public function testResaveDoesNotDuplicatePivotRows(): void
    {
        $post = new Post(['title' => 'Tagged', 'tags' => [['name' => 'php']]]);
        $post->save();

        $post = Post::instance()->with('tags')->id(1)->first();
        $post->title = 'Renamed';
        $post->save();

        $this->assertSame(1, PostTag::instance()->count());
        $this->assertSame(1, Tag::instance()->count());
    }

    public function testCheckOldRemovesDetachedPivotRows(): void
    {
        (new Post(['title' => 'Tagged', 'tags' => [['name' => 'a'], ['name' => 'b'], ['name' => 'c']]]))->save();

        $post = Post::instance()->with('tags')->id(1)->first();
        $post->tags = array_filter($post->tags->toArray(), fn($tag) => $tag['name'] !== 'b');
        $post->save([Model::SAVE_USE_CHECK_OLD => true]);

        $this->assertSame(['a', 'c'], Post::instance()->with('tags:o(id)')->id(1)->first()->tags->column('name'));
        $this->assertSame(3, Tag::instance()->count(), 'Tag itself is not deleted, only the pivot row');
        $this->assertSame(2, PostTag::instance()->count());
    }

    public function testCheckOldRemovesDetachedHasManyChildren(): void
    {
        (new Post(['title' => 'P', 'comments' => [['body' => 'keep'], ['body' => 'drop']]]))->save();

        $post = Post::instance()->with('comments')->id(1)->first();
        $post->comments = [$post->comments[0]];
        $post->save([Model::SAVE_USE_CHECK_OLD => true]);

        $this->assertSame(['keep'], Comment::instance()->column('body'));
    }

    public function testSaveWithoutRelations(): void
    {
        $user = new User(['email' => 'a@x.io', 'posts' => [['title' => 'ignored']]]);
        $user->save([], false);

        $this->assertSame(1, User::instance()->count());
        $this->assertSame(0, Post::instance()->count());
    }

    public function testDeleteWithHasRelations(): void
    {
        (new User(['email' => 'a@x.io', 'profile' => ['bio' => 'x'], 'posts' => [['title' => 'P']]]))->save();

        $user = User::instance()->with('profile, posts')->id(1)->first();
        $user->delete(true);

        $this->assertSame(0, User::instance()->count());
        $this->assertSame(0, Profile::instance()->count());
        // Post использует SoftDeletes: при каскадном удалении запись помечается, а не удаляется
        $this->assertSame(0, Post::instance()->count());
        $this->assertSame(1, Post::instance()->withTrashed()->count());
    }

    public function testDummyRelationIsReadonlyOnSave(): void
    {
        $member = $this->memberWithRole(['id' => 2, 'code' => 'editor', 'title' => 'Editor']);
        $member->save(); // неизменённая строка справочника — можно
        // ключ справочника проставлен в локальное поле (сырой запрос: у User is_active — bool)
        $this->assertEquals(2, User::instance()->id(1)->firstRaw()['is_active']);

        $member = $this->memberWithRole(['id' => 1, 'code' => 'hacked', 'title' => 'x']);
        $this->expectException(ModelException::class);
        $this->expectExceptionMessage('DummyModel is readonly');
        $member->save();
    }

    /** @param array<string, mixed> $role */
    private function memberWithRole(array $role): Model
    {
        if (!User::instance()->id(1)->first()->isExists()) {
            (new User(['email' => 'a@x.io']))->save();
        }
        return new class (['id' => 1, 'role' => $role]) extends Model {
            protected $table = 'users';
            protected string $fields = 'id, is_active';

            public function __construct($data = null)
            {
                $this->belongs(\Jam\Models\Tests\Fixtures\Models\Role::class, 'role', 'is_active', 'id');
                parent::__construct($data);
            }
        };
    }
}
