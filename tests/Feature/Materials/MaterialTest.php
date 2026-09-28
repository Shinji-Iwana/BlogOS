<?php

namespace Tests\Feature\Materials;

use App\Enums\AiGenerationStatus;
use App\Enums\ArticleMaterialSource;
use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Enums\MaterialSuggestionType;
use App\Enums\SuggestionStatus;
use App\Jobs\RunAiApiJob;
use App\Models\AiGeneration;
use App\Models\ArticleManagement;
use App\Models\ArticleMaterial;
use App\Models\ArticleMaterialReview;
use App\Models\Blog;
use App\Models\BlogAiSetting;
use App\Models\Category;
use App\Models\Material;
use App\Models\MaterialSuggestion;
use App\Models\Post;
use App\Models\User;
use App\Services\Articles\ContentExtractionService;
use App\Services\Materials\MaterialMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 収益用の教材：登録・記事との照合・AIの調査と候補探し・記事の教材の見直し・定期チェック（D-30）。
 */
class MaterialTest extends TestCase
{
    use RefreshDatabase;

    protected const AMAZON = '//af.moshimo.com/af/c/click?a_id=1&p_id=170&pc_id=185&pl_id=4062&url=https%3A%2F%2Fwww.amazon.co.jp%2Fdp%2F4295005924';

    protected const RAKUTEN = '//af.moshimo.com/af/c/click?a_id=2&p_id=54&pc_id=54&pl_id=616&url=https%3A%2F%2Fbooks.rakuten.co.jp%2Frb%2F15827907%2F';

    protected const SCHOOL = '//af.moshimo.com/af/c/click?a_id=3&p_id=1000&pc_id=1380&pl_id=72072';

    protected User $user;

    protected Blog $blog;

    protected Category $javascript;

