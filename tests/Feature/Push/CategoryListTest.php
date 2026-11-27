<?php

namespace Tests\Feature\Push;

use App\Enums\SyncTrigger;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\Category;
use App\Models\User;
use App\Services\Push\TermPushService;
use App\Services\Sync\SyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * カテゴリの一覧と、スラッグを変えるときの確認（D-53）。
 */
class CategoryListTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected FakeWordPress $wp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        BlogCredential::create(['blog_id' => $this->blog->id, 'username' => 'admin', 'secret' => 'secret']);

        // 親カテゴリ js（10）と子カテゴリ js-basic（11）。記事 100 は子、101 は親と子の両方
        $this->wp = new FakeWordPress();
        $this->wp->settings['default_category'] = 1;
        $this->wp->lists['users'] = [FakeWordPress::user(1)];
        $this->wp->lists['categories'] = [
            FakeWordPress::term(1, ['name' => '未分類', 'slug' => 'uncategorized']),
            FakeWordPress::term(10, ['name' => 'JavaScript', 'slug' => 'js', 'description' => 'JavaScript の基礎から応用まで']),
            FakeWordPress::term(11, ['name' => '基礎', 'slug' => 'js-basic', 'parent' => 10]),
        ];
        $this->wp->lists['posts'] = [
            FakeWordPress::post(100, ['categories' => [11], 'link' => 'https://blog.example.test/js/js-basic/100.html']),
            FakeWordPress::post(101, ['categories' => [10, 11], 'link' => 'https://blog.example.test/js/js-basic/101.html']),
        ];
        $this->wp->install();

        app(SyncService::class)->run($this->blog, SyncTrigger::Initial);
    }

    public function test_categories_are_listed_in_tree_order_with_post_counts(): void
    {
        $response = $this->get(route('categories.index'))->assertOk();

        $response->assertSeeInOrder(['JavaScript', 'JavaScript の基礎から応用まで', 'js', '基礎', 'js-basic', '未分類', '（未設定）']);
        $this->assertSame(1, app(\App\Services\Terms\CategoryTreeService::class)->tree($this->blog)->firstWhere('slug', 'js-basic')->depth);
    }

    public function test_edit_warns_how_many_post_urls_change_with_the_slug(): void
    {
        $parent = Category::where('wordpress_id', 10)->sole();

        // 親カテゴリのスラッグは、子カテゴリの記事の URL にも入る（記事 100・101 の2件。重複は1件）
        $this->get(route('terms.edit', ['type' => 'categories', 'id' => $parent->id]))->assertOk()
            ->assertSee('スラッグを変えると、URL が変わります。')
            ->assertSee('<strong>2件</strong>', false)
            ->assertSee('https://blog.example.test/js/js-basic/100.html');
    }

    /**
     * 「詳細」パネルと、編集・削除のポップアップ（D-128。確認画面 mock-term-modal）
     */
    public function test_detail_panel_and_popups(): void
    {
        $child = Category::where('wordpress_id', 11)->sole();
        $html = $this->get(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))->assertOk()->getContent();

        // 「詳細」パネル：右端に鉛筆（編集）、その右にごみ箱（削除）。表は親カテゴリ（親 -> 子の形）・名前・スラッグ（URL のリンク）・説明
        $this->assertMatchesRegularExpression('/<span class="panel-title-text">詳細<\/span>\s*<span class="panel-title-icons">\s*<button type="button" class="btn-secondary panel-icon-button" data-modal-open="term-edit-modal" title="編集".*?<button type="button" class="btn-secondary panel-icon-button panel-icon-delete" data-modal-open="term-delete-modal" title="削除"/su', $html);
        $this->assertMatchesRegularExpression('/<th>親カテゴリ<\/th>\s*<td\s*>JavaScript.*?<th>名前<\/th>\s*<td\s*>基礎.*?<th>スラッグ<\/th>\s*<td\s*>js-basic.*?<th>説明<\/th>/su', $html);
        // 前のパネル（入力欄の「情報を更新する」・「完全に削除する」）は、もうない
        $this->assertStringNotContainsString('<h2>情報を更新する</h2>', $html);
        $this->assertStringNotContainsString('<h2>完全に削除する</h2>', $html);

        // 編集のポップアップ：編集のモード、「更新」（WordPress のアイコン）。スラッグの警告は初めは隠す。送るときのブラウザの確認はない
        $this->assertStringContainsString('<div id="term-edit-modal" class="blog-switch-modal site-modal" data-modal-mode="edit" >', $html);
        $this->assertMatchesRegularExpression('/<div id="term-edit-modal".*?<span data-modal-submit-label>更新<\/span>/su', $html);
        $this->assertStringContainsString('id="slug-warning" style="margin-bottom:12px; display:none;"', $html);
        $this->assertStringContainsString("modal.dataset.modalMode === 'edit'", $html);
        $this->assertStringContainsString('submit.disabled = ! approved.checked || (changed && ! slugCheck.checked);', $html);
        $this->assertStringNotContainsString('本当に変更して、WordPress へ反映しますか', $html);
        $this->assertStringNotContainsString('にチェックを入れてください。\');', $html);

        // 削除のポップアップ：共通の削除のポップアップ。題名は「カテゴリ #WordPress ID」、表は親カテゴリ・名前・スラッグ
        $this->assertStringContainsString('id="term-delete-modal" class="blog-switch-modal site-modal" data-modal-mode="delete"', $html);
        $this->assertStringContainsString('<span class="modal-title-text">カテゴリ #11</span>', $html);
        $this->assertMatchesRegularExpression('/<table class="data delete-modal-target">\s*<tr>\s*<th>親カテゴリ<\/th>\s*<td>\s*JavaScript\s*<\/td>.*?<th>名前<\/th>\s*<td>\s*基礎\s*<\/td>.*?<th>スラッグ<\/th>\s*<td>\s*js-basic\s*<\/td>\s*<\/tr>\s*<\/table>/su', $html);
        $this->assertStringContainsString('data-expected="11"', $html);
        $this->assertStringContainsString('<input type="hidden" name="_method" value="DELETE">', $html);

        // 削除：ID が違うと、画面に戻ってエラーを出す。合えば（前後の空白は無視）消す
        $this->from(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))
            ->delete(route('terms.destroy', ['type' => 'categories', 'id' => $child->id]), ['selected_blog_id' => $this->blog->id, 'confirm_id' => '10', 'confirmed' => 1])
            ->assertRedirect(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))->assertSessionHasErrors('confirm_id');
        $this->assertNull($child->fresh()->wordpress_deleted_at);
        $this->delete(route('terms.destroy', ['type' => 'categories', 'id' => $child->id]), ['selected_blog_id' => $this->blog->id, 'confirm_id' => ' 11 ', 'confirmed' => 1])
            ->assertSessionHasNoErrors()->assertRedirect();
        $this->assertNotNull($child->fresh()->wordpress_deleted_at);

        // 削除した後は、詳細は出すが、編集・削除のアイコンとポップアップは出さない
        $html = $this->get(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))->assertOk()->getContent();
        $this->assertStringContainsString('<span class="panel-title-text">詳細</span>', $html);
        $this->assertStringNotContainsString('panel-icon-button', $html);
        $this->assertStringNotContainsString('id="term-edit-modal"', $html);
    }

    /**
     * 編集のポップアップの入力の誤りで戻ったとき（D-128-03）
     */
    public function test_edit_popup_stays_open_on_error(): void
    {
        $child = Category::where('wordpress_id', 11)->sole();

        // 入力の誤りで戻ったときは、編集のポップアップを開いたままにして、中に誤りを出す（ブログの登録と同じ形。D-128-03）
        $base = app(TermPushService::class)->currentValues($child);
        $this->from(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))
            ->put(route('terms.update', ['type' => 'categories', 'id' => $child->id]), [
                'selected_blog_id' => $this->blog->id, '_form' => 'term-edit', 'base' => $base, 'values' => ['name' => '基礎2'] + $base,
            ])->assertRedirect(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))->assertSessionHasErrors('approved')
            ->assertSessionHasInput('_form', 'term-edit');
        // スラッグを変えて、スラッグの確認のチェックがないとき（コントローラーが誤りを返す）。戻った画面で、ポップアップを開いたままにする
        $this->from(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))
            ->put(route('terms.update', ['type' => 'categories', 'id' => $child->id]), [
                'selected_blog_id' => $this->blog->id, '_form' => 'term-edit', 'base' => $base, 'approved' => '1', 'values' => ['slug' => 'js-basic2'] + $base,
            ])->assertRedirect(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))->assertSessionHasErrors('slug_change_confirmed');
        // 戻った画面：画面のデータに、戻したときの誤りと入力（_form を含む）を足して描く
        // （テストの続けての要求では、戻したときの誤りが次の画面に届かないため、画面だけを描いて確かめる）
        $data = $this->get(route('terms.edit', ['type' => 'categories', 'id' => $child->id]))->assertOk()->original->getData();
        $session = app('session.store');
        $session->put('_old_input', ['_form' => 'term-edit', 'base' => $base, 'approved' => '1', 'values' => ['slug' => 'js-basic2'] + $base]);
        request()->setLaravelSession($session);
        $errors = (new \Illuminate\Support\ViewErrorBag())->put('default', new \Illuminate\Support\MessageBag(['slug_change_confirmed' => ['スラッグを変えると URL が変わります。影響を確認し、「URL が変わることを理解したうえで変更します」にチェックを入れてください。']]));
        $html = view('terms.edit', ['errors' => $errors] + $data)->render();
        $this->assertMatchesRegularExpression('/id="term-edit-modal" class="[^"]*" data-modal-mode="edit"\s+data-modal-autoopen/', $html);
        $this->assertMatchesRegularExpression('/<ul class="text-error">\s*<li>スラッグを変えると URL が変わります。/u', $html);
        $this->assertStringContainsString('name="values[slug]" value="js-basic2"', $html);
        // 誤りは、画面の上には出さない（ポップアップの中だけ）
        $this->assertSame(1, substr_count($html, 'スラッグを変えると URL が変わります。影響を確認し'));
    }

    public function test_slug_change_requires_confirmation(): void
    {
        $parent = Category::where('wordpress_id', 10)->sole();
        $base = app(TermPushService::class)->currentValues($parent);
        $form = ['selected_blog_id' => $this->blog->id, 'base' => $base, 'approved' => '1'];

        // 確認のチェックがなければ、反映しない
        $this->put(route('terms.update', ['type' => 'categories', 'id' => $parent->id]), $form + ['values' => ['slug' => 'javascript'] + $base])
            ->assertSessionHasErrors('slug_change_confirmed');
        $this->assertSame('js', $this->wp->lists['categories'][1]['slug']);

        // 確認のチェックがあれば、反映する
        $this->put(route('terms.update', ['type' => 'categories', 'id' => $parent->id]), $form + ['values' => ['slug' => 'javascript'] + $base, 'slug_change_confirmed' => '1'])
            ->assertRedirect();
        $this->assertSame('javascript', $this->wp->lists['categories'][1]['slug']);
    }

    public function test_other_changes_do_not_need_slug_confirmation(): void
    {
        $parent = Category::where('wordpress_id', 10)->sole();
        $base = app(TermPushService::class)->currentValues($parent);

        $this->put(route('terms.update', ['type' => 'categories', 'id' => $parent->id]), [
            'selected_blog_id' => $this->blog->id, 'base' => $base, 'approved' => '1', 'values' => ['description' => '新しい説明'] + $base,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('新しい説明', $this->wp->lists['categories'][1]['description']);
    }
}
