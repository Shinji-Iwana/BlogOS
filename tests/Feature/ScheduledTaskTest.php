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

        // 画面「定期実行」はなくした（設定はメニューの「設定 → 定期実行」、今すぐ実行は「設定 → 即時実行」。D-63-10）
        $this->get('/scheduled-tasks')->assertNotFound();
        $this->get(route('home'))->assertOk()->assertDontSee('順番の注意');

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
        // 順番の注意は、設定のポップアップに出す
        $this->get(route('home'))->assertSee('順番の注意：「WordPress との同期」（04:10）が終わった後の時刻にしてください。');

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

        // トップページの定期実行のパネルから、定期実行履歴へ（D-63-10。前回が失敗した定期実行があるとき）
        \App\Models\ScheduledTaskRun::create(['task_key' => 'google:fetch', 'trigger' => 'scheduled', 'status' => 'failed', 'started_at' => now(), 'finished_at' => now(), 'pending_jobs' => 0, 'error' => 'テスト']);
        $this->get(route('home'))->assertSee('<a href="' . route('scheduled-tasks.runs') . '">定期実行履歴</a>', false);

        // 定期実行履歴（メニューの「履歴 → 定期実行履歴」）：定期実行ごとにしぼり込める
        $this->get(route('home'))->assertSee('href="' . route('scheduled-tasks.runs') . '" >定期実行履歴</a>', false);
        ScheduledTaskRun::create(['task_key' => 'model:prune', 'trigger' => 'scheduled', 'status' => 'succeeded', 'started_at' => now(), 'processed_count' => 777]);
        $this->get(route('scheduled-tasks.runs', ['task' => 'blogs:sync']))->assertOk()
            ->assertSee('<h1>定期実行履歴', false)
            ->assertSee('1年で削除します')
            ->assertSee('<option value="blogs:sync" selected>WordPressとの同期</option>', false)
            ->assertSee('<td>' . number_format($run->processed_count) . '</td>', false)
            ->assertDontSee('<td>777</td>', false);
        $this->get(route('scheduled-tasks.runs'))->assertOk()->assertSee('<td>777</td>', false);
    }

    public function test_failed_run_is_recorded_and_shown_on_dashboard(): void
    {
        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true]);
        // 料金表の照合で、公式のページを読めない（コマンドは失敗で終わる）
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 500)]);

        $run = app(ScheduledTaskService::class)->run('ai:check-prices', 'scheduled');

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('終了コード 1', $run->error);
        $this->get(route('home'))->assertOk()->assertSee('前回が失敗した定期実行があります（OpenAI API料金表との同期）');

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
        foreach (['blogs-sync' => 'WordPressとの同期', 'model-prune' => '古い記録の削除', 'ai-check-prices' => 'OpenAI API料金表との同期',
            'wordpress-check-updates' => 'WordPressの更新確認', 'google-fetch' => 'Googleとの同期', 'google-inspect-index' => 'Googleのインデックス確認'] as $id => $label) {
            $this->assertStringContainsString("data-modal-open=\"scheduled-{$id}-modal\" >{$label}</a>", $html);
            $this->assertStringContainsString("id=\"scheduled-{$id}-modal\"", $html);
        }
        // 記事の再評価は、専用のポップアップ（D-64）。アフィリエイト提携先との同期・教材情報の同期の下（D-63-25）
        $this->assertMatchesRegularExpression('/data-modal-open="scheduled-affiliate-check-links-modal" >アフィリエイト提携先との同期<\/a>.*?>教材情報の同期<\/a>'
            . '.*?data-modal-open="scheduled-ai-auto-reevaluate-modal" >記事の再評価<\/a>/s', $html);
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

        // メニューの「定期実行」の「アフィリエイト提携先との同期」の下に「教材情報の同期」（教材の定期チェック）。有効は、選択中のブログの設定（D-63-03・D-63-25）
        $html = $this->get(route('home'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/>OpenAI API料金表との同期<\/a>.*?>アフィリエイト提携先との同期<\/a>.*?>教材情報の同期<\/a>/s', $html);
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

    public function test_run_now_menu_runs_the_same_as_the_screen_button(): void
    {
        Queue::fake();

        // メニューの「設定 → 即時実行 → WordPressの更新確認」：確認してから、今すぐ実行と同じ処理を送る（D-63-09）
        $html = $this->get(route('home'))->assertOk()->getContent();
        $url = route('scheduled-tasks.run', ['key' => 'wordpress:check-updates']);
        $this->assertMatchesRegularExpression('/>即時実行<\/summary>.*?data-menu-post="' . preg_quote($url, '/') . '" data-menu-confirm="「WordPressの更新確認」を今すぐ実行しますか？">WordPressの更新確認<\/a>.*?>定期実行<\/summary>/s', $html);

        // 今すぐ実行のある定期実行を全て、メニューの「定期実行」と同じ順に出す（料金がかかる2つは出さない）
        preg_match('/>即時実行<\/summary>(.*?)<\/ul>/s', $html, $runNow);
        preg_match_all('/data-menu-confirm="[^"]*">([^<]+)<\/a>/', $runNow[1], $labels);
        $this->assertSame(['WordPressとの同期', 'WordPressの更新確認', 'Googleとの同期', 'Googleのインデックス確認', 'OpenAI API料金表との同期', 'アフィリエイト提携先との同期', '古い記録の削除'], $labels[1]);

        // メニューの「定期実行」の順（D-63-25）
        preg_match('/>定期実行<\/summary>(.*?)<\/ul>/s', $html, $schedule);
        preg_match_all('/data-modal-open="scheduled-[^"]*" >([^<]+)<\/a>/', $schedule[1], $labels);
        $this->assertSame(['WordPressとの同期', 'WordPressの更新確認', 'Googleとの同期', 'Googleのインデックス確認', 'OpenAI API料金表との同期', 'アフィリエイト提携先との同期', '教材情報の同期', '記事の再評価', '古い記録の削除'], $labels[1]);

        // 開いていた画面に戻り、Queue に登録する
        $this->from(route('drafts.index'))->post($url)->assertRedirect(route('drafts.index'))
            ->assertSessionHas('status', fn ($status) => str_contains($status, '「WordPressの更新確認」を Queue に登録しました'));
        Queue::assertPushed(RunScheduledTaskJob::class, fn ($job) => $job->taskKey === 'wordpress:check-updates');

        // 実行中なら、トップページに理由を出す
        ScheduledTaskRun::create(['task_key' => 'wordpress:check-updates', 'trigger' => 'manual', 'status' => 'running', 'started_at' => now()]);
        $this->from(route('home'))->post($url)->assertRedirect(route('home'));
        $this->get(route('home'))->assertSee('は実行中です。終わってから実行してください。');
    }
}
