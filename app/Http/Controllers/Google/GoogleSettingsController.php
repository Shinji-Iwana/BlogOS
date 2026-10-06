<?php

namespace App\Http\Controllers\Google;

use App\Enums\GoogleService;
use App\Http\Controllers\Concerns\UsesSelectedBlog;
use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Repositories\GoogleAccountRepository;
use App\Repositories\GoogleFetchRunRepository;
use App\Repositories\GoogleMetricRepository;
use App\Services\Google\GoogleConnectionService;
use Illuminate\Http\Request;

/**
 * Google連携（D-21-01、D-21-07）。
 *
 * Googleアカウントの接続と、選択中のブログの対応先は、メニューの「設定 → Google」のポップアップ（google/modals）で行う。
 * 保存・解除の後は、開いていた画面に戻り、同じポップアップを開いて結果を出す（session の google_modal。D-63-19）。
 * 取得は、定期実行とメニューの「設定 → 即時実行 → Googleとの同期」で行う（D-63-18）。
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
    ) {
    }

    /**
     * Googleとの同期履歴（取得の記録と、保存している行数。メニューの「履歴 → Googleとの同期履歴」。D-63-19）
     */
    public function runs()
    {
        $blog = $this->selectedBlog();

        return view('google.fetch-runs', [
            'recentRuns' => $this->runs->recentForBlog($blog->id, 30),
            'counts'     => $this->metrics->counts($blog->id),
        ]);
    }

    /**
     * 対応先の候補（ポップアップの「候補を読み込む」から読む。Google API を呼ぶため、求められたときだけ）
     */
    public function candidates(Request $request, BlogRepository $blogs, string $service)
    {
        $serviceEnum = GoogleService::tryFrom($service);
        abort_if($serviceEnum === null, 404);
        $account = $this->accounts->find((int) $request->query('account'));
        if ($account === null) {
            return response()->json(['items' => [], 'error' => 'Googleアカウントが見つかりません。'], 404);
        }

        // AdSense は、選択中のブログのドメインを初めから選ぶ
        $blogHost = parse_url((string) $blogs->findSelected()?->home, PHP_URL_HOST);
        $result = $this->connection->candidatesFor($account, $serviceEnum);

        return response()->json([
            'items' => array_map(function (array $item) use ($blogHost) {
                $domains = $item['domains'] ?? [];

                return [
                    'resource_name' => $item['resource_name'],
                    'display_name'  => $item['display_name'],
                    'domain'        => collect($domains)->first(fn ($d) => $d === $blogHost) ?? ($domains[0] ?? ''),
                ];
            }, $result['items']),
            'error' => $result['error'],
        ]);
    }

    public function updateProperty(Request $request, string $service)
    {
        $blog = $this->selectedBlog();
        $serviceEnum = GoogleService::tryFrom($service);
        abort_if($serviceEnum === null, 404);

        if ($request->boolean('remove')) {
            $this->accounts->removeProperty($blog->id, $serviceEnum);

            return $this->done($serviceEnum->modalId(), "{$serviceEnum->label()}の対応先を解除しました（取得済みのデータは残ります）。");
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

        return $this->done($serviceEnum->modalId(), "{$serviceEnum->label()}の対応先を保存しました。");
    }

    /**
     * 接続の解除（ブログを選んでいなくてもできる。対応先の設定は、全ブログの分を解除する）
     */
    public function destroyAccount(int $id)
    {
        $account = $this->accounts->find($id);
        abort_if($account === null, 404);

        $this->connection->disconnect($account);

        return $this->done('google-account-modal', "Googleアカウント（{$account->email}）の接続を解除しました。このアカウントを使う対応先の設定も解除しました（取得済みのデータは残ります）。");
    }

    /**
     * 開いていた画面に戻り、同じポップアップを開いて結果を出す
     */
    protected function done(string $modalId, string $status)
    {
        return back()->with('status', $status)->with('google_modal', $modalId);
    }
}
