<?php

namespace Tests\Feature\Quality;

use App\Enums\EvaluatorType;
use App\Enums\GoogleIndexCategory;
use App\Enums\ReevaluationReason;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\GoogleIndexStatus;
use App\Models\Post;
use App\Services\Quality\ReevaluationDetector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 自動の再評価の並び順：大きな問題がある記事を先にする（D-48）。
 */
class ReevaluationPriorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_serious_problems_come_first_then_reason_then_impressions(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);

        // すべて「品質基準の更新」（前の版の評価）で再評価の対象。点数・インデックス・表示回数が違う
        $make = function (int $id, ?float $score, ?array $typeFailures = null) use ($blog): Post {
            $post = Post::create(['blog_id' => $blog->id, 'wordpress_id' => $id, 'title_raw' => "記事{$id}", 'status' => 'publish',
                'link' => "https://blog.example.test/{$id}.html", 'normalized_path' => "/{$id}.html", 'wordpress_modified_gmt' => '2026-09-01 00:00:00']);
            if ($score !== null) {
                ArticleEvaluation::create(['blog_id' => $blog->id, 'post_id' => $post->id, 'evaluated_wordpress_modified_gmt' => '2026-09-01 00:00:00', 'evaluator_type' => EvaluatorType::Ai,
                    'quality_common_version' => '1.3.0', 'quality_profile' => 'si-note', 'quality_profile_version' => '1.4.0', 'score' => $score, 'type_failures' => $typeFailures]);
            }

            return $post;
        };
        $good = $make(1, 96.0);
        $structure = $make(2, 60.0);
        $broken = $make(3, 30.0);
        $notIndexed = $make(4, 96.0);
        $typeFailed = $make(5, 92.0, ['type.do_result']);
        $unevaluated = $make(6, null);
        $popular = $make(7, 96.0);

        GoogleIndexStatus::create(['blog_id' => $blog->id, 'post_id' => $notIndexed->id, 'url' => $notIndexed->link, 'category' => GoogleIndexCategory::Crawled]);
        // 3段目の中では、表示回数の多い記事を先に
        $date = now(config('blogos.display_timezone'))->subDays(5)->toDateString();
        DB::table('google_search_console_page_daily')->insert(['blog_id' => $blog->id, 'date' => $date, 'page_url' => $popular->link, 'page_url_hash' => sha1($popular->link),
            'post_id' => $popular->id, 'clicks' => 3, 'impressions' => 300, 'ctr' => 0.01, 'position' => 8]);

        $rows = app(ReevaluationDetector::class)->detect($blog);
        $order = array_map(fn ($row) => $row['article']->title_raw, $rows);

        // 1段目：全面改修が必要・インデックス未登録・★の ×（同じ理由の中では記事の順） → 2段目：未評価（理由の順で先）・構成の見直し → 3段目：表示回数の多い順
        $this->assertSame(['記事3', '記事4', '記事5', '記事6', '記事2', '記事7', '記事1'], $order);
        $this->assertSame(ReevaluationReason::Unevaluated, $rows[3]['reason']);
        $this->assertSame(['前回 30点（全面改修が必要）'], $rows[0]['priority_notes']);
        $this->assertSame(['インデックス未登録'], $rows[1]['priority_notes']);
        $this->assertSame(1, $rows[2]['tier']);
        $this->assertSame([], $rows[6]['priority_notes']);
    }
}