    protected Category $event;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => null, 'services.rakuten.application_id' => null]);
        Http::preventStrayRequests();

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->javascript = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 10, 'name' => 'JavaScript']);
        $this->event = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 11, 'name' => 'Event（イベント操作）', 'parent_id' => $this->javascript->id]);

        $this->post = $this->createPost(100, 'addEventListenerの使い方', $this->bookBox() . '<p><a href="https://trk.udemy.com/3kkdxr" rel="nofollow sponsored">超JavaScript 完全ガイド 2026（Udemy）</a></p>'
            . '<p><a href="' . self::SCHOOL . '">DMM WEBCAMP 学習コース（無料相談はこちら）</a></p><p><a href="http://snnsk.com/81.html">Excel初心者向けの入門講座</a></p>');
        $this->post->categories()->attach($this->event->id);

        $this->actingAs($this->user);
    }

    protected function createPost(int $wordpressId, string $title, string $content): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title, 'status' => 'publish',
            'content_raw' => $content, 'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html",
            'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    protected function bookBox(): string
    {
        return '<div class="book-box"><h3>いちばんやさしいJavaScriptの教本 第2版</h3><div class="book-links">'
            . '<a href="' . self::AMAZON . '">Amazonで見る</a><a href="' . self::RAKUTEN . '">楽天で見る</a></div></div>';
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    /**
     * 見直しが必要な理由（画面と同じく、教材・記事を読み込んでから判定する）
     */
    protected function reason(ArticleMaterial $record): ?string
    {
        return ArticleMaterial::with(['material.successors', 'post', 'page'])->findOrFail($record->id)->reviewReason();
    }

    protected function registerDetected(): void
    {
        $this->get(route('materials.detected'))->assertOk()
            ->assertSee('いちばんやさしいJavaScriptの教本 第2版')
            ->assertSee('超JavaScript 完全ガイド 2026')
            ->assertSee('DMM WEBCAMP 学習コース')
            ->assertDontSee('Excel初心者向けの入門講座');

        $this->post(route('materials.detected.store'), $this->selected([
            'selected' => [0, 1, 2],
            'items'    => [0 => ['name' => '', 'kind' => 'book'], 1 => ['name' => '', 'kind' => 'school'], 2 => ['name' => '', 'kind' => 'udemy']],
        ]))->assertRedirect(route('materials.index'));
    }

    public function test_links_in_existing_articles_are_registered_and_linked(): void
    {
        $this->registerDetected();

        $this->assertSame(3, Material::count());
        $book = Material::where('kind', MaterialKind::Book)->sole();
        $this->assertSame('いちばんやさしいJavaScriptの教本 第2版', $book->name);
        $this->assertSame('https:' . self::AMAZON, $book->amazon_url);
        $this->assertSame('https:' . self::RAKUTEN, $book->rakuten_url);
        // Amazon のリンクの ASIN から、ISBN を補う
        $this->assertSame('4295005924', $book->asin);
        $this->assertSame('9784295005926', $book->isbn);
        // 書籍の商品ページは、Amazon と楽天のそれぞれを、リンクの遷移先から補う（D-30-09）
        $this->assertSame('https://www.amazon.co.jp/dp/4295005924', $book->amazon_product_url);
        $this->assertSame('https://books.rakuten.co.jp/rb/15827907/', $book->rakuten_product_url);
        $this->assertNull($book->product_url);

        // 記事で使っている教材として記録し、今の本文のままなら見直しは不要
        $records = ArticleMaterial::where('post_id', $this->post->id)->get();
        $this->assertCount(3, $records);
        $this->assertTrue($records->every(fn ($record) => $record->source === ArticleMaterialSource::Detected && $this->reason($record) === null));

        // 登録済みのリンクは、検出の画面で登録済みと表示する
        $this->get(route('materials.detected'))->assertOk()->assertSee('登録済み');
        $this->get(route('materials.index'))->assertOk()->assertSee('いちばんやさしいJavaScriptの教本 第2版');
        $this->get(route('articles.show', ['type' => 'posts', 'id' => $this->post->id]))->assertOk()->assertSee('紹介している教材（3件）');

        // 同期で本文が変わり、リンクがなくなったら、記録も消す
        $this->post->update(['content_raw' => $this->bookBox()]);
        app(ContentExtractionService::class)->extract($this->post->fresh(), $this->blog);
        $this->assertSame([$book->id], ArticleMaterial::where('post_id', $this->post->id)->pluck('material_id')->all());
    }

    public function test_material_is_created_from_html_link_and_info_change_requires_review(): void
    {
        $this->post(route('materials.store'), $this->selected([
            'kind' => 'school', 'status' => 'active', 'name' => 'DMM WEBCAMP',
            'affiliate_url' => '<a href="' . str_replace('&', '&amp;', self::SCHOOL) . '" rel="nofollow">DMM WEBCAMP</a><img src="//i.moshimo.com/af/i/impression?a_id=3" width="1" height="1">',
            'category_ids' => [$this->javascript->id],
        ]))->assertRedirect();

        $material = Material::sole();
        $this->assertSame('https:' . self::SCHOOL, $material->affiliate_url);
        $this->assertSame([$this->javascript->id], $material->categories->pluck('id')->all());
        $record = ArticleMaterial::sole();
        $this->assertSame($this->post->id, $record->post_id);

        $update = fn (array $values) => $this->put(route('materials.update', ['id' => $material->id]), $this->selected(array_merge([
            'kind' => 'school', 'status' => 'active', 'name' => 'DMM WEBCAMP', 'affiliate_url' => self::SCHOOL, 'category_ids' => [$this->javascript->id],
        ], $values)))->assertRedirect();

        // 初めて情報を入れた場合は、見直しの対象にしない
        $update(['summary' => '未経験からWeb制作を学ぶ', 'scenes' => ['struggling', 'career']]);
        $this->assertNull($this->reason($record));

        // 情報を変えたら、使っている記事を見直しの対象にする
        $this->travel(1)->minutes();
        $update(['summary' => '未経験からWeb制作を学ぶ。転職の支援あり', 'scenes' => ['struggling', 'career']]);
        $this->assertSame('教材の情報を更新した', $this->reason($record));
        $this->get(route('materials.reviews.index'))->assertOk()->assertSee('教材の情報を更新した');

        // 人が見直した（このままでよい）
        $this->post(route('materials.reviews.mark'), $this->selected(['target' => "posts:{$this->post->id}"]))->assertRedirect();
        $this->assertNull($this->reason($record));

        // 使わないにしたら、見直しの対象にする。記事で使っている教材は削除できない
        $update(['status' => 'inactive', 'summary' => '未経験からWeb制作を学ぶ。転職の支援あり', 'scenes' => ['struggling', 'career']]);
        $this->assertSame('教材を「使わない」にした', $this->reason($record));
        $this->delete(route('materials.destroy', ['id' => $material->id]), $this->selected())->assertSessionHasErrors('material');
    }

    public function test_research_manual_flow_creates_suggestions_and_new_edition(): void
    {
        $this->registerDetected();
        $book = Material::where('kind', MaterialKind::Book)->sole();

        $this->post(route('materials.research', ['id' => $book->id]), $this->selected([
            'execution_method' => 'manual', 'pasted' => '楽天ブックス：いちばんやさしいJavaScriptの教本 第2版 発売日 2020年03月',
        ]))->assertRedirect();

        $generation = AiGeneration::sole();
        $this->assertSame($book->id, $generation->material_id);
        $this->assertFalse($generation->use_web_search);
        $this->assertStringContainsString('いちばんやさしいJavaScriptの教本 第2版', $generation->input);
        $this->assertStringContainsString('ISBN：9784295005926', $generation->input);
        $this->assertStringContainsString('Amazonの商品ページ：https://www.amazon.co.jp/dp/4295005924', $generation->input);
        $this->assertStringContainsString('楽天の商品ページ：https://books.rakuten.co.jp/rb/15827907/', $generation->input);
        $this->assertStringContainsString('発売日 2020年03月', $generation->input);
        $this->assertStringContainsString("{$this->event->id}：Event（イベント操作）（親：JavaScript）", $generation->input);
        $this->assertStringContainsString('（楽天ブックスAPIは設定されていないため、使っていません）', $generation->input);

        $output = "```json\n" . json_encode([
            'material' => [
                'name' => 'いちばんやさしいJavaScriptの教本 第2版', 'creator' => '岩田宇史', 'publisher' => 'インプレス', 'edition' => '第2版',
                'published_on' => '2020-03', 'isbn' => '978-4-295-00592-6', 'product_url' => 'https://book.impress.co.jp/books/1119101030',
                'category_ids' => [$this->javascript->id, 9999], 'topics' => ['JavaScript', 'DOM', 'イベント'], 'target_versions' => ['JavaScript ES2019'],
                'levels' => ['intro', 'beginner', 'expert'], 'scenes' => ['systematic', 'unknown'], 'summary' => '基礎を学べる入門書。',
                'target_readers' => '初めての人', 'not_for' => '経験者', 'merits' => ['図が多い'], 'cautions' => ['発展的な内容は少ない'],
                'availability' => null,
            ],
            'newer'   => [
                ['kind' => 'book', 'relation' => 'new_edition', 'name' => 'いちばんやさしいJavaScriptの教本 第3版', 'isbn' => '9784295099999', 'published_on' => '2026-05-20', 'product_url' => 'https://www.amazon.co.jp/dp/4295099999', 'reason' => '最新版'],
                // 登録済みの教材は候補にしない
                ['kind' => 'book', 'relation' => 'same_author', 'name' => 'いちばんやさしい JavaScript の教本　第2版', 'reason' => '同じ'],
            ],
            'sources' => ['https://book.impress.co.jp/books/1119101030', 'ftp://files.example.test/book'],
            'reason'  => '出版社のページで確認した',
        ], JSON_UNESCAPED_UNICODE) . "\n```";

        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => $output, 'model' => 'gpt-6-luna']))->assertRedirect();
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->fresh()->status);
        $this->assertNotNull($book->fresh()->researched_at);

        $research = MaterialSuggestion::where('type', MaterialSuggestionType::Research)->sole();
        $this->assertSame('2020-03-01', $research->data['published_on']);
        $this->assertSame('9784295005926', $research->data['isbn']);
        $this->assertSame([$this->javascript->id], $research->data['category_ids']);
        $this->assertSame(['intro', 'beginner'], $research->data['levels']);
        $this->assertSame(['systematic'], $research->data['scenes']);
        $this->assertSame(['https://book.impress.co.jp/books/1119101030'], $research->data['sources']);
        $this->assertSame('https://book.impress.co.jp/books/1119101030', $research->data['product_url']);

        // 新しい版の商品ページ：product_url に Amazon のページが入っていたら、Amazon の欄に分ける
        $this->assertSame('https://www.amazon.co.jp/dp/4295099999', MaterialSuggestion::where('type', MaterialSuggestionType::Candidate)->sole()->data['amazon_product_url']);

        $candidate = MaterialSuggestion::where('type', MaterialSuggestionType::Candidate)->sole();
        $this->assertSame('いちばんやさしいJavaScriptの教本 第3版', $candidate->name);
        $this->assertSame($book->id, $candidate->related_material_id);

        // 情報の案：チェックした項目だけを写す
        $this->get(route('materials.suggestions.index'))->assertOk()->assertSee('いちばんやさしいJavaScriptの教本 第3版');
        $this->get(route('materials.suggestions.show', ['id' => $research->id]))->assertOk()->assertSee('基礎を学べる入門書。');
        $this->post(route('materials.suggestions.apply', ['id' => $research->id]), $this->selected([
            'apply'  => ['topics', 'scenes', 'category_ids', 'published_on'],
            'values' => [
                'name' => 'いちばんやさしいJavaScriptの教本 第2版', 'summary' => '基礎を学べる入門書。', 'topics' => "JavaScript\nDOM\nイベント",
                'scenes' => ['systematic'], 'category_ids' => [$this->javascript->id], 'published_on' => '2020-03-19',
            ],
        ]))->assertRedirect(route('materials.suggestions.index'));

        $book->refresh();
        $this->assertSame(['JavaScript', 'DOM', 'イベント'], $book->topics);
        $this->assertSame(['systematic'], $book->scenes);
        $this->assertSame('2020-03-19', $book->published_on->toDateString());
        $this->assertNull($book->summary);
        $this->assertSame(SuggestionStatus::Accepted, $research->fresh()->status);

        // 新しい教材の候補：アフィリエイトのリンクが必要
        $this->post(route('materials.suggestions.register', ['id' => $candidate->id]), $this->selected([
            'values' => ['name' => 'いちばんやさしいJavaScriptの教本 第3版'],
        ]))->assertSessionHasErrors('affiliate_url');

        $this->post(route('materials.suggestions.register', ['id' => $candidate->id]), $this->selected([
            'values'     => ['name' => 'いちばんやさしいJavaScriptの教本 第3版', 'isbn' => '9784295099999', 'category_ids' => [$this->javascript->id], 'topics' => 'JavaScript'],
            'amazon_url' => '//af.moshimo.com/af/c/click?a_id=1&p_id=170&pc_id=185&pl_id=4062&url=https%3A%2F%2Fwww.amazon.co.jp%2Fdp%2F4295099999',
        ]))->assertRedirect();

        $third = Material::where('name', 'いちばんやさしいJavaScriptの教本 第3版')->sole();
        $this->assertSame($book->id, $third->previous_material_id);
        $this->assertSame(SuggestionStatus::Accepted, $candidate->fresh()->status);
        $this->assertSame($third->id, $candidate->fresh()->material_id);

        // 前の版を使っている記事は、見直しの対象になる
        $record = ArticleMaterial::where('material_id', $book->id)->sole();
        $this->assertSame('新しい版を登録した', $this->reason($record));
    }

    public function test_discovery_saves_new_candidates_only(): void
    {
        $this->registerDetected();

        $this->post(route('materials.discover.store'), $this->selected([
            'execution_method' => 'manual', 'category_id' => $this->event->id, 'kind' => 'udemy', 'count' => 3,
        ]))->assertRedirect();

        $generation = AiGeneration::sole();
        $this->assertStringContainsString('addEventListenerの使い方', $generation->input);
        $this->assertStringContainsString('教材の種類：Udemy', $generation->input);
        $this->assertStringContainsString('超JavaScript 完全ガイド 2026', $generation->input);
        $this->assertStringNotContainsString('カテゴリの値', $generation->input);

        $output = json_encode(['candidates' => [
            ['kind' => 'udemy', 'name' => 'JavaScriptイベント処理入門', 'product_url' => 'https://www.udemy.com/course/js-event/', 'topics' => ['JavaScript', 'イベント'], 'levels' => ['beginner'], 'reason' => 'イベントに特化'],
            ['kind' => 'udemy', 'name' => '超JavaScript 完全ガイド 2026', 'reason' => '登録済み'],
            ['kind' => 'book', 'name' => '種類が違う本', 'reason' => '種類が違う'],
        ], 'reason' => '初心者向け'], JSON_UNESCAPED_UNICODE);
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => $output, 'model' => 'gpt-6-luna']))->assertRedirect();

        $candidate = MaterialSuggestion::sole();
        $this->assertSame('JavaScriptイベント処理入門', $candidate->name);
        $this->assertSame(MaterialKind::Udemy, $candidate->kind);
        // AIがカテゴリを示さなかった場合は、探したカテゴリにする
        $this->assertSame([$this->event->id], $candidate->data['category_ids']);
        $this->get(route('materials.suggestions.show', ['id' => $candidate->id]))->assertOk()->assertSee('アフィリエイトのリンク');
    }

    public function test_matcher_and_review_flow(): void
    {
        $this->registerDetected();
        $udemy = Material::where('kind', MaterialKind::Udemy)->sole();
        $udemy->update(['topics' => ['JavaScript', 'addEventListener'], 'scenes' => ['hands_on']]);
        $book = Material::where('kind', MaterialKind::Book)->sole();
        $book->categories()->sync([$this->javascript->id]);
        $book->update(['scenes' => ['systematic']]);
        $other = Material::create(['blog_id' => $this->blog->id, 'kind' => MaterialKind::Book, 'status' => MaterialStatus::Active, 'name' => 'Python入門', 'topics' => ['Python']]);

        // 一段目：カテゴリ（親のカテゴリを含む）・分野の語句で絞る。関係のない教材は候補にしない
        $candidates = app(MaterialMatcher::class)->candidates($this->blog, $this->post);
        $ids = array_map(fn ($row) => $row['material']->id, $candidates);
        $this->assertContains($book->id, $ids);
        $this->assertContains($udemy->id, $ids);
        $this->assertNotContains($other->id, $ids);

        // 親ロードマップでは紹介しない（今使っている教材だけ、見直しのために残す）
        ArticleManagement::create(['blog_id' => $this->blog->id, 'post_id' => $this->post->id, 'article_type' => 'parent_roadmap']);
        $roadmap = app(MaterialMatcher::class)->candidates($this->blog, $this->post);
        $this->assertTrue(collect($roadmap)->every(fn ($row) => $row['used'] && $row['score'] === 0));
        ArticleManagement::query()->delete();

        // 二段目：AIの見直し（手動実行）
        $this->post(route('materials.reviews.run'), $this->selected(['target' => "posts:{$this->post->id}", 'execution_method' => 'manual']))->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('教材ID ' . $book->id, $generation->input);
        $this->assertStringContainsString('候補にした理由：カテゴリ：JavaScript', $generation->input);
        $this->assertStringContainsString('5-6. 紹介する教材の選び方と数', $generation->input);

        $school = Material::where('kind', MaterialKind::School)->sole();
        $output = json_encode([
            'current'   => [
                ['material_id' => $book->id, 'judgment' => 'keep', 'replace_with' => null, 'reason' => '合っている'],
                ['material_id' => $school->id, 'judgment' => 'replace', 'replace_with' => 99999, 'reason' => '候補にない'],
                ['material_id' => 99999, 'judgment' => 'keep', 'reason' => 'ない教材'],
            ],
            'additions' => [['material_id' => $other->id, 'reason' => '追加']],
            'summary'   => 'おおむね合っている',
        ], JSON_UNESCAPED_UNICODE);
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), $this->selected(['output' => $output, 'model' => 'gpt-6-luna']))->assertRedirect();

        $review = ArticleMaterialReview::sole();
        $this->assertCount(2, $review->result['current']);
        // 差し替え先が使えない教材なら、「外す」にする
        $this->assertSame('remove', $review->result['current'][1]['judgment']);
        $this->assertSame($other->id, $review->result['additions'][0]['material_id']);
        $this->get(route('materials.reviews.index'))->assertOk()->assertSee('おおむね合っている')->assertSee('Python入門');

        // 確認したら、記事の教材を見直したことにする
        $record = ArticleMaterial::where('material_id', $book->id)->sole();
        $record->update(['reviewed_at' => now()->subYear()]);
        $book->update(['info_updated_at' => now()->subMonth()]);
        $this->assertNotNull($this->reason($record));
        $this->post(route('materials.reviews.confirm', ['id' => $review->id]), $this->selected())->assertRedirect();
        $this->assertSame(SuggestionStatus::Accepted, $review->fresh()->status);
        $this->assertNull($this->reason($record));
    }

    public function test_api_research_uses_web_search_and_rakuten(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => 10, 'services.rakuten.application_id' => 'rakuten-app-id']);
        $this->registerDetected();
        $book = Material::where('kind', MaterialKind::Book)->sole();

        $output = json_encode(['material' => ['name' => $book->name, 'topics' => ['JavaScript']], 'newer' => [], 'sources' => [], 'reason' => 'OK'], JSON_UNESCAPED_UNICODE);
        Http::fake([
            'app.rakuten.co.jp/*' => Http::response(['Items' => [
                ['title' => 'いちばんやさしいJavaScriptの教本', 'subTitle' => '人気講師が教える', 'author' => '岩田宇史', 'publisherName' => 'インプレス', 'salesDate' => '2020年03月19日', 'isbn' => '9784295005926', 'itemCaption' => '入門書', 'itemUrl' => 'https://books.rakuten.co.jp/rb/15827907/'],
            ]]),
            'api.openai.com/v1/responses' => Http::response([
                'id' => 'resp_1', 'status' => 'completed', 'model' => 'gpt-6-luna',
                'output' => [
                    ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'search', 'query' => 'いちばんやさしいJavaScriptの教本']],
                    ['type' => 'web_search_call', 'status' => 'completed'],
                    // ページを開く動作は、料金の対象ではないため数えない（D-31-01）
                    ['type' => 'web_search_call', 'status' => 'completed', 'action' => ['type' => 'open_page', 'url' => 'https://book.impress.co.jp/']],
                    ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $output]]],
                ],
                'usage' => ['input_tokens' => 10000, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens' => 2000, 'output_tokens_details' => ['reasoning_tokens' => 1000]],
            ]),
        ]);

        $this->post(route('materials.research', ['id' => $book->id]), $this->selected([
            'execution_method' => 'api', 'model' => 'gpt-6-luna', 'reasoning_effort' => 'medium', 'web_search' => '1',
        ]))->assertRedirect();

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'app.rakuten.co.jp') && ($request->data()['isbn'] ?? null) === '9784295005926' && (string) ($request->data()['formatVersion'] ?? '') === '2');
        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.openai.com')
            && $request['tools'] === [['type' => 'web_search']]
            && $request['max_tool_calls'] === 8
            && str_contains($request['input'], '発売日 2020年03月19日')
            && ! str_contains($request['input'], 'rakuten-app-id'));

        $generation = AiGeneration::sole();
        $this->assertTrue($generation->use_web_search);
        $this->assertSame(AiGenerationStatus::Succeeded, $generation->status);
        $this->assertSame(2, $generation->web_search_calls);
        // 料金：入力 10000×0.125（キャッシュの書き込み）+ 出力 2000×0.50（/1M）+ 検索 2回×0.01
        $this->assertEqualsWithDelta(0.00125 + 0.001 + 0.02, $generation->estimated_cost, 0.0001);
        $this->assertSame(1, MaterialSuggestion::count());
    }

    public function test_periodic_check_runs_only_when_enabled(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => 10, 'blogos.materials.check.daily_limit' => 2]);
        Queue::fake();
        $this->registerDetected();

        // 無効なら実行しない
        $this->artisan('materials:check')->assertSuccessful();
        Queue::assertNothingPushed();

        BlogAiSetting::create(['blog_id' => $this->blog->id, 'auto_model' => 'gpt-6-luna', 'auto_reasoning_effort' => 'medium', 'material_check_enabled' => true]);
        // 最近調べた教材は対象にしない
        Material::where('kind', MaterialKind::School)->update(['researched_at' => now()->subMonth()]);

        $this->artisan('materials:check')->assertSuccessful();
        Queue::assertPushed(RunAiApiJob::class, 2);
        $this->assertSame(2, AiGeneration::where('use_web_search', true)->whereNotNull('material_id')->count());
        $this->assertNotContains(Material::where('kind', MaterialKind::School)->value('id'), AiGeneration::pluck('material_id')->all());
    }
}
