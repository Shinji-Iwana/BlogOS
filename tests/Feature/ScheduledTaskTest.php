<?php

namespace Tests\Feature;

use App\Enums\SyncTrigger;
use App\Jobs\RunScheduledTaskJob;
use App\Models\Blog;
use App\Models\BlogCredential;
use App\Models\ScheduledTaskRun;
use App\Models\User;
use App\Services\Schedule\ScheduledTaskService;
use App\Services\Sync\SyncService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeWordPress;
use Tests\TestCase;

/**
 * 定期実行の確認・時刻の変更・今すぐ実行と、実行の記録（D-44）。
 */
class ScheduledTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create());
    }

    /**
     * @return array<string, string> 定期実行の名前 => cron の式（UTC）
     */
    protected function registered(): array
    {
        $schedule = new Schedule();
        app(ScheduledTaskService::class)->register($schedule);

        return collect($schedule->events())->mapWithKeys(fn ($event) => [$event->description => $event->expression])->all();
    }

    public function test_settings_change_the_schedule_and_warn_about_order(): void
    {
        // 既定（日本時間 3:00 = UTC 18:00、月曜 6:45 = UTC 日曜 21:45）
        $events = $this->registered();
        $this->assertCount(9, $events);
        $this->assertSame('0 3 * * *', $events['blogs:sync']);
        $this->assertSame('45 6 * * 1', $events['affiliate:check-links']);

        $this->get(route('scheduled-tasks.index'))->assertOk()->assertSee('WordPress との同期')->assertSee('まだ実行していません')
            ->assertDontSee('順番の注意');

        // 同期を 4:10 にし、WordPress の更新の確認を無効にする
        $this->put(route('scheduled-tasks.update', ['key' => 'blogs:sync']), ['frequency' => 'daily', 'time' => '04:10', 'enabled' => '1'])->assertSessionHasNoErrors();
        $this->put(route('scheduled-tasks.update', ['key' => 'wordpress:check-updates']), ['frequency' => 'daily', 'time' => '04:45'])->assertSessionHasNoErrors();
        // 古い記録の削除は、無効にしようとしても有効のまま
        $this->put(route('scheduled-tasks.update', ['key' => 'model:prune']), ['frequency' => 'weekly', 'weekday' => 0, 'time' => '02:00']);

        $events = $this->registered();
        $this->assertSame('10 4 * * *', $events['blogs:sync']);
        $this->assertArrayNotHasKey('wordpress:check-updates', $events);
        $this->assertSame('0 2 * * 0', $events['model:prune']);

        // Google の取得を、同期より前にすると注意が出る
        $this->put(route('scheduled-tasks.update', ['key' => 'google:fetch']), ['frequency' => 'daily', 'time' => '04:00', 'enabled' => '1'])
            ->assertSessionHasErrors('order');
        $this->get(route('scheduled-tasks.index'))->assertSee('順番の注意：「WordPress との同期」（04:10）が終わった後の時刻にしてください。');

        $this->put(route('scheduled-tasks.update', ['key' => 'blogs:sync']), ['frequency' => 'daily', 'time' => '25:00'])->assertSessionHasErrors('time');
    }

    public function test_runs_record_start_end_and_counts_including_queued_sync(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        BlogCredential::create(['blog_id' => $blog->id, 'username' => 'admin', 'secret' => 'secret']);
        $wp = new FakeWordPress();
        $wp->lists['users'] = [FakeWordPress::user(1)];
        $wp->lists['posts'] = [FakeWordPress::post(10), FakeWordPress::post(11)];
        $wp->install();
        app(SyncService::class)->run($blog, SyncTrigger::Initial);

        // 同期は Queue に登録し（テストでは同じ処理の中で動く）、同期が終わったときに記録を閉じる
        $run = app(ScheduledTaskService::class)->run('blogs:sync', 'scheduled', null, now()->startOfMinute());

        $this->assertSame('succeeded', $run->status);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(0, $run->pending_jobs);
        $this->assertSame(1, $run->blog_count);
        $this->assertGreaterThanOrEqual(2, $run->processed_count);
        $this->assertSame(0, $run->changed_count);
        $this->assertStringContainsString('[同期を登録しました] https://blog.example.test', $run->output);
        $this->assertStringContainsString('[同期] https://blog.example.test：成功', $run->output);
        $this->assertNotNull($run->peak_memory_mb);

        $this->get(route('scheduled-tasks.index', ['task' => 'blogs:sync']))->assertOk()->assertSee('処理 ' . $run->processed_count . '件');
    }

    public function test_failed_run_is_recorded_and_shown_on_dashboard(): void
    {
        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        // 料金表の照合で、公式のページを読めない（コマンドは失敗で終わる）
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $run = app(ScheduledTaskService::class)->run('ai:check-prices', 'scheduled');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('終了コード 1', $run->error);
        $this->get(route('home'))->assertOk()->assertSee('前回が失敗した定期実行があります（AI の料金表の照合）');

        // 26時間以上、定期実行が動いていなければ、cron の停止を知らせる
        ScheduledTaskRun::query()->update(['started_at' => now()->subHours(30)]);
        $this->get(route('home'))->assertSee('26時間以上、定期実行が動いていません');
    }

    public function test_run_now_dispatches_job_except_for_paid_tasks(): void
    {
        Queue::fake();

        $this->post(route('scheduled-tasks.run', ['key' => 'model:prune']))->assertSessionHas('status');
        Queue::assertPushed(RunScheduledTaskJob::class, fn ($job) => $job->taskKey === 'model:prune');

        // 料金がかかる定期実行は、今すぐ実行できない
        $this->post(route('scheduled-tasks.run', ['key' => 'ai:auto-reevaluate']))->assertNotFound();

        // 実行中なら重ねない
        ScheduledTaskRun::create(['task_key' => 'google:inspect-index', 'trigger' => 'scheduled', 'status' => 'running', 'started_at' => now()]);
        $this->post(route('scheduled-tasks.run', ['key' => 'google:inspect-index']))->assertSessionHasErrors('run');
    }

    public function test_prune_counts_deleted_records(): void
    {
        $run = app(ScheduledTaskService::class)->run('model:prune', 'manual', auth()->id());

        $this->assertSame('succeeded', $run->status);
        $this->assertSame('manual', $run->trigger);
        $this->assertNotNull($run->duration_seconds);
    }
}
