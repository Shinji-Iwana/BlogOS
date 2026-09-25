<?php

namespace Tests\Feature\Articles;

use App\Enums\DraftState;
use App\Enums\PushState;
use App\Enums\SyncTrigger;
use App\Models\ArticleDraft;
use App\Models\ArticleKeyword;
use App\Models\ArticleManagement;
use App\Models\ArticleRelation;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Histories\ArticleManagementHistory;
use App\Models\Post;
use App\Models\User;
use App\Models\WordPressPushOperation;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * 記事・編集案・反映の画面（ARCHITECTURE 13-5・17章）。
 */
class ArticleScreensTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);

        $this->wp = new FakeWordPress();
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['categories'] = [FakeWordPress::term(10)];
        $this->wp->lists['pages'] = [FakeWordPress::page(50)];
        $this->wp->lists['posts'] = [
            FakeWordPress::post(100, ['categories' => [10], 'status' => 'draft', 'content' => ['raw' => '<p>行1</p>' . "\n" . '<a href="/post-101/">次</a>', 'rendered' => '']]),
            FakeWordPress::post(101),
        ];
        $this->wp->install();

        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);
        $this->actingAs($this->user);
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    public function test_article_list_and_detail_render(): void
    {
        $post = Post::where('wordpress_id', 100)->sole();

        $this->get(route('articles.index', ['type' => 'posts']))->assertOk()->assertSee('Title 100');
        $this->get(route('articles.index', ['type' => 'pages']))->assertOk()->assertSee('Title 50');
        $this->get(route('articles.show', ['type' => 'posts', 'id' => $post->id]))
            ->assertOk()
            ->assertSee('この記事からのリンク（1件）')
            ->assertSee('親ロードマップ'); // si-note の記事種類の定義を選択肢にする
        $this->get(route('articles.show', ['type' => 'posts', 'id' => $post->id, 'related_q' => 'Title 101']))
            ->assertOk()->assertSee('追加');
    }

    public function test_full_draft_edit_and_push_flow(): void
    {
        $post = Post::where('wordpress_id', 100)->sole();

        $this->post(route('drafts.store'), $this->selected(['article_type' => 'posts', 'article_id' => $post->id, 'revision_scope' => 'minor']))
            ->assertRedirect();
        $draft = ArticleDraft::sole();

        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertOk()->assertSee('Title 100');

        $this->put(route('drafts.update', ['id' => $draft->id]), $this->selected([
            'title_raw'              => '画面から変更',
            'content_raw'            => "<p>行1</p>\n<p>追加した行</p>",
            'excerpt_raw'            => '',
            'slug'                   => 'post-100',
            'status'                 => 'draft',
            'wordpress_category_ids' => [10],
            'revision_scope'         => 'minor',
        ]))->assertSessionHasNoErrors();

        $this->get(route('drafts.push.confirm', ['id' => $draft->id]))
            ->assertOk()
            ->assertSee('画面から変更')
            ->assertSee('+ &lt;p&gt;追加した行&lt;/p&gt;', false);

        // 承認のチェックがなければ反映しない
        $this->post(route('drafts.push.store', ['id' => $draft->id]), $this->selected())->assertSessionHasErrors('approved');
        $this->assertSame(0, WordPressPushOperation::count());

        $this->post(route('drafts.push.store', ['id' => $draft->id]), $this->selected(['approved' => 1]))
            ->assertRedirect(route('push-operations.show', ['id' => WordPressPushOperation::sole()->id]));

        $this->assertSame(PushState::Completed, WordPressPushOperation::sole()->state);
        $this->assertSame('画面から変更', $post->fresh()->title_raw);
        $this->assertSame(DraftState::Pushed, $draft->fresh()->state);

        $this->get(route('push-operations.show', ['id' => WordPressPushOperation::sole()->id]))->assertOk()->assertSee('反映が完了しました');
        $this->get(route('push-operations.index'))->assertOk();
    }

    public function test_publishing_requires_extra_confirmation(): void
    {
        $post = Post::where('wordpress_id', 100)->sole();
        $this->post(route('drafts.store'), $this->selected(['article_type' => 'posts', 'article_id' => $post->id]));
        $draft = ArticleDraft::sole();
        $draft->update(['status' => 'publish']);

        $this->post(route('drafts.push.store', ['id' => $draft->id]), $this->selected(['approved' => 1]))
            ->assertSessionHasErrors('confirmed_public');
        $this->assertSame(0, WordPressPushOperation::count());

        $this->post(route('drafts.push.store', ['id' => $draft->id]), $this->selected(['approved' => 1, 'confirmed_public' => 1]));
        $this->assertSame('publish', $post->fresh()->status);
    }

    public function test_new_article_draft_from_list(): void
    {
        $this->post(route('drafts.store'), $this->selected(['article_type' => 'pages']))->assertRedirect();

        $draft = ArticleDraft::sole();
        $this->assertTrue($draft->isNewArticle());
        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertOk()->assertSee('新規の固定ページ');
    }

    public function test_conflict_resolution_take_in(): void
    {
        $post = Post::where('wordpress_id', 100)->sole();
        $this->post(route('drafts.store'), $this->selected(['article_type' => 'posts', 'article_id' => $post->id]));
        $draft = ArticleDraft::sole();

        $this->wp->lists['posts'][0]['title'] = ['raw' => 'WordPressで変更', 'rendered' => 'WordPressで変更'];
        $this->wp->lists['posts'][0]['modified_gmt'] = '2026-09-20T00:00:00';
        app(SyncService::class)->run($this->blog, SyncTrigger::Scheduled);

        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertSee('競合の解消へ');
        $this->get(route('drafts.conflict.show', ['id' => $draft->id]))->assertOk()->assertSee('WordPressで変更');

        $this->post(route('drafts.conflict.resolve', ['id' => $draft->id]), $this->selected(['action' => 'take_in']))
            ->assertRedirect(route('drafts.edit', ['id' => $draft->id]));

        $this->assertSame('WordPressで変更', $post->fresh()->title_raw);
        $this->assertSame('2026-09-20 00:00:00', $draft->fresh()->base_wordpress_modified_gmt->format('Y-m-d H:i:s'));
        $this->assertSame(DraftState::Editing, $draft->fresh()->state);
        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertDontSee('競合の解消へ');
    }

    public function test_discard_in_conflict_requires_confirmation(): void
    {
        $post = Post::where('wordpress_id', 100)->sole();
        $this->post(route('drafts.store'), $this->selected(['article_type' => 'posts', 'article_id' => $post->id]));
        $draft = ArticleDraft::sole();

        $this->post(route('drafts.conflict.resolve', ['id' => $draft->id]), $this->selected(['action' => 'discard']))
            ->assertSessionHasErrors('confirmed');
        $this->assertSame(DraftState::Editing, $draft->fresh()->state);
    }

    public function test_management_keywords_and_relations(): void
    {
        $post = Post::where('wordpress_id', 100)->sole();
        $other = Post::where('wordpress_id', 101)->sole();

        $this->put(route('articles.management.update', ['type' => 'posts', 'id' => $post->id]), $this->selected([
            'article_type'       => 'acquisition',
            'article_subtype'    => 'know',
            'work_status'        => 'needs_revision',
            'main_search_intent' => '基本を知りたい',
            'sub_search_intents' => "例を見たい\n違いを知りたい",
            'main_keyword'       => 'PHP 入門',
            'sub_keywords'       => "PHP 基本\nPHP とは",
        ]))->assertSessionHasNoErrors();

        $management = ArticleManagement::sole();
        $this->assertSame(['例を見たい', '違いを知りたい'], $management->sub_search_intents);
        $this->assertSame(3, ArticleKeyword::count());

        // ブログ別の定義にない記事種類は受け付けない
        $this->put(route('articles.management.update', ['type' => 'posts', 'id' => $post->id]), $this->selected([
            'article_type' => 'unknown_type', 'work_status' => 'done',
        ]))->assertSessionHasErrors('article_type');

        // 変更した項目だけ履歴に残す
        $this->put(route('articles.management.update', ['type' => 'posts', 'id' => $post->id]), $this->selected([
            'article_type' => 'acquisition', 'article_subtype' => 'know', 'work_status' => 'in_progress',
            'main_search_intent' => '基本を知りたい', 'sub_search_intents' => "例を見たい\n違いを知りたい",
            'main_keyword' => 'PHP 入門', 'sub_keywords' => "PHP 基本\nPHP とは",
        ]))->assertSessionHasNoErrors();
        $lastChangeSet = ArticleManagementHistory::latest('id')->value('change_set_id');
        $this->assertSame(['work_status'], ArticleManagementHistory::where('change_set_id', $lastChangeSet)->pluck('field')->all());

        $this->post(route('articles.relations.store', ['type' => 'posts', 'id' => $post->id]), $this->selected([
            'related_type' => 'posts', 'related_id' => $other->id, 'relation_type' => 'next', 'sort_order' => 1,
        ]))->assertSessionHasNoErrors();
        $relation = ArticleRelation::sole();
        $this->assertTrue(ArticleManagementHistory::where('field', 'relations')->exists());

        // 本文から post-101 へのリンクがあるため「あり」と表示する
        $this->get(route('articles.show', ['type' => 'posts', 'id' => $post->id]))->assertSee('あり');

        $this->delete(route('articles.relations.destroy', ['type' => 'posts', 'id' => $post->id, 'relationId' => $relation->id]), $this->selected())
            ->assertSessionHasNoErrors();
        $this->assertSame(0, ArticleRelation::count());
    }

    public function test_trash_requires_confirmation_and_force_delete_requires_slug(): void
    {
        $post = Post::where('wordpress_id', 101)->sole();

        $this->get(route('articles.trash.confirm', ['type' => 'posts', 'id' => $post->id, 'force' => 1]))->assertOk()->assertSee('元に戻せません');

        $base = $post->wordpress_modified_gmt->format('Y-m-d H:i:s');
        $this->post(route('articles.trash.destroy', ['type' => 'posts', 'id' => $post->id]), $this->selected(['force' => 1, 'base_modified' => $base]))
            ->assertSessionHasErrors(['confirmed', 'confirm_slug']);

        $this->post(route('articles.trash.destroy', ['type' => 'posts', 'id' => $post->id]), $this->selected(['force' => 1, 'base_modified' => $base, 'confirmed' => 1, 'confirm_slug' => 'wrong']))
            ->assertSessionHasErrors('confirm_slug');
        $this->assertNull($post->fresh()->wordpress_deleted_at);

        $this->post(route('articles.trash.destroy', ['type' => 'posts', 'id' => $post->id]), $this->selected(['force' => 0, 'base_modified' => $base, 'confirmed' => 1]))
            ->assertRedirect();
        $this->assertSame('trash', $post->fresh()->status);
    }

    public function test_updates_are_rejected_when_selected_blog_changed(): void
    {
        $post = Post::where('wordpress_id', 100)->sole();

        $this->post(route('drafts.store'), ['selected_blog_id' => 999, 'article_type' => 'posts', 'article_id' => $post->id])
            ->assertSessionHasErrors('selected_blog_id');
        $this->assertSame(0, ArticleDraft::count());
    }
}
