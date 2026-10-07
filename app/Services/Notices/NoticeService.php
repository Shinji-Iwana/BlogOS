<?php

namespace App\Services\Notices;

use App\Models\Notice;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiCreditService;
use App\Services\Dashboard\DashboardDataService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * お知らせ（D-74）。
 *
 * 以前はトップページを開くたびに今の状態から作って出していた要対応・注意のお知らせを、1件ずつ記録する（notices）。
 * 記録を更新する（refresh）のは、トップページを開いたときと、定期実行が終わったとき。
 *
 * ・新しく起きた問題：お知らせを記録する（未確認）
 * ・同じ種類の問題の内容（件数など。signature）が変わった：良くなった・悪くなったにかかわらず、新しいお知らせを記録し、
 *   変わる前のお知らせに変動した日時（changed_at）を残す（解消ではない）
 * ・問題がなくなった：最新のお知らせに、解消した日時（resolved_at）を残す
 * ・どれも消さない。確認済みにしても、一覧に残る
 *
 * ブログごとのお知らせは、選択中のブログの分だけ作る（ほかのブログの記録は、選択中のブログでないことを理由に解消しない）。
 */
class NoticeService
{
    /**
     * 種類（表示名は、お知らせの画面の説明に使う）
     */
    public const KINDS = [
        'ai_credit'    => 'OpenAI の残高',
        'ai_price'     => 'OpenAI API料金表',
        'link_switch'  => 'リンクの切り替え・修正の編集案',
        'schedule'     => '定期実行',
        'broken_links' => '内部リンクのリンク切れ',
        'wordpress'    => 'WordPress の更新',
        'affiliate'    => 'アフィリエイトのリンク',
    ];

    // ブログごとのお知らせ
    protected const BLOG_KINDS = ['link_switch', 'broken_links', 'wordpress', 'affiliate'];

    public function __construct(
        protected DashboardDataService $dashboard,
    ) {
    }

    /**
     * 今の状態から、お知らせの記録を更新する。同時に動かないよう、ほかで更新中なら何もしない
     */
    public function refresh(): void
    {
        Cache::lock('notices.refresh', 60)->get(function () {
            $data = $this->dashboard->notices();
            // ブログを選んでいないときは、お知らせを作れない（以前のトップページでも出していなかった）ため、記録を変えない
            if ($data['selectedBlog'] === null) {
                return;
            }
            $blogId = $data['selectedBlog']->id;
            $conditions = collect($this->conditions($data))->keyBy(fn (array $c) => $this->key($c['kind'], $c['blog_id']));

            DB::transaction(function () use ($conditions, $blogId) {
                $now = now();
                $current = Notice::current()->get()->keyBy(fn (Notice $n) => $this->key($n->kind, $n->blog_id));

                foreach ($conditions as $key => $condition) {
                    $notice = $current->get($key);
                    if ($notice !== null && $notice->signature === $condition['signature']) {
                        continue;
                    }
                    // 内容が変わった：変わる前のお知らせに変動した日時を残し、新しいお知らせを記録する
                    $notice?->update(['changed_at' => $now]);
                    Notice::create($condition + ['occurred_at' => $now]);
                }

                // 問題がなくなった：最新のお知らせに解消した日時を残す（ブログごとのものは、選択中のブログの分だけ）
                $current->reject(fn (Notice $n, string $key) => $conditions->has($key))
                    ->filter(fn (Notice $n) => $n->blog_id === null || $n->blog_id === $blogId)
                    ->each(fn (Notice $n) => $n->update(['resolved_at' => $now]));
            });
        });
    }

