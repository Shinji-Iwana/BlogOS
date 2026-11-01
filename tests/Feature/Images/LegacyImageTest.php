<?php

namespace Tests\Feature\Images;

use App\Enums\DraftState;
use App\Enums\ImageKind;
use App\Enums\ImageSource;
use App\Enums\ImageStatus;
use App\Enums\PushState;
use App\Enums\SyncTrigger;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Image;
use App\Models\Media;
use App\Models\Post;
use App\Models\User;
use App\Services\Articles\DraftService;
use App\Services\Images\LegacyImageService;
use App\Services\Push\ArticlePushService;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * 古い画像（/wp-content/img/）の移し替え（D-90）：取り込み・確認・登録・置き換え（公開中の記事と作業中の編集案）・削除の一覧。
 */
class LegacyImageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Blog $blog;

    protected FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->user = User::factory()->create();
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);

        $this->wp = new FakeWordPress();
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['media'] = [FakeWordPress::media(30)];
        $this->wp->lists['posts'] = [
            FakeWordPress::post(100, ['content' => ['raw' => $this->content(), 'rendered' => '']]),
            FakeWordPress::post(101, ['content' => ['raw' => '<p><img src="/wp-content/img/b.png" alt=""></p>', 'rendered' => '']]),
        ];
        $this->wp->install();
        // サイトの古い画像（WordPress の REST API 以外）
        Http::fake(function (Request $request) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            return match ($path) {
                '/wp-content/img/JavaScript/a.png', '/wp-content/img/b.png' => Http::response($this->png()),
                '/wp-content/img/anim.gif' => Http::response('GIF89a'),
                default => Http::response('', 404),
            };
        });
        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);

        $this->actingAs($this->user);
    }

    protected function content(): string
    {
        return '<p><a href="/wp-content/img/JavaScript/a.png"><img src="/wp-content/img/JavaScript/a.png" alt="old" class="aligncenter"></a></p>'
            . "\n<p><img src=\"https://blog.example.test/wp-content/img/b.png\"></p>"
            . "\n<p><img src=\"https://other.example.com/wp-content/img/x.png\"></p>";
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    protected function png(): string
    {
        $image = imagecreatetruecolor(40, 20);
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    protected function article(int $wordpressId): Post
    {
        return Post::where('wordpress_id', $wordpressId)->sole();
    }

    protected function wpContent(int $id): string
    {
        return collect($this->wp->lists['posts'])->firstWhere('id', $id)['content']['raw'];
    }

    public function test_import_register_and_replace_in_published_article_and_active_drafts(): void
    {
        $post = $this->article(100);
        $shared = $this->article(101);
        $show = ['type' => 'posts', 'id' => $post->id];

        // 本文の古い画像を拾う（ほかのサイトの URL は拾わない）。同じ画像を使うほかの記事も出す
        $this->get(route('articles.show', $show))->assertOk()
            ->assertSee('古い画像の移し替え（2件）')
            ->assertSee('/wp-content/img/JavaScript/a.png')
            ->assertDontSee('other.example.com/wp-content/img/x.png</td>', false)
            ->assertSee($shared->title_raw);

        // まとめて取り込む。ほかの記事から同じファイルを取り込んでも、増えない
        $this->post(route('articles.legacy-images.import', $show), $this->selected())->assertSessionHas('status', '画像を 2件取り込みました。');
        $this->post(route('articles.legacy-images.import', ['type' => 'posts', 'id' => $shared->id]), $this->selected(['path' => '/wp-content/img/b.png']))->assertSessionHasNoErrors();
        $this->assertSame(2, Image::count());
        $a = Image::where('legacy_path', '/wp-content/img/JavaScript/a.png')->sole();
        $this->assertSame(ImageSource::Legacy, $a->source);
        $this->assertSame(ImageKind::Screenshot, $a->kind);
        $this->assertSame('a', $a->title);

        // 名前・alt・ファイル名を入れて確認済みにし、まとめて WordPress に登録する（b は確認済みでないため登録しない）
        $this->put(route('articles.legacy-images.update', $show + ['imageId' => $a->id]), $this->selected([
            'kind' => 'screenshot', 'title' => 'クリックの結果', 'alt' => 'ボタンを押すとメッセージが出た画面', 'filename' => 'JS Click Result',
        ]))->assertSessionHasNoErrors();
        $this->assertSame(ImageStatus::Ready, $a->fresh()->status);
        $this->post(route('articles.legacy-images.upload', $show), $this->selected())->assertSessionHas('status', 'WordPress のメディアに 1件登録しました。');
        $media = Media::findOrFail($a->fresh()->media_id);
        $this->assertSame('https://blog.example.test/wp-content/uploads/js-click-result.png', $media->source_url);
        $this->assertNull(Image::where('legacy_path', '/wp-content/img/b.png')->sole()->media_id);

        // 人が作業中の編集案（この記事と、同じ画像を使うほかの記事）
        $draft = app(DraftService::class)->createFromArticle($post, null, $this->user->id);
        $draft->update(['title_raw' => '直したタイトル']);
        $otherDraft = app(DraftService::class)->createFromArticle($shared, null, $this->user->id);

        $this->get(route('articles.legacy-images.confirm', $show))->assertOk()
            ->assertSee('/wp-content/img/JavaScript/a.png')
            ->assertSee($media->source_url)
            ->assertSee("編集案 #{$draft->id}");

        // 置き換える：公開中の記事は URL だけを反映する（作業中の編集案とは別に、すぐに）
        $this->post(route('articles.legacy-images.replace', $show), $this->selected())
            ->assertSessionHas('status', '公開中の記事の画像 1件を、メディアの画像に置き換えて反映しました。作業中の編集案 1件の URL も置き換えました。');

        $published = $this->wpContent(100);
        $this->assertStringContainsString("<a href=\"{$media->source_url}\"><img src=\"{$media->source_url}\" alt=\"ボタンを押すとメッセージが出た画面\" class=\"aligncenter wp-image-{$media->wordpress_id}\" width=\"100\" height=\"100\"></a>", $published);
        $this->assertStringNotContainsString('/wp-content/img/JavaScript/a.png', $published);
        // 登録していない画像と、ほかのサイトの画像はそのまま
        $this->assertStringContainsString('https://blog.example.test/wp-content/img/b.png', $published);
        $this->assertStringContainsString('https://other.example.com/wp-content/img/x.png', $published);
        // 反映したのは本文だけ（人の編集案のタイトルは反映しない）
        $this->assertSame('Title 100', collect($this->wp->lists['posts'])->firstWhere('id', 100)['title']['raw']);

        $internal = ArticleDraft::where('auto_reason', LegacyImageService::REASON)->sole();
        $this->assertSame(DraftState::Pushed, $internal->state);

        // 人の編集案も置き換わり、元の版が反映後の版に進むため、そのまま反映できる（競合にならない）
        $draft->refresh();
        $this->assertSame(DraftState::Editing, $draft->state);
        $this->assertStringContainsString($media->source_url, $draft->content_raw);
        $this->assertContains('古い画像（/wp-content/img/）を、メディアの画像に置き換えました：1件。', $draft->finish_notes);
        $this->assertSame($post->fresh()->wordpress_modified_gmt->format('Y-m-d H:i:s'), $draft->base_wordpress_modified_gmt->format('Y-m-d H:i:s'));
        $this->assertSame(PushState::Completed, app(ArticlePushService::class)->push($draft, $this->user->id)->fresh()->state);
        $this->assertSame('直したタイトル', collect($this->wp->lists['posts'])->firstWhere('id', 100)['title']['raw']);

        // ほかの記事の編集案は、登録していない画像（b）だけなので変えない
        $this->assertStringContainsString('/wp-content/img/b.png', $otherDraft->fresh()->content_raw);
    }

    public function test_replace_failure_discards_internal_draft_and_import_errors_are_shown(): void
    {
        $post = $this->article(100);
        $show = ['type' => 'posts', 'id' => $post->id];

        // 置き換えられる画像がない
        $this->post(route('articles.legacy-images.replace', $show), $this->selected())->assertSessionHasErrors('legacy');

        // 読み込めない・画像でないファイルは取り込まない
        $service = app(LegacyImageService::class);
        foreach (['/wp-content/img/missing.png', '/wp-content/img/anim.gif'] as $path) {
            try {
                $service->import($this->blog, $path, null);
                $this->fail('取り込めてしまった');
            } catch (\App\Services\Images\ImageException $e) {
                $this->assertStringContainsString($path, $e->getMessage());
            }
        }
        $this->assertSame(0, Image::count());

        // WordPress 側で記事が変わっていたら反映を止め、内部の編集案は破棄する
        $image = $service->import($this->blog, '/wp-content/img/JavaScript/a.png', null);
        $image->update(['media_id' => Media::where('wordpress_id', 30)->value('id'), 'status' => ImageStatus::Ready]);
        $index = array_search(100, array_column($this->wp->lists['posts'], 'id'), true);
        $this->wp->lists['posts'][$index]['modified_gmt'] = '2026-09-20T00:00:00';

        $this->post(route('articles.legacy-images.replace', $show), $this->selected())->assertSessionHasErrors('legacy');
        $this->assertSame(DraftState::Discarded, ArticleDraft::where('auto_reason', LegacyImageService::REASON)->sole()->state);
        $this->assertStringContainsString('/wp-content/img/JavaScript/a.png', $this->wpContent(100));
        $this->assertSame(0, \App\Models\SyncIssue::unresolved()->count());
    }

    public function test_deletion_list_excludes_files_still_used_anywhere(): void
    {
        $schema = 'blogos_testing_legacy';
        try {
            DB::statement("create database if not exists `{$schema}`");
        } catch (\Throwable) {
            $this->markTestSkipped('テスト用の DB を作れない');
        }
        $root = storage_path('framework/testing/legacy-' . uniqid());

        try {
            DB::statement("create table `{$schema}`.`wp_options` (option_id bigint unsigned primary key auto_increment, option_name varchar(191), option_value longtext)");
            DB::statement("create table `{$schema}`.`wp_posts` (ID bigint unsigned primary key auto_increment, post_content longtext, post_type varchar(20))");
            DB::table("{$schema}.wp_options")->insert([
                ['option_name' => 'home', 'option_value' => 'https://blog.example.test'],
                ['option_name' => 'widget_text', 'option_value' => 'a:1:{s:4:"text";s:40:"<img src=\"/wp-content/img/widget.png\">";}'],
            ]);
            DB::table("{$schema}.wp_posts")->insert([
                ['post_content' => '<img src="/wp-content/img/revision.png">', 'post_type' => 'revision'],
            ]);

            foreach (['JavaScript/a.png', 'b.png', 'widget.png', 'revision.png', 'theme.png', 'it\'s.png'] as $file) {
                File::ensureDirectoryExists(dirname("{$root}/wp-content/img/{$file}"));
                file_put_contents("{$root}/wp-content/img/{$file}", 'x');
            }
            File::ensureDirectoryExists("{$root}/wp-content/themes/si");
            file_put_contents("{$root}/wp-content/themes/si/style.css", '.logo { background: url(/wp-content/img/theme.png); }');

            $mediaId = Media::where('wordpress_id', 30)->value('id');
            foreach (['/wp-content/img/JavaScript/a.png', '/wp-content/img/b.png', '/wp-content/img/widget.png', '/wp-content/img/revision.png', '/wp-content/img/theme.png', '/wp-content/img/it\'s.png', '/wp-content/img/gone.png'] as $path) {
                Image::create(['blog_id' => $this->blog->id, 'kind' => ImageKind::Screenshot, 'status' => ImageStatus::Ready, 'title' => $path, 'legacy_path' => $path, 'media_id' => $mediaId]);
            }

            // WordPress のフォルダを読めないときは、一覧を出さない
            config(['blogos.backup.wordpress_path' => null]);
            $this->get(route('images.legacy'))->assertOk()->assertSee('一覧を出せません')->assertDontSee('rm -f');

            config(['blogos.backup.wordpress_path' => $root]);
            $response = $this->get(route('images.legacy'))->assertOk();
            // 記事の本文（a は記事100、b は記事100・101）・ウィジェット・テーマで使っているものは消さない。投稿の版だけで使っているものは消せる
            $response->assertSee("rm -f -- '{$root}/wp-content/img/revision.png'")
                ->assertSee("rm -f -- '{$root}/wp-content/img/it'\\''s.png'")
                ->assertDontSee("{$root}/wp-content/img/b.png'")
                ->assertDontSee("{$root}/wp-content/img/JavaScript/a.png'")
                ->assertDontSee("{$root}/wp-content/img/widget.png'")
                ->assertDontSee("{$root}/wp-content/img/theme.png'")
                ->assertDontSee('gone.png')
                ->assertSee('WordPress の DB：wp_options.option_value')
                ->assertSee('テーマ：si' . DIRECTORY_SEPARATOR . 'style.css')
                ->assertSee('BlogOS の記事「Title 101」');

            // 古い画像が残っている記事
            $response->assertSee('古い画像が残っている記事（2件）');
        } finally {
            DB::statement("drop database if exists `{$schema}`");
            File::deleteDirectory($root);
        }
    }
}
