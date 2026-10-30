<?php

namespace Tests\Feature;

use App\Models\Backup;
use App\Models\Blog;
use App\Models\Notice;
use App\Models\User;
use App\Services\Schedule\ScheduledTaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;
use ZipArchive;

/**
 * 月1回のバックアップ（D-89）。サーバーの中に作り、トップページに1回だけ「作成しました」を出し、XServer情報からダウンロードする。
 */
class BackupTest extends TestCase
{
    use RefreshDatabase;

    protected string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = storage_path('framework/testing/backup-' . uniqid());
        File::ensureDirectoryExists($this->root . '/wordpress/wp-content/uploads');
        file_put_contents($this->root . '/wordpress/wp-content/uploads/photo.jpg', 'image');
        file_put_contents($this->root . '/wordpress/wp-config.php', "<?php define('DB_PASSWORD', 'wp-secret');");
        file_put_contents($this->root . '/.blogos-htpasswd', 'admin:$apr1$hash');

        config([
            'blogos.backup.path'               => $this->root . '/backups',
            'blogos.backup.passphrase'         => 'correct-horse-battery',
            'blogos.backup.wordpress_database' => 'wp_example',
            'blogos.backup.wordpress_path'     => $this->root . '/wordpress',
            'blogos.backup.secret_files'       => [$this->root . '/.blogos-htpasswd'],
        ]);

        // mysqldump は結果のファイルを書き、tar はまとめたファイルを作ったことにする
        Process::fake(function (PendingProcess $process) {
            $command = $process->command;
            if (str_contains($command[0], 'mysqldump')) {
                $file = substr(collect($command)->first(fn ($arg) => str_starts_with($arg, '--result-file=')), strlen('--result-file='));
                file_put_contents($file, "CREATE TABLE wp_posts (ID int);\n-- Dump completed on 2026-11-01\n");
            } else {
                file_put_contents($command[2], 'tar.gz');
            }

            return Process::result();
        });

        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_backup_is_created_announced_once_downloaded_and_old_ones_are_removed(): void
    {
        $this->artisan('backup:create')->assertSuccessful();

        $backup = Backup::sole();
        $this->assertSame(Backup::SUCCEEDED, $backup->status);
        $this->assertSame(['wordpress_db', 'blogos_db', 'wp_content', 'secrets'], array_column($backup->files, 'key'));
        $this->assertFileExists($backup->directory() . '/wordpress-db.sql.gz');
        $this->assertStringContainsString('Dump completed', gzdecode(file_get_contents($backup->directory() . '/wordpress-db.sql.gz')));
        // 接続の情報の一時的なファイルは残さない
        $this->assertSame([], glob($this->root . '/backups/.client-*'));
        // WordPress の DB の名前で書き出す。パスワードはコマンドの引数に出さない
        Process::assertRan(fn (PendingProcess $process) => in_array('wp_example', $process->command, true)
            && str_starts_with($process->command[1], '--defaults-extra-file=')
            && ! collect($process->command)->contains(fn ($arg) => str_starts_with($arg, '--password') || str_starts_with($arg, '-p')));

        // 秘密の情報は、全て AES-256 で暗号化され、パスワードがなければ読めない
        $zip = new ZipArchive();
        $zip->open($backup->directory() . '/secrets.zip');
        $this->assertSame(3, $zip->numFiles);
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $this->assertSame(ZipArchive::EM_AES_256, $zip->statIndex($i)['encryption_method']);
        }
        $this->assertFalse($zip->getFromName('wp-config.php'));
        $zip->setPassword('correct-horse-battery');
        $this->assertStringContainsString('wp-secret', $zip->getFromName('wp-config.php'));
        $zip->close();

        // トップページに1回だけ「作成しました」（緑）。作ったばかりなので、お知らせはない
        $this->get(route('home'))->assertOk()->assertSee('バックアップを作成しました');
        $this->get(route('home'))->assertOk()->assertDontSee('バックアップを作成しました');
        $this->assertSame(0, Notice::where('kind', 'backup')->count());

