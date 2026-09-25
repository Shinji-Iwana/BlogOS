<?php

namespace App\Http\Controllers\Google;

use App\Clients\Google\GoogleOAuthClient;
use App\Enums\GoogleService;
use App\Enums\SyncTrigger;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Jobs\GoogleFetchJob;
use App\Repositories\GoogleAccountRepository;
use App\Repositories\GoogleFetchRunRepository;
use App\Repositories\GoogleMetricRepository;
use App\Services\Google\GoogleConnectionService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Google連携の設定（Googleアカウントの接続と、選択中のブログの対応先）と、手動での取得（D-21-01、D-21-07）。
 */
class GoogleSettingsController extends Controller
{
    use UsesSelectedBlog;

    /**
     * 対応先の値の形
     */
    protected const RESOURCE_PATTERNS = [
        'ga4'            => '/^properties\/\d+$/',
        'search_console' => '/^(sc-domain:[^\s]+|https?:\/\/[^\s]+)$/',
        'adsense'        => '/^accounts\/pub-\d+$/',
    ];

    public function __construct(
        protected GoogleAccountRepository $accounts,
        protected GoogleFetchRunRepository $runs,
        protected GoogleMetricRepository $metrics,
        protected GoogleConnectionService $connection,
        protected GoogleOAuthClient $oauth,
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->selectedBlog();
        $accounts = $this->accounts->all();

        // 対応先の候補は、Google APIを呼ぶため、求められたときだけ取得する
        $candidateAccount = $request->filled('candidates') ? $this->accounts->find((int) $request->query('candidates')) : null;

        return view('google.settings', [
            'blog'             => $blog,
            'configured'       => $this->oauth->isConfigured(),
            'accounts'         => $accounts,
            'properties'       => $this->accounts->propertiesForBlog($blog->id),
            'services'         => GoogleService::cases(),
            'candidateAccount' => $candidateAccount,
            'candidates'       => $candidateAccount ? $this->connection->candidates($candidateAccount) : null,
            'latestRuns'       => $this->runs->latestForBlog($blog->id),
            'recentRuns'       => $this->runs->recentForBlog($blog->id, 30),
            'counts'           => $this->metrics->counts($blog->id),
            'queued'           => Cache::has(GoogleFetchJob::queuedKey($blog->id)),
            'blogHost'         => parse_url($blog->home, PHP_URL_HOST),
        ]);
    }

    public function updateProperty(Request $request, string $service)
    {
        $blog = $this->selectedBlog();
        $serviceEnum = GoogleService::tryFrom($service);
        abort_if($serviceEnum === null, 404);

        if ($request->boolean('remove')) {
            $this->accounts->removeProperty($blog->id, $serviceEnum);

            return redirect()->route('google.settings')->with('status', "{$serviceEnum->label()}の対応先を解除しました（取得済みのデータは残ります）。");
        }

        $validated = $request->validate([
            'google_account_id' => ['required', 'integer'],
            'resource_name'     => ['required', 'string', 'max:255', 'regex:' . self::RESOURCE_PATTERNS[$service]],
            'display_name'      => ['nullable', 'string', 'max:255'],
            'adsense_domain'    => [$serviceEnum === GoogleService::Adsense ? 'nullable' : 'prohibited', 'string', 'max:255', 'regex:/^[a-z0-9.-]+$/i'],
        ], [
            'resource_name.regex' => match ($serviceEnum) {
                GoogleService::Ga4           => 'GA4のプロパティは「properties/数字」の形で指定してください。',
                GoogleService::SearchConsole => 'Search Consoleのサイトは「sc-domain:ドメイン」またはURLで指定してください。',
                GoogleService::Adsense       => 'AdSenseのアカウントは「accounts/pub-数字」の形で指定してください。',
            },
        ]);

        if ($this->accounts->find((int) $validated['google_account_id']) === null) {
            return back()->withErrors(['google_account_id' => 'Googleアカウントが見つかりません。'])->withInput();
        }

        $this->accounts->saveProperty(
            $blog->id,
            $serviceEnum,
            (int) $validated['google_account_id'],
            $validated['resource_name'],
            $validated['display_name'] ?? null,
            $validated['adsense_domain'] ?? null
        );

        return redirect()->route('google.settings')->with('status', "{$serviceEnum->label()}の対応先を保存しました。");
    }

    public function destroyAccount(int $id)
    {
        $this->selectedBlog();
        $account = $this->accounts->find($id);
        abort_if($account === null, 404);

        $this->connection->disconnect($account);

        return redirect()->route('google.settings')->with('status', "Googleアカウント（{$account->email}）の接続を解除しました。このアカウントを使う対応先の設定も解除しました（取得済みのデータは残ります）。");
    }

    /**
     * 手動での取得（Jobとして登録する）
     */
    public function fetch(Request $request)
    {
        $blog = $this->selectedBlog();

        if ($this->accounts->propertiesForBlog($blog->id)->isEmpty()) {
            return back()->withErrors(['google' => '対応先が設定されていません。']);
        }

        Cache::put(GoogleFetchJob::queuedKey($blog->id), now()->toIso8601String(), 86400);
        GoogleFetchJob::dispatch($blog->id, SyncTrigger::Manual, $request->user()?->id);

        return redirect()->route('google.settings')->with('status', 'Googleのデータの取得を開始しました。初めての取得は時間がかかることがあります。');
    }
}
