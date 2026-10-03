<?php

namespace Tests\Feature\Analytics;

use App\Enums\EvaluatorType;
use App\Enums\GoogleIndexCategory;
use App\Enums\Judgment;
use App\Models\Blog;
use App\Models\GoogleIndexStatus;
use App\Models\Post;
use App\Models\User;
use App\Services\Google\ArticlePerformanceService;
use App\Services\Quality\EvaluationService;
use App\Services\Quality\QualityStandardLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 記事の実績と次にやること（D-47 S4）。
 */
class ArticlePerformanceTest extends TestCase
{
    use RefreshDatabase;

    protected Blog $blog;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
    }

    protected function createPost(int $wordpressId, string $title): Post
    {
        return Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => $wordpressId, 'title_raw' => $title, 'status' => 'publish',
            'link' => "https://blog.example.test/js/{$wordpressId}.html", 'normalized_path' => "/js/{$wordpressId}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
    }

    /**
     * Search Console の記事ごとの1日分の行
     */
    protected function search(Post $post, string $date, int $clicks, int $impressions, float $position): void
    {
        DB::table('google_search_console_page_daily')->insert([
            'blog_id' => $this->blog->id, 'date' => $date, 'page_url' => $post->link, 'page_url_hash' => sha1($post->link . $date), 'post_id' => $post->id,
            'clicks' => $clicks, 'impressions' => $impressions, 'ctr' => $impressions > 0 ? $clicks / $impressions : 0, 'position' => $position,
        ]);
    }

    public function test_actions_are_derived_from_metrics_index_and_evaluation(): void
    {
        $lowCtr = $this->createPost(10, 'クリック率が低い記事');
        $nearTop = $this->createPost(11, '15位の記事');
        $gap = $this->createPost(12, '評価は高いのに選ばれない記事');
        $notIndexed = $this->createPost(13, '未登録の記事');
        $good = $this->createPost(14, '順調な記事');

        // 3位で表示500回・クリック5回（1%。目安 11% の半分より低い）
        $this->search($lowCtr, '2026-09-20', 5, 500, 3.0);
        $this->search($nearTop, '2026-09-20', 1, 80, 15.0);
        $this->search($gap, '2026-09-20', 2, 300, 2.0);
        $this->search($good, '2026-09-20', 60, 400, 2.0);
        // 前の期間（2026-08-24 より前）は多くクリックされていた → 減っている
        $this->search($good, '2026-08-10', 120, 500, 2.0);
        GoogleIndexStatus::create(['blog_id' => $this->blog->id, 'post_id' => $notIndexed->id, 'url' => $notIndexed->link, 'category' => GoogleIndexCategory::Discovered]);

        // 「評価は高いのに選ばれない記事」は、検索結果で選ばれるか（ctr）の適合度が高い
        $standard = app(QualityStandardLoader::class)->load('si-note');
        $judgments = array_fill_keys(array_keys($standard->items), Judgment::Good);
        app(EvaluationService::class)->save($this->blog, $gap, EvaluatorType::Ai, $judgments, [], null, null, null);

        $rows = collect(app(ArticlePerformanceService::class)->rows($this->blog, 28))->keyBy(fn ($row) => $row['article']->title_raw);
        $this->assertSame(['low_ctr'], $rows['クリック率が低い記事']['actions']);
        $this->assertSame(['near_top'], $rows['15位の記事']['actions']);
        $this->assertSame(['ctr_gap'], $rows['評価は高いのに選ばれない記事']['actions']);
        $this->assertSame(['not_indexed'], $rows['未登録の記事']['actions']);
        $this->assertSame(['dropping'], $rows['順調な記事']['actions']);
        $this->assertSame(0.01, $rows['クリック率が低い記事']['metrics']['ctr']);

        $this->get(route('analytics.performance'))->assertOk()->assertSee('2026-08-24〜2026-09-20')
            ->assertSee('クリック率が低い記事')->assertSee('評価は高いのに選ばれない記事');
        $this->get(route('analytics.performance', ['action' => 'near_top']))->assertOk()->assertSee('15位の記事')->assertDontSee('順調な記事</a>', false);
    }
}
