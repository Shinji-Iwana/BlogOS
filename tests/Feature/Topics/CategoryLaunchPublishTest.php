<?php

namespace Tests\Feature\Topics;

use App\Enums\ChangeSource;
use App\Enums\DraftState;
use App\Enums\AiMode;
use App\Enums\PushResourceType;
use App\Enums\RevisionScope;
use App\Enums\SuggestionStatus;
use App\Enums\SyncTrigger;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Category;
use App\Models\CategoryLaunch;
use App\Models\Page;
use App\Models\Post;
use App\Models\TopicSuggestion;
use App\Models\User;
use App\Repositories\ArticleDraftRepository;
use App\Services\Ai\AiRunService;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Articles\DraftService;
use App\Services\Sync\SyncService;
use App\Services\Topics\CategoryLaunchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * カテゴリの立ち上げの公開（D-41 の ⑥・⑦）と親ロードマップ（⑧）。
 */
class CategoryLaunchPublishTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);
        $this->wp = new FakeWordPress();
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['categories'] = [FakeWordPress::term(30, ['name' => 'Java', 'slug' => 'java'])];
        $this->wp->install();
        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);

        $this->actingAs(User::factory()->create());
    }

    protected function draft(PushResourceType $type, string $title, string $content, int $childId): ArticleDraft
    {
        $draft = app(DraftService::class)->createNew($this->blog, $type, null);
        app(ArticleDraftRepository::class)->update($draft, ['title_raw' => $title, 'content_raw' => $content, 'status' => 'draft'], ChangeSource::System, null);
        $draft->forceFill(['category_launch_child_id' => $childId])->save();

        return $draft->fresh();
    }

    public function test_first_publish_creates_category_parent_page_and_publishes_articles_and_roadmap(): void
    {
        $java = Category::where('slug', 'java')->sole();
        $suggestion = TopicSuggestion::create(['blog_id' => $this->blog->id, 'type' => 'category', 'category_id' => $java->id, 'title' => 'オブジェクト指向', 'slug' => 'java-oop', 'status' => SuggestionStatus::Accepted]);
        $launch = app(CategoryLaunchService::class)->create($this->blog, $java, [], [$suggestion->id], null);
        $child = $launch->children()->sole();

        $first = $this->draft(PushResourceType::Post, 'クラスとは', '<p>本文</p>', $child->id);
        $second = $this->draft(PushResourceType::Post, '継承とは', "<p>前の記事：[[記事:下書き{$first->id}]]</p>", $child->id);
        $second->update(['content_raw' => app(ArticleHtmlFinisher::class)->finish($this->blog, $second->content_raw)['content']]);
        $roadmapContent = app(ArticleHtmlFinisher::class)->finish($this->blog, "<ul><li>[[記事:下書き{$first->id}]]</li><li>[[記事:下書き{$second->id}]]</li></ul>")['content'];
        $roadmap = $this->draft(PushResourceType::Page, 'Java オブジェクト指向ロードマップ', $roadmapContent, $child->id);
        $child->update(['roadmap_draft_id' => $roadmap->id]);

        $this->get(route('launches.show', ['id' => $launch->id]))->assertOk()->assertSee('⑥・⑦ 公開')->assertSee('子カテゴリ「オブジェクト指向」（java-oop）を WordPress に作ります');

        $this->post(route('launches.children.publish', ['id' => $child->id]), [
            'selected_blog_id' => $this->blog->id, 'drafts' => [$first->id, $second->id], 'roadmap' => '1',
        ])->assertSessionHasNoErrors()->assertSessionHas('status', fn ($m) => str_contains($m, '3件を公開しました'));

        // 子カテゴリを作った（親は Java）
        $category = Category::where('slug', 'java-oop')->sole();
        $this->assertSame($java->id, $category->parent_id);
        $this->assertSame($category->id, $child->fresh()->category_id);

        // 記事は、子カテゴリで公開した。後に公開した記事は、先に公開した記事へのリンクになる
        $posts = Post::whereIn('title_raw', ['クラスとは', '継承とは'])->get()->keyBy('title_raw');
        $this->assertSame('publish', $posts['クラスとは']->status);
        $this->assertSame([(int) $category->wordpress_id], $posts['継承とは']->categories()->pluck('categories.wordpress_id')->map(fn ($id) => (int) $id)->all());
        $this->assertStringContainsString('<a href="', $posts['継承とは']->content_raw);

        // 親ロードマップのページを、WordPress の下書きとして先に作った。子ロードマップは、その子のページとして公開した
        $parentPage = Page::where('slug', 'java')->sole();
        $this->assertSame('draft', $parentPage->status);
        $roadmapPage = Page::where('title_raw', 'Java オブジェクト指向ロードマップ')->sole();
        $this->assertSame('publish', $roadmapPage->status);
        $this->assertSame((int) $parentPage->wordpress_id, (int) $roadmapPage->wordpress_parent_id);
        $this->assertSame('java-oop', $roadmapPage->slug);
        $this->assertSame(2, substr_count($roadmapPage->content_raw, '<a href="'));

        // 親ロードマップの編集案（⑧で中身を作る）を、作業中で残した
        $parentDraft = ArticleDraft::find(CategoryLaunch::find($launch->id)->parent_roadmap_draft_id);
        $this->assertSame($parentPage->id, $parentDraft->page_id);
        $this->assertSame(DraftState::Editing, $parentDraft->state);

        $this->get(route('launches.show', ['id' => $launch->id]))->assertOk()->assertSee('すべて公開しました');
    }

    public function test_new_parent_category_can_be_created(): void
    {
        $this->post(route('launches.parents.store'), ['selected_blog_id' => $this->blog->id, 'name' => 'Go', 'slug' => 'go'])->assertRedirect();
        $go = Category::where('slug', 'go')->sole();
        $this->assertNull($go->parent_id);
        $this->get(route('launches.index', ['parent_id' => $go->id]))->assertOk()->assertSee('親カテゴリ「Go」を WordPress に作りました');
    }

    public function test_parent_roadmap_is_generated_as_revision_of_placeholder_page_and_published(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => null]);
        $java = Category::where('slug', 'java')->sole();
        $suggestion = TopicSuggestion::create(['blog_id' => $this->blog->id, 'type' => 'category', 'category_id' => $java->id, 'title' => 'オブジェクト指向', 'slug' => 'java-oop', 'status' => SuggestionStatus::Accepted]);
        $launch = app(CategoryLaunchService::class)->create($this->blog, $java, [], [$suggestion->id], null);
        $child = $launch->children()->sole();
        $article = $this->draft(PushResourceType::Post, 'クラスとは', '<p>本文</p>', $child->id);

        // 子ロードマップの編集案がそろうまでは作れない
        $this->get(route('launches.show', ['id' => $launch->id]))->assertOk()->assertSee('すべての子カテゴリの子ロードマップの編集案ができると');
        $this->post(route('launches.parent-roadmap', ['id' => $launch->id]), ['selected_blog_id' => $this->blog->id])->assertSessionHasErrors('ai');

        $roadmap = $this->draft(PushResourceType::Page, 'Java オブジェクト指向ロードマップ', '<p>ロードマップ</p>', $child->id);
        $child->update(['roadmap_draft_id' => $roadmap->id]);
        $this->post(route('launches.children.publish', ['id' => $child->id]), ['selected_blog_id' => $this->blog->id, 'drafts' => [$article->id], 'roadmap' => '1'])->assertSessionHasNoErrors();
        $parentPage = Page::where('slug', 'java')->sole();

        // ⑧ 「準備中」のページの全面改修として、子ロードマップの目印を渡して作る
        Http::fake(['api.openai.com/v1/responses' => fn (Request $request) => Http::response(['id' => 'resp', 'status' => 'completed', 'model' => $request['model'],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => "=== タイトル ===\nJava ロードマップ\n=== 本文 ===\n<h2>学習ロードマップ</h2>\n<ul><li>[[記事:下書き{$roadmap->id}]]</li></ul>"]]]],
            'usage' => ['input_tokens' => 1000, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 500, 'output_tokens_details' => ['reasoning_tokens' => 0]]])]);
        Queue::fake();
        $this->post(route('launches.parent-roadmap', ['id' => $launch->id]), ['selected_blog_id' => $this->blog->id])->assertSessionHasNoErrors()->assertRedirect();
        $generation = AiGeneration::where('purpose', AiMode::Revision)->sole();
        $this->assertSame($parentPage->id, $generation->page_id);
        $this->assertSame(RevisionScope::Full, $generation->revision_scope);
        $this->assertStringContainsString('「準備中」のページです', $generation->input);
        $this->assertStringContainsString("[[記事:下書き{$roadmap->id}]]", $generation->input);
        app(AiRunService::class)->runApi($generation);

        $parentDraft = ArticleDraft::find(CategoryLaunch::find($launch->id)->parent_roadmap_draft_id);
        $this->assertSame('Java ロードマップ', $parentDraft->title_raw);
        $this->assertStringContainsString('<a href="', $parentDraft->content_raw);
        $this->get(route('launches.show', ['id' => $launch->id]))->assertOk()->assertSee('親ロードマップ「Java ロードマップ」を公開する');

        // 公開すると、記事・子ロードマップがすべて公開済みのため、立ち上げを完了にする
        $this->post(route('launches.parent-roadmap.publish', ['id' => $launch->id]), ['selected_blog_id' => $this->blog->id])
            ->assertSessionHasNoErrors()->assertSessionHas('status', fn ($m) => str_contains($m, '立ち上げを「完了」にしました'));
        $this->assertSame('publish', $parentPage->fresh()->status);
        $this->assertSame('Java ロードマップ', $parentPage->fresh()->title_raw);
        $this->assertSame('completed', $launch->fresh()->status);
    }
}
