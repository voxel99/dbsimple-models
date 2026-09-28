<?php

declare(strict_types=1);

namespace Jam\Models\Tests\Integration;

use Jam\Models\Model;
use Jam\Models\ModelList;
use Jam\Models\Tests\Fixtures\Models\Activity;
use Jam\Models\Tests\Fixtures\Models\Category;
use Jam\Models\Tests\Fixtures\Models\Comment;
use Jam\Models\Tests\Fixtures\Models\Post;
use Jam\Models\Tests\Fixtures\Models\PostTag;
use Jam\Models\Tests\Fixtures\Models\Profile;
use Jam\Models\Tests\Fixtures\Models\Tag;
use Jam\Models\Tests\Fixtures\Models\User;
use Jam\Models\Tests\Support\DatabaseTestCase;

final class EagerLoadingTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (new User(['email' => 'alice@x.io', 'name' => 'Alice']))->save();   // 1
        (new User(['email' => 'bob@x.io', 'name' => 'Bob']))->save();       // 2
        (new User(['email' => 'carol@x.io', 'name' => 'Carol']))->save();   // 3 — без профиля и постов
        (new Profile(['user_id' => 1, 'bio' => 'Alice bio']))->save();
        (new Profile(['user_id' => 2, 'bio' => 'Bob bio']))->save();

        (new Category(['title' => 'News']))->save();                           // 1
        (new Post(['user_id' => 1, 'category_id' => 1, 'title' => 'A1', 'views' => 3]))->save(); // 1
        (new Post(['user_id' => 1, 'title' => 'A2', 'views' => 7]))->save();                     // 2
        (new Post(['user_id' => 2, 'category_id' => 1, 'title' => 'B1', 'views' => 5]))->save(); // 3

        (new Comment(['post_id' => 1, 'user_id' => 2, 'body' => 'Nice', 'is_approved' => true]))->save();
        (new Comment(['post_id' => 1, 'user_id' => 3, 'body' => 'Spam']))->save();
        (new Comment(['post_id' => 3, 'user_id' => 1, 'body' => 'Привет', 'is_approved' => true]))->save();

        foreach (['php', 'orm', 'sql'] as $name) {
            (new Tag(['name' => $name]))->save();
        }
        foreach ([[1, 1], [1, 2], [3, 2], [3, 3]] as [$postId, $tagId]) {
            (new PostTag(['post_id' => $postId, 'tag_id' => $tagId]))->save();
        }
        $this->resetQueryLog();
    }

    public function testHasManyIsLoadedWithOneQueryPerRelation(): void
    {
        $users = User::instance()->with('posts')->orderBy('id')->all();

        $this->assertQueryCount(2);
        $this->assertSame('SELECT * FROM posts WHERE `user_id` IN (1, 2, 3) AND deleted_at IS NULL', $this->queries()[1]);

        $this->assertInstanceOf(ModelList::class, $users[0]->posts);
        $this->assertSame(['A1', 'A2'], $users[0]->posts->column('title'));
        $this->assertSame(['B1'], $users[1]->posts->column('title'));
        $this->assertNull($users[2]->posts, 'no related rows — relation stays null');
    }

    public function testHasOneAndBelongs(): void
    {
        $posts = Post::instance()->with('author, category')->orderBy('id')->all();

        $this->assertInstanceOf(User::class, $posts[0]->author);
        $this->assertSame('Alice', $posts[0]->author->name);
        $this->assertSame('News', $posts[0]->category->title);
        $this->assertNull($posts[1]->category);

        $user = User::instance()->with('profile')->id(2)->first();
        $this->assertSame('Bob bio', $user->profile->bio);
    }

    public function testNestedRelations(): void
    {
        $user = User::instance()->with('posts.comments.author, profile')->id(1)->first();

        $this->assertQueryCount(5); // users, posts, comments, users (authors), profiles
        $this->assertSame(['Nice', 'Spam'], $user->posts[0]->comments->column('body'));
        $this->assertSame('Bob', $user->posts[0]->comments[0]->author->name);
    }

    public function testManyToManyThroughPivot(): void
    {
        $posts = Post::instance()->with('tags:o(id)')->orderBy('id')->all();

        $this->assertQueryCount(3); // posts, post_tag, tags
        $this->assertSame('SELECT * FROM post_tag WHERE `post_id` IN (1, 2, 3)', $this->queries()[1]);
        $this->assertSame(['php', 'orm'], $posts[0]->tags->column('name'));
        $this->assertNull($posts[1]->tags);
        $this->assertSame(['orm', 'sql'], $posts[2]->tags->column('name'));
    }

    public function testPivotRowsCanBeExported(): void
    {
        $post = Post::instance()->with('tags:p:o(id)')->id(1)->first();
        $arr = $post->toArray();
        $this->assertSame(['id' => 1, 'post_id' => 1, 'tag_id' => 1], $arr['tags'][0][Model::PIVOT_FIELD]);
    }

    public function testRelationFieldsConditionsAndExclude(): void
    {
        $posts = Post::instance()
            ->with('comments[is_approved = 1](id, body), author^email^')
            ->orderBy('id')
            ->all();

        $this->assertQueryLogContains('SELECT * FROM comments WHERE (`post_id` IN (1, 2, 3)) AND (is_approved = 1)');
        $this->assertSame(['Nice'], $posts[0]->comments->column('body'));
        $this->assertSame(['Привет'], $posts[2]->comments->column('body'));

        $arr = $posts[0]->toArray();
        // к перечисленным полям автоматически добавляются ключи связей Comment (post_id, user_id)
        $this->assertSame([['id' => 1, 'post_id' => 1, 'user_id' => 2, 'body' => 'Nice']], $arr['comments']);
        $this->assertArrayNotHasKey('email', $arr['author']);
        $this->assertSame('Alice', $arr['author']['name']);
    }

    public function testConditionReferencingParentRow(): void
    {
        // Автор поста 1 (Alice) комментирует свой пост; Bob — свой пост 3
        (new Comment(['post_id' => 1, 'user_id' => 1, 'body' => 'Own comment']))->save();
        (new Comment(['post_id' => 3, 'user_id' => 2, 'body' => 'Own comment too']))->save();

        // this(field) — значение поля родительской записи: комментарии НЕ от автора поста
        $posts = Post::instance()->with('comments[user_id <> this(user_id)]')->orderBy('id')->all();

        $this->assertSame(['Nice', 'Spam'], $posts[0]->comments->column('body'));
        $this->assertSame(['Привет'], $posts[2]->comments->column('body'));
    }

    public function testConditionReferencingParentRowEscapesValues(): void
    {
        (new Post(['user_id' => 1, 'title' => "O'Reilly"]))->save();
        (new Comment(['post_id' => 4, 'user_id' => 1, 'body' => "O'Reilly"]))->save();
        $this->resetQueryLog();

        $post = Post::instance()->with('comments[body = this(title)]')->id(4)->first();

        $this->assertSame(["O'Reilly"], $post->comments->column('body'));
    }

    public function testOrderAndKeyByFlags(): void
    {
        $user = User::instance()->with('posts:o(views DESC):k(id)')->id(1)->first();
        $arr = $user->toArray();
        $this->assertSame([2, 1], array_keys($arr['posts']));
        $this->assertSame('A2', $arr['posts'][2]['title']);
    }

    public function testComputedFlagOnRelation(): void
    {
        (new Post(['user_id' => 3, 'title' => 'C1', 'body' => 'A very long body of the post']))->save();
        $arr = User::instance()->with('posts:c')->id(3)->first()->toArray();
        $this->assertSame('A very long body of ', $arr['posts'][0]['excerpt']);
    }

    public function testLimitFlagIsGlobalNotPerParent(): void
    {
        // l(N) ограничивает общий запрос по связи, а не количество записей у каждого родителя
        $users = User::instance()->with('posts:o(id):l(1)')->orderBy('id')->all();
        $this->assertSame(['A1'], $users[0]->posts->column('title'));
        $this->assertNull($users[1]->posts);
    }

    public function testReturnEmptyMarker(): void
    {
        $arr = User::instance()->with('profile!, posts!')->id(3)->first()->toArray();
        $this->assertEquals((object) [], $arr['profile']);
        $this->assertSame([], $arr['posts']);
    }

    public function testSoftDeletedRelatedRowsAreExcluded(): void
    {
        Post::instance()->id(2)->first()->delete();
        $this->resetQueryLog();

        $user = User::instance()->with('posts')->id(1)->first();
        $this->assertSame(['A1'], $user->posts->column('title'));

        // тот же фильтр при урезанном списке полей
        $user = User::instance()->with('posts(id, title)')->id(1)->first();
        $this->assertSame(['A1'], $user->posts->column('title'));

        // :r — вместе с удалёнными
        $user = User::instance()->with('posts:r')->id(1)->first();
        $this->assertSame(['A1', 'A2'], $user->posts->column('title'));
    }

    public function testPolymorphicRelationByClosure(): void
    {
        (new Activity(['user_id' => 1, 'subject_type' => 'post', 'subject_id' => 3]))->save();
        (new Activity(['user_id' => 1, 'subject_type' => 'comment', 'subject_id' => 2]))->save();
        $this->resetQueryLog();

        $feed = Activity::instance()->with('subject, user')->orderBy('id')->all();

        $this->assertInstanceOf(Post::class, $feed[0]->subject);
        $this->assertSame('B1', $feed[0]->subject->title);
        $this->assertInstanceOf(Comment::class, $feed[1]->subject);
        $this->assertSame('Spam', $feed[1]->subject->body);
        $this->assertQueryCount(4); // activities, posts, comments, users
    }

    public function testSelfReferencingRelation(): void
    {
        (new Category(['title' => 'Local', 'parent_id' => 1]))->save();
        (new Category(['title' => 'World', 'parent_id' => 1]))->save();

        $root = Category::instance()->with('children:o(title)')->id(1)->first();
        $this->assertSame(['Local', 'World'], $root->children->column('title'));

        $child = Category::instance()->with('parent')->where('title = ?', 'World')->first();
        $this->assertSame('News', $child->parent->title);
    }

    public function testSelectedFieldsGetRelationKeyAdded(): void
    {
        // Локальный ключ связи (user_id) добавляется в SELECT, иначе связь не собрать
        $posts = Post::instance()->with('author')->orderBy('id')->collection('title');
        $this->assertQueryLogContains('SELECT `title`, `user_id` FROM posts');
        $this->assertSame('Alice', $posts[0]->author->name);
    }

    public function testWithIsAppliedToFirstAndCollectionAlike(): void
    {
        $builder = Post::instance()->with('comments');
        $this->assertCount(3, $builder->all());
        // with сохраняется на экземпляре-построителе между запросами
        $this->assertNotNull($builder->id(1)->first()->comments);
    }
}