    /**
     * 今の状態から作る、お知らせの元（refresh の材料。DashboardDataService::notices の結果から）
     *
     * @param  array<string, mixed>  $data
     * @return list<array{kind: string, blog_id: int|null, level: string, message: string, url: string|null, link_label: string|null, signature: string}>
     */
    public function conditions(array $data): array
    {
        $blogId = $data['selectedBlog']?->id;
        $items = [];
        if ($blogId === null) {
            return [];
        }
        $add = function (string $kind, string $level, string $message, ?string $url, ?string $label, string $signature) use (&$items, $blogId) {
            $items[] = [
                'kind'       => $kind,
                'blog_id'    => in_array($kind, self::BLOG_KINDS, true) ? $blogId : null,
                'level'      => $level,
                'message'    => $message,
                'url'        => $url,
                'link_label' => $label,
                'signature'  => $signature,
            ];
        };

        // OpenAI の残高の見込み（D-31-04）。残高の額は API を実行するたびに変わるため、内容の印は段階（未登録・少ない・止まる）だけにする
        if (app(AiApiPolicy::class)->isConfigured()) {
            $credit = app(AiCreditService::class)->status();
            match ($credit['level']) {
                'unknown'  => $add('ai_credit', 'warn', 'OpenAI の残高が登録されていません。OpenAI の画面（Billing）の Credit balance を登録してください（残高の見込みで、API実行を止めるかを判断します）。', route('ai.credits.index'), 'AIの費用と残高', 'unknown'),
                'critical' => $add('ai_credit', 'error', 'OpenAI の残高の見込みが $' . number_format($credit['balance'], 2) . ' です。API実行は止まっているか、まもなく止まります（自動の再評価・定期チェックを含む）。OpenAI の画面で残高を確認して課金し、課金額を登録してください。', route('ai.credits.index'), 'AIの費用と残高', 'critical'),
                'warning'  => $add('ai_credit', 'warn', 'OpenAI の残高の見込みが $' . number_format($credit['balance'], 2) . ' になりました（知らせる基準 $' . number_format($credit['warning'], 2) . '）。OpenAI の画面で残高を確認し、必要なら課金して、残高・課金した額を登録してください。', route('ai.credits.index'), 'AIの費用と残高', 'warning'),
                default    => null,
            };
        }

        // API実行の料金表（D-31-03）
        $price = $data['priceNotice'];
        if ($price['pending'] || $price['failed'] || $price['applied']) {
            $message = 'OpenAI API料金表：'
                . ($price['failed'] ? '公式のページから読み取れなかった料金があります。' : '')
                . ($price['pending'] ? "値下がりの確認待ちが{$price['pending']}件あります。" : '')
                . ($price['applied'] ? "直近7日に{$price['applied']}件の料金を変更しました。" : '');
            $add('ai_price', $price['failed'] ? 'error' : 'warn', $message,
                $price['pending'] ? route('ai.settings.edit') . '#prices' : route('ai.prices.index'),
                $price['pending'] ? '値下がりを確認する' : '料金表を確認する',
                "failed:{$price['failed']}|pending:{$price['pending']}|applied:{$price['applied']}");
        }

        // 公開された記事へのリンクの切り替え（D-39）
        if (($count = $data['linkSwitchDrafts'] ?? 0) > 0) {
            $add('link_switch', 'warn', "リンクの切り替え・修正の編集案が{$count}件あります（公開された記事へのリンク、古い URL など）。", route('drafts.link-switch'), '確認して反映する', (string) $count);
        }

        // 定期実行（D-44）
        $schedule = $data['scheduleNotice'];
        if ($schedule['stopped'] || $schedule['failed'] !== []) {
            $message = '定期実行：'
                . ($schedule['stopped'] ? '26時間以上、定期実行が動いていません（サーバーの cron を確認してください）。' : '')
                . ($schedule['failed'] !== [] ? '前回が失敗した定期実行があります（' . implode('、', $schedule['failed']) . '）。' : '');
            $add('schedule', 'error', $message, route('scheduled-tasks.runs'), '定期実行履歴',
                'stopped:' . (int) $schedule['stopped'] . '|failed:' . implode(',', $schedule['failed']));
        }

        // 内部リンクのリンク切れ（D-42）
        if (($count = $data['brokenLinks'] ?? 0) > 0) {
            $add('broken_links', 'error', "内部リンク：リンク切れが{$count}件あります。", route('links.check') . '#broken', '内部リンクを確認する', (string) $count);
        }

        // WordPress の更新・公開停止のプラグイン（D-38）
        $wordpress = $data['wordpressNotice'];
        if ($wordpress['updates'] > 0 || $wordpress['closed'] > 0) {
            $message = 'WordPress：'
                . ($wordpress['updates'] ? "更新が{$wordpress['updates']}件あります。" : '')
                . ($wordpress['closed'] ? "公開停止になったプラグインが{$wordpress['closed']}件あります。" : '');
            $add('wordpress', 'error', $message, route('wordpress-updates.index'), 'WordPress情報', "updates:{$wordpress['updates']}|closed:{$wordpress['closed']}");
        }

        // アフィリエイトのリンクの確認（D-33-09）
        if (($count = $data['affiliateSuspects'] ?? 0) > 0) {
            $add('affiliate', 'error', "アフィリエイトのリンク：提携終了の疑いがあるプログラムが{$count}件あります。", route('materials.programs.index'), 'アフィリエイトのプログラムを確認する', (string) $count);
        }

        return $items;
    }

    /**
     * ヘッダーの件数：今も続いていて（変動も解消もしていない）、未確認のお知らせの数
     */
    public function openCount(): int
    {
        return Notice::open()->count();
    }

    /**
     * 今も続いていて、未確認のお知らせ（新しい順）
     *
     * @return Collection<int, Notice>
     */
    public function open(int $limit = 5): Collection
    {
        return Notice::open()->latest('occurred_at')->latest('id')->limit($limit)->get();
    }

    /**
     * お知らせの画面の一覧（新しい順。ページに分ける）
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Notice::with('confirmer:id,name')->latest('occurred_at')->latest('id')->paginate($perPage);
    }

    /**
     * 確認済みにする（確認済みのものは、そのまま）
     *
     * @param  list<int>  $ids
     */
    public function confirm(array $ids, ?int $userId): int
    {
        return Notice::whereIn('id', $ids)->whereNull('confirmed_at')->update(['confirmed_at' => now(), 'confirmed_by' => $userId]);
    }

    protected function key(string $kind, ?int $blogId): string
    {
        return $kind . ':' . ($blogId ?? '-');
    }
}
