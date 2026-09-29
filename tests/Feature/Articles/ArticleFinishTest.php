<?php

namespace Tests\Feature\Articles;

use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\ImageKind;
use App\Enums\ImageStatus;
use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Jobs\RunAiApiJob;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Category;
use App\Models\CategoryEyecatch;
use App\Models\Image;
use App\Models\Material;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\Articles\ArticleHtmlFinisher;
use App\Services\Push\ArticlePushService;
use App\Services\Push\PushException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * 記事改修・新規記事作成への HTML のルール・教材・画像・記事へのリンクの組み込み（D-34）。
 */
class ArticleFinishTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected Post $post;

    protected Material $book;

    protected Material $udemy;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.openai.key' => null]);
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->post = $this->createPost(24, 'JavaScriptとは？', 'publish', '<p>本文</p>');
        $this->createPost(50, '下書きの記事', 'draft', '<p>下書き</p>');

        $this->book = Material::create([
            'blog_id' => $this->blog->id, 'kind' => MaterialKind::Book, 'status' => MaterialStatus::Active, 'name' => 'いちばんやさしいJavaScriptの教本',
            'amazon_url' => 'https://af.moshimo.com/af/c/click?a_id=1&p_id=170&pc_id=185&pl_id=4062&url=https%3A%2F%2Fwww.amazon.co.jp%2Fdp%2F4295005924',
            'rakuten_url' => 'https://af.moshimo.com/af/c/click?a_id=2&p_id=54&pc_id=54&pl_id=616&url=https%3A%2F%2Fbooks.rakuten.co.jp%2Frb%2F15827907%2F',
            'topics' => ['JavaScript'], 'summary' => '基礎を学べる入門書',
        ]);
        $this->udemy = Material::create([
            'blog_id' => $this->blog->id, 'kind' => MaterialKind::Udemy, 'status' => MaterialStatus::Active, 'name' => '初心者のためのJavaScript 完全入門',
            'affiliate_url' => 'https://trk.udemy.com/AgEabK', 'topics' => ['JavaScript'], 'summary' => '動画で学ぶ入門講座',
        ]);

        $this->actingAs(User::factory()->create());
    }

    protected function createPost(int $wordpressId, string $title, string $status, string $content): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title, 'status' => $status, 'content_raw' => $content,
            'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    protected function uploadedImage(): Image
    {
        $media = Media::create(['blog_id' => $this->blog->id, 'wordpress_id' => 3400, 'source_url' => 'https://blog.example.test/wp-content/uploads/js-roles.png', 'width' => 1600, 'height' => 900]);

        return Image::create(['blog_id' => $this->blog->id, 'kind' => ImageKind::Diagram, 'status' => ImageStatus::Ready, 'title' => '役割の図', 'alt' => 'HTML・CSS・JavaScriptの役割', 'media_id' => $media->id]);
    }

    protected function sampleContent(int $imageId): string
    {
        return implode("\n", [
            '<div class="point-box">', '  <h2>この記事で分かること</h2>', '  <ul><li>JavaScriptとは</li></ul>', '</div>',
            '<p>導入文です。</p>',
            '[quads id=1]',
            '<h2>JavaScriptの基本</h2>', '<p>説明</p>', "<p>[[画像:{$imageId}]]</p>",
            '<h2>できること</h2>', '<p>詳しくは [[記事:24]] と [[記事:50]] を見てください。</p>',
            '<h2>Javaとの違い</h2>', '<p>説明</p>',
            '<h2>学び方</h2>', '<p>説明</p>',
            '[aws_book_box_beginner]',
            '<div class="book-box">', '  <h2>おすすめの学習書籍</h2>', '  <h3>いちばんやさしいJavaScriptの教本</h3>', '  <p>紹介文</p>', "  [[教材:{$this->book->id}]]", '</div>',
            '<div class="udemy-box">', '  <h2>動画で学びたい方へ（Udemy講座）</h2>', "  <p>[[教材:{$this->udemy->id}]]</p>", '</div>',
            '<div class="faq-box">', '  <h2>よくある質問（FAQ）</h2>', '  <div class="faq-item"><div class="faq-q"><p>質問</p></div></div>', '</div>',
            '<div class="summary-box">', '  <h2>まとめ</h2>', '  <p>まとめ</p>', '</div>',
            '<script type="application/ld+json">{"@type":"FAQPage"}</script>',
        ]);
    }

    public function test_finisher_replaces_placeholders_and_inserts_ads_and_pr_marks(): void
    {
        $image = $this->uploadedImage();
        $finished = app(ArticleHtmlFinisher::class)->finish($this->blog, $this->sampleContent($image->id));
        $html = $finished['content'];

        // 広告を含むことの表示は冒頭、PR の印は教材の枠の見出し
        $this->assertStringStartsWith('<p class="pr-note">本記事にはプロモーション（アフィリエイト広告）を含みます。</p>', $html);
        $this->assertStringContainsString('<h2>おすすめの学習書籍 <span class="pr-label">PR</span></h2>', $html);
        $this->assertStringContainsString('<h2>動画で学びたい方へ（Udemy講座） <span class="pr-label">PR</span></h2>', $html);

        // 目印の置き換え
        $this->assertStringContainsString('class="amazon-btn">Amazonで見る</a>', $html);
        $this->assertStringContainsString('class="rakuten-btn">楽天で見る</a>', $html);
        $this->assertStringContainsString('<p>→ <a href="https://trk.udemy.com/AgEabK" rel="nofollow sponsored">初心者のためのJavaScript 完全入門（Udemy）</a></p>', $html);
        $this->assertStringContainsString('<a href="/js/24.html">JavaScriptとは？</a>', $html);
        $this->assertStringContainsString('<!-- blogos:記事:50 -->下書きの記事<!-- /blogos -->', $html);
        $this->assertStringContainsString('<p><img src="https://blog.example.test/wp-content/uploads/js-roles.png" alt="HTML・CSS・JavaScriptの役割" width="1600" height="900" class="wp-image-3400"></p>', $html);
        $this->assertStringNotContainsString('[[', $html);

        // 古い書き方を外す
        $this->assertStringNotContainsString('application/ld+json', $html);
        $this->assertStringNotContainsString('[aws_book_box_beginner]', $html);
        $this->assertSame(1, substr_count($html, '[quads id=1]'));

        // 広告の位置：id=1 は最初の本文の H2 の前、id=2 は本文の中ほど、id=3 は FAQ の後（教材の枠の近くには置かない）
        $this->assertLessThan(strpos($html, '<h2>JavaScriptの基本</h2>'), strpos($html, '[quads id=1]'));
        $this->assertGreaterThan(strpos($html, '</ul>'), strpos($html, '[quads id=1]'));
        $this->assertGreaterThan(strpos($html, '<h2>できること</h2>'), strpos($html, '[quads id=2]'));
        $this->assertLessThan(strpos($html, '<h2>Javaとの違い</h2>'), strpos($html, '[quads id=2]'));
        $this->assertGreaterThan(strpos($html, 'よくある質問'), strpos($html, '[quads id=3]'));
        $this->assertLessThan(strpos($html, '<h2>まとめ</h2>'), strpos($html, '[quads id=3]'));
        $this->assertStringNotContainsString('[quads id=4]', $html);

        $notes = implode("\n", $finished['notes']);
        $this->assertStringContainsString('FAQ の構造化データ', $notes);
        $this->assertStringContainsString('テーマの教材のショートコードを 1件外しました', $notes);
        $this->assertStringContainsString('公開していない記事は、タイトルだけにしました', $notes);

        // 仕上げ直しても変わらない（広告・表示は入れ直す）
        $this->assertSame($html, app(ArticleHtmlFinisher::class)->finish($this->blog, $html)['content']);

        // 公開された記事は、仕上げ直すとリンクになる
        Post::where('wordpress_id', 50)->update(['status' => 'publish']);
        $this->assertStringContainsString('<a href="/js/50.html">下書きの記事</a>', app(ArticleHtmlFinisher::class)->finish($this->blog, $html)['content']);
    }

    public function test_plain_faq_summary_and_related_are_wrapped_in_frames(): void
    {
        $content = implode("\n", [
            '<p>導入文</p>',
            '<h2>学習の順番</h2>', '<p>説明</p>',
            '<h2>よくある質問</h2>',
            '<h3>どの順番で学べばよいですか？</h3>', '<p>基本から学びます。</p>',
            '<h3>期間はどれくらいですか？</h3>', '一律には言えません。',
            '<!-- ▼▼ まとめ ▼▼ -->',
            '<h2>まとめ：基礎から進もう</h2>', '<div class="summary-box">', '  <ul><li>要点</li></ul>', '</div>',
            '<h2>関連記事</h2>', '<ul><li>[[記事:24]]</li></ul>',
        ]);

        $finished = app(ArticleHtmlFinisher::class)->finish($this->blog, $content);
        $html = $finished['content'];

        $this->assertStringContainsString("<div class=\"faq-box\">\n  <h2>よくある質問</h2>", $html);
        $this->assertStringContainsString("<span class=\"faq-label\">Q2</span>\n      <p>期間はどれくらいですか？</p>", $html);
        $this->assertStringContainsString("<span class=\"faq-label-a\">A</span>\n      <p>一律には言えません。</p>", $html);
        // 次の部品の区切りのコメントは、FAQ の枠の外に残す
        $this->assertGreaterThan(strrpos(substr($html, 0, strpos($html, '<!-- ▼▼ まとめ ▼▼ -->')), '</div>'), strpos($html, '<!-- ▼▼ まとめ ▼▼ -->'));
        $this->assertStringContainsString("<div class=\"summary-box\">\n  <h2>まとめ：基礎から進もう</h2>", $html);
        $this->assertStringContainsString("<div class=\"related-box\">\n<h2>関連記事</h2>\n<ul><li><a href=\"/js/24.html\">JavaScriptとは？</a></li></ul>\n</div>", $html);
        // 広告の id=3 は、FAQ の枠の後（まとめの見出しと枠の間には入れない）
        $this->assertLessThan(strpos($html, '<!-- ▼▼ まとめ ▼▼ -->'), strpos($html, '[quads id=3]'));
        $this->assertGreaterThan(strpos($html, '期間はどれくらいですか？'), strpos($html, '[quads id=3]'));

        $notes = implode("\n", $finished['notes']);
        $this->assertStringContainsString('FAQ を、FAQ の枠（faq-box）の形に直しました', $notes);
        $this->assertStringContainsString('point-box）がありません', $notes);

        // 仕上げ直しても変わらない
        $this->assertSame($html, app(ArticleHtmlFinisher::class)->finish($this->blog, $html)['content']);
    }

    public function test_supplements_for_terms_not_in_the_article_are_reported(): void
    {
        $content = implode("\n", [
            '<p>JavaScriptはインタプリタ型の言語で、DOMを操作できます。</p>',
            '<div class="supplement-box"><strong>補足：インタプリタ型とは？</strong><br>上から順に読みながら動かす方式です。</div>',
            '<div class="supplement-box"><strong>補足：DOM（ドム）とは？</strong><br>ページの中身を JavaScript から読み書きできるように整理したものです。</div>',
            '<div class="supplement-box"><strong>補足：コンパイルとは？</strong><br>まとめて機械の言葉に直すことです。</div>',
        ]);

        $notes = implode("\n", app(ArticleHtmlFinisher::class)->finish($this->blog, $content)['notes']);

        $this->assertStringContainsString('本文に出てこない用語の補足があります：「コンパイル」', $notes);
        $this->assertStringNotContainsString('「インタプリタ型」', $notes);
        $this->assertStringNotContainsString('「DOM（ドム）」', $notes);
    }

    public function test_revision_output_creates_image_requests_and_blocks_push_until_resolved(): void
    {
        $this->post(route('ai.generations.store'), ['selected_blog_id' => $this->blog->id, 'mode' => 'revision', 'target' => "posts:{$this->post->id}"])->assertRedirect();
        $generation = AiGeneration::sole();
        // 指示文：HTMLのルール、記事の目印、教材の候補
        $this->assertStringContainsString('si-note：HTMLのルール', $generation->input);
        $this->assertStringContainsString('[[記事:24]] JavaScriptとは？', $generation->input);
        $this->assertStringContainsString("教材ID {$this->book->id}", $generation->input);
        $this->assertStringContainsString('=== 画像の依頼 ===', $generation->input);
        // 情報（学べる内容）を調べていない教材は、候補に入れない
        $unresearched = Material::create(['blog_id' => $this->blog->id, 'kind' => MaterialKind::Book, 'status' => MaterialStatus::Active, 'name' => '未調査のJavaScript本', 'topics' => ['JavaScript']]);
        $this->assertStringNotContainsString("教材ID {$unresearched->id}", app(\App\Services\Materials\MaterialMatcher::class)::describe(app(\App\Services\Materials\MaterialMatcher::class)->candidates($this->blog, $this->post)));

        $body = "<h2>仕組み</h2>\n<p>[[画像:新規1]]</p>\n<p>[[画像:新規2]]</p>\n<p>[[画像:新規3]]</p>\n<p>[[画像:新規4]]</p>";
        $requests = json_encode([
            ['key' => '新規1', 'kind' => 'diagram', 'title' => '処理の流れ', 'description' => 'クリックから表示までの流れ', 'alt' => '処理の流れの図', 'illustration_prompt' => null],
            ['key' => '新規2', 'kind' => 'screenshot', 'title' => 'アラートの表示', 'description' => 'ボタンを押してアラートが出た画面', 'alt' => 'アラートの画面'],
            ['key' => '新規3', 'kind' => 'illustration', 'title' => '例え話の絵', 'description' => '家の骨組み', 'alt' => '例え', 'illustration_prompt' => 'a simple house frame, no text'],
            ['key' => '新規4', 'kind' => 'illustration', 'title' => '2枚目の絵', 'description' => '上限を超える', 'alt' => '例え'],
        ], JSON_UNESCAPED_UNICODE);
        $output = "=== タイトル ===\nJavaScriptとは？\n=== メタディスクリプション ===\n説明\n=== 抜粋 ===\n\n=== 本文 ===\n{$body}\n=== 画像の依頼 ===\n```json\n{$requests}\n```\n=== 変更点 ===\n- 図を追加\n=== 自己評価 ===\nなし\n=== 確認が必要な点 ===\nなし";
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-sol'])
            ->assertSessionHasNoErrors();

        $draft = ArticleDraft::sole();
        $images = Image::where('article_draft_id', $draft->id)->orderBy('id')->get();
        $this->assertSame([ImageKind::Diagram, ImageKind::Screenshot, ImageKind::Illustration], $images->pluck('kind')->all());
        $this->assertSame('a simple house frame, no text', $images[2]->image_prompt);
        foreach ($images as $image) {
            $this->assertStringContainsString("[[画像:{$image->id}]]", $draft->content_raw);
        }
        // 上限を超えた依頼は作らず、目印が残る。APIキーがないため、図は作っていない
        $this->assertStringContainsString('[[画像:新規4]]', $draft->content_raw);
        $notes = implode("\n", $draft->finish_notes);
        $this->assertStringContainsString('イラストの依頼が上限（1件）を超えた', $notes);
        $this->assertStringContainsString('APIキーが設定されていないため、図解は作っていません', $notes);
        // 教材を紹介しなかったことを知らせる
        $this->assertStringContainsString('この編集案には、教材の紹介がありません', $notes);

        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertOk()
            ->assertSee('置き換えられていない目印があるため、反映できません')
            ->assertSee('撮影の依頼：ボタンを押してアラートが出た画面');

        // 目印が残っている間は反映しない
        try {
            app(ArticlePushService::class)->push($draft->fresh(), null);
            $this->fail('目印が残っているのに反映できました。');
        } catch (PushException $e) {
            $this->assertStringContainsString('置き換えられていない目印があります', $e->getMessage());
        }

        // 画像を WordPress に登録し、使わない目印を人が消してから置き換え直す
        $media = Media::create(['blog_id' => $this->blog->id, 'wordpress_id' => 3500, 'source_url' => 'https://blog.example.test/wp-content/uploads/a.png']);
        Image::whereIn('id', $images->pluck('id'))->update(['media_id' => $media->id, 'status' => ImageStatus::Ready]);
        $draft->update(['content_raw' => str_replace('<p>[[画像:新規4]]</p>', '', $draft->content_raw)]);
        $this->post(route('drafts.finish', ['id' => $draft->id]), ['selected_blog_id' => $this->blog->id])->assertRedirect(route('drafts.edit', ['id' => $draft->id]));
        $this->assertSame([], \App\Support\ArticlePlaceholders::remaining($draft->fresh()->content_raw));
        $this->assertSame(3, substr_count($draft->fresh()->content_raw, 'wp-image-3500'));
    }

    public function test_diagrams_are_designed_automatically_with_api_and_new_article_gets_eyecatch(): void
    {
        config(['services.openai.key' => 'sk-test-key', 'blogos.ai.api.monthly_budget_usd' => null]);
        Queue::fake();

        $category = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 10, 'name' => 'JavaScript']);
        $eyecatch = Media::create(['blog_id' => $this->blog->id, 'wordpress_id' => 777, 'source_url' => 'https://blog.example.test/wp-content/uploads/js.png']);
        CategoryEyecatch::create(['blog_id' => $this->blog->id, 'category_id' => $category->id, 'media_id' => $eyecatch->id]);

        $this->post(route('ai.generations.store'), [
            'selected_blog_id' => $this->blog->id, 'mode' => 'new_article', 'target_type' => '投稿', 'category_id' => $category->id,
            'main_keyword' => 'JavaScript イベント', 'execution_method' => 'manual',
        ])->assertRedirect();
        $generation = AiGeneration::sole();
        $this->assertStringContainsString('カテゴリ：JavaScript', $generation->input);
        $this->assertStringContainsString("教材ID {$this->book->id}", $generation->input);

        $requests = json_encode([['key' => '新規1', 'kind' => 'diagram', 'title' => 'イベントの流れ', 'description' => 'クリックから処理まで', 'alt' => '流れ']], JSON_UNESCAPED_UNICODE);
        $output = "=== タイトル ===\nイベントの基本\n=== スラッグ ===\nevent-basics\n=== メタディスクリプション ===\n説明\n=== 抜粋 ===\n\n=== 本文 ===\n<h2>流れ</h2>\n<p>[[画像:新規1]]</p>\n=== 画像の依頼 ===\n{$requests}\n=== 変更点 ===\nなし\n=== 自己評価 ===\nなし\n=== 確認が必要な点 ===\nなし";
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), ['selected_blog_id' => $this->blog->id, 'output' => $output, 'model' => 'gpt-6-sol'])
            ->assertSessionHasNoErrors();

        $draft = ArticleDraft::sole();
        $this->assertSame([10], $draft->wordpress_category_ids);
        $this->assertSame(777, (int) $draft->wordpress_featured_media_id);

        // 図解は、続けて API で SVG の図を作る（編集案の本文を記事として渡す）
        $image = Image::where('article_draft_id', $draft->id)->sole();
        $design = AiGeneration::where('purpose', AiMode::ImageDesign)->sole();
        $this->assertSame($image->id, $design->image_id);
        $this->assertSame(AiGenerationStatus::Running, $design->status);
        $this->assertStringContainsString('（svg にする）', $design->input);
        $this->assertStringContainsString("本文の [[画像:{$image->id}]] の位置に載せます", $design->input);
        Queue::assertPushed(RunAiApiJob::class, fn ($job) => $job->generationId === $design->id);
    }
}