        // 7日たってもダウンロードしていない：注意
        $this->travel(8)->days();
        $this->get(route('home'))->assertOk();
        $notice = Notice::where('kind', 'backup')->sole();
        $this->assertSame('warn', $notice->level);
        $this->assertStringContainsString('ダウンロードしていません', $notice->message);
        $this->get(route('server.index'))->assertOk()->assertSee('未ダウンロードあり');

        // 全てのファイルをダウンロードすると、ダウンロード済みになり、お知らせは解消する
        foreach (array_column($backup->files, 'key') as $key) {
            $this->get(route('server.backups.download', ['backup' => $backup->id, 'key' => $key]))->assertOk()
                ->assertDownload("blogos-backup-{$backup->name}-" . \App\Services\BackupService::FILES[$key]['file']);
        }
        $this->assertNotNull($backup->fresh()->downloaded_at);
        $this->get(route('home'))->assertOk();
        $this->assertNotNull($notice->fresh()->resolved_at);

        // 新しいものから3回分だけ残す（古いものは、ファイルと記録を消す）
        foreach (range(1, 3) as $i) {
            $this->travel(1)->minutes();
            $this->artisan('backup:create')->assertSuccessful();
        }
        $this->assertSame(3, Backup::count());
        $this->assertNull(Backup::find($backup->id));
        $this->assertDirectoryDoesNotExist($backup->directory());
    }

    public function test_missing_settings_fail_without_leaving_files_and_show_an_error(): void
    {
        config(['blogos.backup.passphrase' => 'short']);

        $this->artisan('backup:create')->assertFailed();

        $backup = Backup::sole();
        $this->assertSame(Backup::FAILED, $backup->status);
        $this->assertStringContainsString('BLOGOS_BACKUP_PASSPHRASE', $backup->message);
        $this->assertDirectoryDoesNotExist($backup->directory());

        $this->get(route('home'))->assertOk()->assertDontSee('バックアップを作成しました');
        $notice = Notice::where('kind', 'backup')->sole();
        $this->assertSame('error', $notice->level);
        $this->assertStringContainsString('バックアップの作成に失敗しました', $notice->message);
        $this->get(route('server.index'))->assertOk()->assertSee('BLOGOS_BACKUP_PASSPHRASE');
        // ファイルのない回は、ダウンロードできない
        $this->get(route('server.backups.download', ['backup' => $backup->id, 'key' => 'secrets']))->assertNotFound();
    }

    public function test_backup_runs_monthly_and_cannot_be_changed_to_daily(): void
    {
        $this->put(route('scheduled-tasks.update', ['key' => 'backup:create']), ['frequency' => 'monthly', 'monthday' => 15, 'time' => '06:00', 'enabled' => '1'])
            ->assertSessionHasNoErrors();
        $service = app(ScheduledTaskService::class);
        $this->assertSame(['frequency' => 'monthly', 'weekday' => null, 'monthday' => 15, 'time' => '06:00', 'enabled' => true], $service->setting('backup:create'));
        $this->assertSame(15, $service->nextRunAt('backup:create')->day);

        $this->put(route('scheduled-tasks.update', ['key' => 'backup:create']), ['frequency' => 'daily', 'time' => '06:00'])->assertSessionHasErrors('frequency');
        $this->put(route('scheduled-tasks.update', ['key' => 'backup:create']), ['frequency' => 'monthly', 'monthday' => 31, 'time' => '06:00'])->assertSessionHasErrors('monthday');
        // ほかの定期実行は、毎月にできない
        $this->put(route('scheduled-tasks.update', ['key' => 'blogs:sync']), ['frequency' => 'monthly', 'monthday' => 1, 'time' => '03:00'])->assertSessionHasErrors('frequency');
    }
}
