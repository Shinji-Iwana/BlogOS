<?php

namespace Tests\Feature;

use App\Models\Blog;
use App\Models\EnvironmentCheck;
use App\Models\Notice;
use App\Models\User;
use App\Services\EnvironmentCheckService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

/**
 * サーバー環境の点検（D-88）。知らせるのは、セキュリティの修正が入っていない・部品の脆弱性（すぐ対応）と、サポートの終わりが近い（計画して対応）だけ。
 */
class EnvironmentCheckTest extends TestCase
{
    use RefreshDatabase;

    protected string $branch;

    protected int $patch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
        $this->patch = PHP_RELEASE_VERSION;
        Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
    }

    /**
     * @param array<string, array{tags: list<string>, days: int}> $newer 今より新しい PHP の版（版の末尾の数 => 印・何日前に出たか）
     */
    protected function fakeSources(array $newer, string $phpSupportUntil, string $laravelSupportUntil, array $advisories): void
    {
        $releases = ["{$this->branch}.{$this->patch}" => ['tags' => ['security'], 'date' => now()->subDays(60)->format('d M Y')]];
        foreach ($newer as $patch => $release) {
            $releases["{$this->branch}.{$patch}"] = ['tags' => $release['tags'], 'date' => now()->subDays($release['days'])->format('d M Y')];
        }

        Http::preventStrayRequests();
        Http::fake(function (Request $request) use ($releases, $phpSupportUntil, $laravelSupportUntil) {
            $url = $request->url();

            return match (true) {
                str_starts_with($url, 'https://www.php.net/releases/')                         => Http::response($releases),
                str_contains($url, 'endoflife.date/api/v1/products/php/releases/')             => Http::response(['result' => ['eolFrom' => $phpSupportUntil, 'latest' => ['name' => 'x']]]),
                str_contains($url, 'endoflife.date/api/v1/products/laravel/releases/')         => Http::response(['result' => ['eolFrom' => $laravelSupportUntil, 'latest' => ['name' => '13.99.0']]]),
                str_contains($url, 'endoflife.date/api/v1/products/')                          => Http::response(['result' => ['eolFrom' => '2023-10-31', 'latest' => ['name' => 'y']]]),
                default                                                                        => Http::response([], 404),
            };
        });
        Process::fake(['*' => Process::result(output: json_encode(['advisories' => $advisories, 'abandoned' => []]), exitCode: $advisories === [] ? 0 : 1)]);
    }

    public function test_missing_security_fix_vulnerable_packages_and_near_end_of_support_are_notified(): void
    {
        // 今より新しいセキュリティの修正版が10日前に出た（7日を過ぎても入っていない）。PHP のサポートは3か月後に終わる
        $this->fakeSources([$this->patch + 1 => ['tags' => ['security'], 'days' => 10]], now()->addMonths(3)->toDateString(), now()->addYears(2)->toDateString(),
            ['laravel/framework' => [['packageName' => 'laravel/framework', 'severity' => 'critical', 'title' => 'TokenGuard bypass', 'cve' => 'CVE-2026-114509', 'affectedVersions' => '<13.29.0', 'link' => 'https://example.test']]]);
        EnvironmentCheckService::rememberWebPhp();

        $this->artisan('environment:check')->assertSuccessful();

        $cli = EnvironmentCheck::where('target', 'php_cli')->sole();
        $this->assertSame(EnvironmentCheck::SECURITY, $cli->level);
        $this->assertSame("{$this->branch}." . ($this->patch + 1), $cli->latest_version);
        $this->assertStringContainsString("PHP {$this->branch} のセキュリティの修正版が 1件 入っていません（最新は {$this->branch}." . ($this->patch + 1), $cli->message);
        // サポートの終わりも、合わせて書く（状態は、すぐ対応のほうを優先）
        $this->assertStringContainsString('のサポート（セキュリティの修正）は', $cli->message);
        $this->assertSame(EnvironmentCheck::SECURITY, EnvironmentCheck::where('target', 'php_web')->value('level'));

        $packages = EnvironmentCheck::where('target', 'packages')->sole();
        $this->assertSame(EnvironmentCheck::SECURITY, $packages->level);
        $this->assertStringContainsString('脆弱性が 1件 あります（危険度：critical 1件）', $packages->message);
        $this->assertSame('CVE-2026-114509', $packages->details['advisories'][0]['cve']);

        // Laravel：サポートの終わりが遠い。新しい版（13.99.0）があるだけでは知らせない
        $laravel = EnvironmentCheck::where('target', 'laravel')->sole();
        $this->assertSame(EnvironmentCheck::OK, $laravel->level);
        $this->assertSame('13.99.0', $laravel->latest_version);

        // DB：参考（サポートが終わっていても、知らせない）
        $this->assertSame(EnvironmentCheck::INFO, EnvironmentCheck::where('target', 'database')->value('level'));

        // お知らせ（エラー）と、XServer情報の画面
        $this->get(route('home'))->assertOk();
        $notice = Notice::where('kind', 'environment')->sole();
        $this->assertSame('error', $notice->level);
        $this->assertStringContainsString('すぐ対応が必要なものがあります', $notice->message);
        $this->get(route('server.index'))->assertOk()
            ->assertSee('サーバー環境の点検')
            ->assertSee('すぐ対応')
            ->assertSee('TokenGuard bypass');
    }

    public function test_only_new_versions_and_fresh_fixes_are_not_notified(): void
    {
        // 新しい版はあるが、セキュリティの修正ではない。セキュリティの修正版は出たばかり（XServer の対応待ち）。サポートの終わりは遠い
        $this->fakeSources([
            $this->patch + 1 => ['tags' => [], 'days' => 30],
            $this->patch + 2 => ['tags' => ['security'], 'days' => 2],
        ], now()->addYears(2)->toDateString(), now()->addYears(2)->toDateString(), []);
        EnvironmentCheckService::rememberWebPhp();

        $this->artisan('environment:check')->assertSuccessful();

        $cli = EnvironmentCheck::where('target', 'php_cli')->sole();
        $this->assertSame(EnvironmentCheck::OK, $cli->level);
        $this->assertStringContainsString('出たばかりです（XServer の対応を待っています）', $cli->message);
        $this->assertSame(EnvironmentCheck::OK, EnvironmentCheck::where('target', 'packages')->value('level'));

        $this->get(route('home'))->assertOk();
        $this->assertSame(0, Notice::where('kind', 'environment')->count());
    }

    public function test_near_end_of_support_is_planned_and_unreadable_sources_fail_the_task(): void
    {
        // PHP のサポートが5か月後に終わる（計画して対応・注意）
        $this->fakeSources([], now()->addMonths(5)->toDateString(), now()->addYears(2)->toDateString(), []);
        EnvironmentCheckService::rememberWebPhp();
        $this->artisan('environment:check')->assertSuccessful();

        $this->assertSame(EnvironmentCheck::SUPPORT, EnvironmentCheck::where('target', 'php_cli')->value('level'));
        $this->get(route('home'))->assertOk();
        $this->assertSame('warn', Notice::where('kind', 'environment')->sole()->level);

        // 部品の確認ができない（composer の出力が JSON でない）：読み取れないとして、定期実行を失敗にする
        Process::fake(['*' => Process::result(errorOutput: 'Could not open input file: composer', exitCode: 1)]);
        $this->artisan('environment:check')->assertFailed();
        $packages = EnvironmentCheck::where('target', 'packages')->sole();
        $this->assertSame(EnvironmentCheck::UNKNOWN, $packages->level);
        $this->assertStringContainsString('Could not open input file', $packages->message);
    }
}
