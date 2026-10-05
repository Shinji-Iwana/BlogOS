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

        $this->get(route('scheduled-tasks.index'))->assertOk()->assertSee('<strong>WordPressとの同期</strong>', false)->assertSee('まだ実行していません')
            ->assertDontSee('順番の注意')
            // いつ・有効は、メニューのポップアップで変える。画面の「いつ」の列はない（D-63-04）
            ->assertDontSee('<th>いつ</th>', false)
            ->assertSee('<th>設定</th>', false)
            ->assertSee('<button type="button" class="btn-secondary" data-modal-open="scheduled-blogs-sync-modal">設定</button>', false)
            ->assertDontSee('設定を変える')
            // 名前は、メニューとポップアップの題名と同じ（D-63-05）
            ->assertSee('<strong>Googleとの同期</strong>', false)
            ->assertDontSee('<strong>Google のデータの取得</strong>', false);

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
    public function test_sync_schedule_can_be_changed_from_menu_popup(): void
    {
        // メニューの「設定 → 定期実行 → WordPressとの同期」で、ポップアップを開く。今すぐ実行のボタンはない（D-63）
        $html = $this->get(route('home'))->assertOk()
            ->assertSee('data-modal-open="scheduled-blogs-sync-modal"', false)
            ->assertSee('id="scheduled-blogs-sync-modal"', false)
            ->getContent();
        preg_match('/id="scheduled-blogs-sync-modal".*?<\/form>/s', $html, $modal);
        $this->assertStringContainsString(route('scheduled-tasks.update', ['key' => 'blogs:sync']), $modal[0]);
        $this->assertStringContainsString('name="enabled"', $modal[0]);
        $this->assertStringNotContainsString('今すぐ実行</button>', $modal[0]);

        // 保存した後は、開いていた画面に戻る
        $this->from(route('drafts.index'))->put(route('scheduled-tasks.update', ['key' => 'blogs:sync']), ['_form' => 'scheduled-blogs-sync-modal', 'frequency' => 'daily', 'time' => '05:20'])
            ->assertRedirect(route('drafts.index'));
        $this->assertSame(['frequency' => 'daily', 'weekday' => null, 'time' => '05:20', 'enabled' => false], app(\App\Services\Schedule\ScheduledTaskService::class)->setting('blogs:sync'));

        // 入力の誤りで戻ったときは、ポップアップを開いたままにする
        $this->from(route('home'))->put(route('scheduled-tasks.update', ['key' => 'blogs:sync']), ['_form' => 'scheduled-blogs-sync-modal', 'frequency' => 'daily', 'time' => '25:00']);
        $this->assertMatchesRegularExpression('/id="scheduled-blogs-sync-modal"[^>]*data-modal-autoopen/', $this->get(route('home'))->getContent());
    }
    public function test_menu_has_popups_for_scheduled_tasks(): void
    {
        // メニューの「設定 → 定期実行」に6つ（D-63）。料金がかかる2つと、提携先のリンクの確認は、まだ出さない
        $html = $this->get(route('home'))->assertOk()->getContent();
        foreach (['blogs-sync' => 'WordPressとの同期', 'model-prune' => '古い記録の削除', 'ai-check-prices' => 'AI料金表の照合',
            'wordpress-check-updates' => 'WordPressの更新確認', 'google-fetch' => 'Googleとの同期', 'google-inspect-index' => 'Googleのインデックス確認'] as $id => $label) {
            $this->assertStringContainsString("data-modal-open=\"scheduled-{$id}-modal\" >{$label}</a>", $html);
            $this->assertStringContainsString("id=\"scheduled-{$id}-modal\"", $html);
        }
        // 記事の再評価は、専用のポップアップ（D-64）。その下に、アフィリエイト提携先との同期。どちらも教材の照合の上
        $this->assertMatchesRegularExpression('/>Googleのインデックス確認<\/a>.*?data-modal-open="scheduled-ai-auto-reevaluate-modal" >記事の再評価<\/a>'
            . '.*?data-modal-open="scheduled-affiliate-check-links-modal" >アフィリエイト提携先との同期<\/a>.*?>教材の照合<\/a>/s', $html);
        $this->assertStringContainsString('アフィリエイト提携先との同期（定期実行）', $html);

        // 古い記録の削除：有効は、チェックしたまま変えられない（送らない）
        preg_match('/id="scheduled-model-prune-modal".*?<\/form>/s', $html, $prune);
        $this->assertStringContainsString('<input type="checkbox" checked disabled>', $prune[0]);
        $this->assertStringNotContainsString('name="enabled"', $prune[0]);
        $this->assertStringContainsString('この定期実行は止められません', $prune[0]);

        // 時刻は変えられ、有効のまま
        $this->put(route('scheduled-tasks.update', ['key' => 'model:prune']), ['_form' => 'scheduled-model-prune-modal', 'frequency' => 'daily', 'time' => '02:30'])->assertSessionHasNoErrors();
        $this->assertSame(['frequency' => 'daily', 'weekday' => null, 'time' => '02:30', 'enabled' => true], app(\App\Services\Schedule\ScheduledTaskService::class)->setting('model:prune'));
    }

    public function test_material_check_popup_saves_time_and_blog_setting(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);

        // メニューの「定期実行」の「AI料金表の照合」の上に「教材の照合」（教材の定期チェック）。有効は、選択中のブログの設定（D-63-03）
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>Googleのインデックス確認<\/a>.*?>教材の照合<\/a>.*?>AI料金表の照合<\/a>/s', $html);
        preg_match('/id="scheduled-materials-check-modal".*?<\/form>/s', $html, $modal);
        $this->assertStringContainsString(route('scheduled-tasks.update-blog', ['key' => 'materials:check']), $modal[0]);
        $this->assertStringContainsString('name="enabled" value="1" > 有効（Example Blog）', $modal[0]);

        // AIの設定の画面には、もうない
        $this->get(route('ai.settings.edit'))->assertOk()->assertDontSee('教材の定期チェックを有効にする');

        // いつと、ブログの有効を保存する。保存した後は、開いていた画面に戻る
        $this->from(route('drafts.index'))->put(route('scheduled-tasks.update-blog', ['key' => 'materials:check']), [
            'selected_blog_id' => $blog->id, '_form' => 'scheduled-materials-check-modal', 'frequency' => 'daily', 'time' => '07:10', 'enabled' => '1',
        ])->assertRedirect(route('drafts.index'))->assertSessionHas('status', fn ($status) => str_contains($status, 'Example Blog：有効'));
        $this->assertSame('07:10', app(ScheduledTaskService::class)->setting('materials:check')['time']);
        $this->assertTrue(app(\App\Repositories\BlogAiSettingRepository::class)->forBlog($blog)->material_check_enabled);

        // AIの設定を保存しても、教材の定期チェックは変わらない
        $this->put(route('ai.settings.update'), ['selected_blog_id' => $blog->id, 'auto_model' => 'gpt-6-luna', 'auto_reasoning_effort' => 'medium',
            'auto_revision_model' => 'gpt-6-luna', 'auto_revision_reasoning_effort' => 'medium'])->assertSessionHasNoErrors();
        $this->assertTrue(app(\App\Repositories\BlogAiSettingRepository::class)->forBlog($blog)->material_check_enabled);

        // 無効にする
        $this->put(route('scheduled-tasks.update-blog', ['key' => 'materials:check']), ['selected_blog_id' => $blog->id, 'frequency' => 'daily', 'time' => '07:10']);
        $this->assertFalse(app(\App\Repositories\BlogAiSettingRepository::class)->forBlog($blog)->material_check_enabled);
    }

    public function test_popups_show_next_and_last_run(): void
    {
        // WordPress との同期は前回あり（件数とリンクは出さない）、Google との同期は無効、古い記録の削除はまだ実行していない（D-63-06）
        ScheduledTaskRun::create(['task_key' => 'blogs:sync', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now()->subHour(),
            'finished_at' => now()->subHour()->addSeconds(95), 'duration_seconds' => 95, 'processed_count' => 120, 'changed_count' => 3]);
        $this->put(route('scheduled-tasks.update', ['key' => 'google:fetch']), ['frequency' => 'daily', 'time' => '05:00']);

        $html = $this->get(route('home'))->assertOk()->getContent();
        $popup = fn (string $id) => preg_match('/id="' . $id . '".*?<\/form>/s', $html, $m) ? $m[0] : '';

        $sync = $popup('scheduled-blogs-sync-modal');
        $this->assertMatchesRegularExpression('/次の実行：\d{4}-\d{2}-\d{2} 03:00/', $sync);
        $this->assertMatchesRegularExpression('/前回：\s*<span\s*>成功<\/span>\s*（\d{2}-\d{2} \d{2}:\d{2} 開始・1分35秒）/u', $sync);
        $this->assertStringNotContainsString('処理 120件', $sync);
        $this->assertStringNotContainsString('記録を見る', $sync);

        $this->assertStringContainsString('次の実行：無効', $popup('scheduled-google-fetch-modal'));
        $this->assertStringContainsString('まだ実行していません', $popup('scheduled-model-prune-modal'));
        // 記事の再評価（専用のポップアップ）にも出す
        $this->assertStringContainsString('次の実行：', $popup('scheduled-ai-auto-reevaluate-modal'));
    }
}
