<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\ScheduledTaskRun;
use App\Models\SyncRun;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * アクティビティログ（D-77）
 */
class ActivityLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_start_and_end_separately_in_time_order(): void
    {
        $this->actingAs(User::factory()->create());
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        $other = Blog::create(['home' => 'https://other.example.test', 'display_name' => 'Other Blog']);

        // 定期実行の開始 → 同期の開始・終了 → 定期実行の終了（時系列で追えるよう、開始と終了を別の行にする）
        ScheduledTaskRun::create(['task_key' => 'blogs:sync', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now()->subMinutes(10), 'finished_at' => now()->subMinutes(2), 'processed_count' => 1]);
        SyncRun::create(['blog_id' => $blog->id, 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now()->subMinutes(8), 'finished_at' => now()->subMinutes(5)]);
        // 選択していないブログの作業は出さない
        SyncRun::create(['blog_id' => $other->id, 'trigger' => 'manual', 'status' => 'partial', 'started_at' => now()->subHour(), 'finished_at' => now()->subHour()]);
        // 直近7日間より前は、初めは出さない
        SyncRun::create(['blog_id' => $blog->id, 'trigger' => 'manual', 'status' => 'failed', 'started_at' => now()->subDays(10), 'finished_at' => now()->subDays(10)]);

        // WordPress のデータの変更（初めの「全て」に含める）
        $tag = Tag::create(['blog_id' => $blog->id, 'wordpress_id' => 5, 'name' => 'Java', 'slug' => 'java']);
        DB::table('tag_histories')->insert(['blog_id' => $blog->id, 'tag_id' => $tag->id, 'change_set_id' => 'set-1', 'field' => 'name', 'old_value' => 'java', 'new_value' => 'Java', 'source' => 'wp_sync', 'changed_at' => now()->subMinute()]);

        $response = $this->get(route('activities.index'))->assertOk()
            ->assertSee('<h1>アクティビティログ', false)
            ->assertSee('value="' . now('Asia/Tokyo')->subDays(6)->format('Y-m-d') . '"', false)
            ->assertSeeInOrder(['タグの変更『Java』（name）［同期で取り込み］', '定期実行を終了', 'WordPressとの同期を終了：成功（3分0秒）', 'WordPressとの同期を開始（毎日の同期）', '定期実行を開始'])
            ->assertSee(route('database.wordpress-records.show', ['table' => 'tags', 'id' => $tag->id]))
            ->assertDontSee('一部失敗')
            ->assertDontSee('WordPressとの同期を終了：失敗')
            ->assertDontSee('<th>ブログ</th>', false);

        // 種類の候補は、同じ期間に記録がある種類だけ
        $response->assertSee('<option value="schedule"', false)
            ->assertSee('<option value="wordpress"', false)
            ->assertDontSee('<option value="voice"', false);

        // 取得条件（期間）と表示条件（種類）のパネル。一覧の上に、どの条件の一覧かを出す
        $from = now('Asia/Tokyo')->subDays(6)->format('Y-m-d');
        $to = now('Asia/Tokyo')->format('Y-m-d');
        $response->assertSeeInOrder(['<h2 data-code="QUERY">取得条件</h2>', '>取得</button>', '<h2 data-code="FILTER">表示条件</h2>', '>絞り込む</button>'], false)
            ->assertSee("期間：{$from} 〜 {$to}（日本時間）／種類：全て");

        // 種類で絞る
        $this->get(route('activities.index', ['kind' => 'sync']))->assertOk()
            ->assertSee('WordPressとの同期を開始')
            ->assertSee('種類：同期')
            ->assertDontSee('定期実行を開始');

        // 期間を変えても、選んでいた種類の記録があれば、その種類で絞ったまま
        $wide = now('Asia/Tokyo')->subDays(30)->format('Y-m-d');
        $this->get(route('activities.index', ['from' => $wide, 'kind' => 'sync']))->assertOk()
            ->assertSee('WordPressとの同期を終了：失敗')
            ->assertDontSee('定期実行を開始');

        // 選んでいた種類の記録がなくなったときは「全て」に戻す
        $old = now('Asia/Tokyo')->subDays(10)->format('Y-m-d');
        $this->get(route('activities.index', ['from' => $old, 'to' => $old, 'kind' => 'schedule']))->assertOk()
            ->assertSee('種類：全て')
            ->assertSee('WordPressとの同期を終了：失敗');

        // メニューの「履歴」から開ける
        $this->get(route('home'))->assertSee(route('activities.index'));
    }
}
