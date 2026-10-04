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
