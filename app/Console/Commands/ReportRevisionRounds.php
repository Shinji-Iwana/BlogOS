<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 記事の再評価の繰り返し（改修 → 編集案の診断。D-65）の結果を確かめる（読み取りだけ。何も変えない）。
 *
 * 点数が上がらない原因を調べるために作った（D-63-30）。表示するもの：
 * - 全体：回ごとの点数の上がり下がり、判定が下がった・上がった項目、最後の診断で ○ にならなかった項目（95点を阻んでいる項目）
 * - --items：項目ごとの、最後の診断の判定の理由・指摘と、改修で直さなかった理由（D-70-08）
 * - --article：1記事の回ごとの点数・判定が変わった項目・指摘への対応と確かめた結果・編集案の目印の数と、図・リンク・教材の項目の判定の理由
 */
class ReportRevisionRounds extends Command
{
    protected $signature = 'blogos:report-revision-rounds
        {--days=30 : 何日前からの記事の再評価を見るか}
        {--article= : 1記事だけを詳しく見る（post:12 または page:3）}
        {--items= : 項目ごとに、最後の診断の判定の理由・指摘と、改修で直さなかった理由を並べる（例：reader.terms,intent.no_extra_search）}
        {--limit=15 : --items で出す記事の数（項目ごと）}';

    protected $description = '記事の再評価の繰り返し（改修と編集案の診断）の結果を表示する（読み取りだけ）';

    protected const JUDGMENTS = ['good' => '○', 'partial' => '△', 'bad' => '×', 'needs_human' => '人'];

    // 図・内部リンク・回遊・収益化の項目（編集案の目印 [[画像:]]・[[記事:]]・[[教材:]] の影響を見る）
    protected const MARKER_ITEMS = ['reader.diagrams', 'original.diagrams', 'seo.internal_links', 'nav.parent', 'nav.child', 'nav.related_next', 'mon.fit', 'mon.placement', 'mon.reason'];

    public function handle(): int
    {
        $chains = $this->chains();
        if ($chains->isEmpty()) {
            $this->info('対象の記事の再評価（繰り返しのある改修）がありません。--days を増やしてください。');

            return self::SUCCESS;
        }

        if ($this->option('items')) {
            $this->items($chains, array_values(array_filter(array_map('trim', explode(',', (string) $this->option('items'))))));

            return self::SUCCESS;
        }

        if ($this->option('article')) {
            $key = (string) $this->option('article');
            if (! $chains->has($key)) {
                $this->error("{$key} の記録がありません。");

                return self::FAILURE;
            }
            $this->detail($key, $chains[$key]);

            return self::SUCCESS;
        }

        $this->summary($chains);

        return self::SUCCESS;
    }

    /**
     * 記事ごとの、回の並び（改修の実行・改修前の点数・改修後の診断）
     *
     * @return Collection<string, Collection<int, object>>
     */
    protected function chains(): Collection
    {
        $batches = DB::table('ai_batches')->where('purpose', 'revision')->where('created_at', '>=', now()->subDays((int) $this->option('days')))
            ->orderBy('id')->get(['id', 'target_parameters'])
            ->filter(fn ($b) => isset(json_decode((string) $b->target_parameters, true)['max_rounds']));
        $rounds = $batches->mapWithKeys(fn ($b) => [$b->id => (int) (json_decode((string) $b->target_parameters, true)['round'] ?? 1)]);

        return DB::table('ai_batch_items')->whereIn('ai_batch_id', $batches->pluck('id'))->orderBy('id')->get()
            ->map(function ($item) use ($rounds) {
                $item->round = $rounds[$item->ai_batch_id];
                $item->evaluation = $item->diagnosis_generation_id
                    ? DB::table('article_evaluations')->where('ai_generation_id', $item->diagnosis_generation_id)->orderByDesc('id')->first()
                    : null;
                $item->draft_id = $item->ai_generation_id ? DB::table('article_drafts')->where('ai_generation_id', $item->ai_generation_id)->value('id')
                    ?? DB::table('ai_generations')->where('id', $item->ai_generation_id)->value('article_draft_id') : null;

                return $item;
            })
            ->groupBy(fn ($item) => $item->post_id ? "post:{$item->post_id}" : "page:{$item->page_id}");
    }

    /**
     * @return array<string, string> 項目のキー => 判定
     */
    protected function judgments(?object $evaluation): array
    {
        return $evaluation ? DB::table('article_evaluation_details')->where('article_evaluation_id', $evaluation->id)->pluck('judgment', 'item_key')->all() : [];
    }

    /**
     * 改修前の評価（1回目は記事・前の回の編集案の、改修より前の最新の評価）
     */
    protected function evaluationBefore(object $item): ?object
    {
        $generation = DB::table('ai_generations')->where('id', $item->ai_generation_id)->first(['article_draft_id', 'created_at']);
        if ($generation === null) {
            return null;
        }

        return DB::table('article_evaluations')->where($item->post_id ? 'post_id' : 'page_id', $item->post_id ?? $item->page_id)
            ->where('created_at', '<=', $generation->created_at)
            ->when($generation->article_draft_id, fn ($q) => $q->where('article_draft_id', $generation->article_draft_id), fn ($q) => $q->whereNull('article_draft_id'))
            ->whereNotNull('score')->orderByDesc('id')->first();
    }

    /**
     * @param Collection<string, Collection<int, object>> $chains
     */
    protected function summary(Collection $chains): void
    {
        $this->info("記事の再評価（繰り返しのある改修）：{$chains->count()}記事（直近{$this->option('days')}日）");

        // 回ごとの点数の変化
        $deltas = [];
        $passed = 0;
        $down = [];
        $up = [];
        $blocking = [];
        foreach ($chains as $key => $items) {
            foreach ($items as $item) {
                if ($item->evaluation?->score === null || $item->score_before === null) {
                    continue;
                }
                $deltas[$item->round][] = (float) $item->evaluation->score - (float) $item->score_before;

                // 判定が変わった項目（改修前の評価 → 改修後の診断）
                $before = $this->judgments($this->evaluationBefore($item));
                foreach ($this->judgments($item->evaluation) as $itemKey => $after) {
                    $was = $before[$itemKey] ?? null;
                    if ($was === 'good' && in_array($after, ['partial', 'bad'], true)) {
                        $down[$itemKey] = ($down[$itemKey] ?? 0) + 1;
                    } elseif (in_array($was, ['partial', 'bad'], true) && $after === 'good') {
                        $up[$itemKey] = ($up[$itemKey] ?? 0) + 1;
                    }
                }
            }

            // 最後の診断で ○ にならなかった項目
            $last = $items->last(fn ($item) => $item->evaluation !== null);
            if ($last === null) {
                continue;
            }
            if ((float) $last->evaluation->score >= (float) config('blogos.ai.acceptance_score')) {
                $passed++;
            }
            foreach ($this->judgments($last->evaluation) as $itemKey => $judgment) {
                if (in_array($judgment, ['partial', 'bad'], true)) {
                    $blocking[$itemKey] = ($blocking[$itemKey] ?? 0) + 1;
                }
            }
        }

        $this->newLine();
        $this->line('■ 回ごとの点数の変化（改修後の診断 − 改修前の点数）');
        ksort($deltas);
        foreach ($deltas as $round => $values) {
            $this->line(sprintf('  %d回目：%d件・平均 %+.1f点・上がった %d件・下がった %d件（最大 %+.1f／最小 %+.1f）', $round, count($values),
                array_sum($values) / count($values), count(array_filter($values, fn ($v) => $v > 0)), count(array_filter($values, fn ($v) => $v < 0)), max($values), min($values)));
        }
        $this->line("  最後の診断で基準（" . config('blogos.ai.acceptance_score') . "点）以上：{$passed}記事");

        $this->newLine();
        $this->line('■ 改修の後に、○ から △・× に下がった項目（記事数の多い順）');
        $this->counts($down);
        $this->newLine();
        $this->line('■ 改修の後に、△・× から ○ に上がった項目（記事数の多い順）');
        $this->counts($up);
        $this->newLine();
        $this->line('■ 最後の診断で ○ にならなかった項目（95点を阻んでいる項目。記事数の多い順）');
        $this->counts($blocking);

        // 改修で「直さなかった」指摘（AI が直せない項目を見る）
        $generationIds = $chains->flatten(1)->pluck('ai_generation_id')->filter()->all();
        $notFixed = DB::table('revision_findings')->whereIn('ai_generation_id', $generationIds)->where('response_status', 'not_fixed')
            ->selectRaw('item_key, count(*) as n')->groupBy('item_key')->pluck('n', 'item_key')->map(fn ($n) => (int) $n)->all();
        $this->newLine();
        $this->line('■ 改修で「直さなかった」指摘（件数の多い順。AI が直せない項目）');
        $this->counts($notFixed);

        $this->newLine();
        $this->line('■ 記事ごと（回：改修前 → 改修後。1記事を詳しく見るときは --article=post:12）');
        foreach ($chains as $key => $items) {
            $this->line("  {$key} " . $items->map(fn ($item) => "{$item->round}回目：" . ($item->score_before ?? '-') . '→' . ($item->evaluation->score ?? '-') . "（編集案 #" . ($item->draft_id ?? '-') . '）')->implode('　'));
        }
    }

    /**
     * 項目ごとに、最後の診断で ○ にならなかった記事の、判定の理由・指摘（どこが・何が足りないか・どう直すか）と、
     * その項目を改修でどう扱ったか（直した・一部直した・直さなかったと、その理由）を並べる（D-70-08）
     *
     * @param Collection<string, Collection<int, object>> $chains
     * @param list<string> $keys
     */
    protected function items(Collection $chains, array $keys): void
    {
        $limit = max(1, (int) $this->option('limit'));
        $cut = fn (?string $text, int $length = 220) => $text === null || $text === '' ? '-' : mb_substr(preg_replace('/\s+/u', ' ', $text), 0, $length);

        foreach ($keys as $itemKey) {
            $rows = [];
            $judgments = [];
            foreach ($chains as $key => $items) {
                $last = $items->last(fn ($item) => $item->evaluation !== null);
                if ($last === null) {
                    continue;
                }
                $detail = DB::table('article_evaluation_details')->where('article_evaluation_id', $last->evaluation->id)->where('item_key', $itemKey)->first();
                if ($detail === null) {
                    continue;
                }
                $judgments[$detail->judgment] = ($judgments[$detail->judgment] ?? 0) + 1;
                if (! in_array($detail->judgment, ['partial', 'bad'], true)) {
                    continue;
                }
                $finding = DB::table('revision_findings')->where('ai_generation_id', $last->ai_generation_id)->where('item_key', $itemKey)->first();
                $rows[] = [$key, $last, $detail, $finding];
            }

            $this->newLine();
            $this->info("■ {$itemKey}：最後の診断の判定　" . collect($judgments)->map(fn ($n, $j) => (self::JUDGMENTS[$j] ?? $j) . " {$n}件")->implode('・'));
            foreach (array_slice($rows, 0, $limit) as [$key, $last, $detail, $finding]) {
                $this->line("  {$key}（{$last->round}回目・{$last->evaluation->score}点）" . (self::JUDGMENTS[$detail->judgment] ?? $detail->judgment));
                $this->line('    理由：' . $cut($detail->comment));
                $this->line('    どこが：' . $cut($detail->location, 120));
                $this->line('    何が足りないか：' . $cut($detail->problem));
                $this->line('    どう直すか：' . $cut($detail->fix));
                if ($finding !== null) {
                    $this->line('    改修での対応：' . ($finding->response_status ?? '-') . '（' . $cut($finding->response_note, 160) . '）');
                }
            }
            if (count($rows) > $limit) {
                $this->line('  ほか ' . (count($rows) - $limit) . '記事（--limit で増やせます）');
            }
        }
    }

    /**
     * @param array<string, int> $counts
     */
    protected function counts(array $counts): void
    {
        if ($counts === []) {
            $this->line('  なし');

            return;
        }
        arsort($counts);
        foreach (array_slice($counts, 0, 20, true) as $itemKey => $count) {
            $this->line("  {$itemKey}：{$count}");
        }
    }

    /**
     * @param Collection<int, object> $items
     */
    protected function detail(string $key, Collection $items): void
    {
        $this->info("{$key} の記事の再評価");
        foreach ($items as $item) {
            $this->newLine();
            $this->line("■ {$item->round}回目（まとめて実行 #{$item->ai_batch_id}・改修の実行 #{$item->ai_generation_id}・編集案 #" . ($item->draft_id ?? '-') . "・改修範囲 {$item->revision_scope}）");
            $this->line('  点数：' . ($item->score_before ?? '-') . ' → ' . ($item->evaluation->score ?? '-') . '（必須条件：' . match ($item->evaluation?->required_conditions_passed) { true, 1 => '満たす', false, 0 => '満たさない', default => '-' } . '）');
            if ($item->message) {
                $this->line("  メッセージ：{$item->message}");
            }

            // 判定が変わった項目
            $before = $this->judgments($this->evaluationBefore($item));
            $after = $this->judgments($item->evaluation);
            $changes = [];
            foreach ($after as $itemKey => $judgment) {
                if (($before[$itemKey] ?? null) !== $judgment) {
                    $changes[] = "{$itemKey} " . (self::JUDGMENTS[$before[$itemKey] ?? ''] ?? '-') . '→' . (self::JUDGMENTS[$judgment] ?? $judgment);
                }
            }
            $this->line('  判定が変わった項目：' . ($changes === [] ? 'なし' : implode('、', $changes)));

            // 指摘への対応（改修）と、確かめた結果（診断）
            $findings = DB::table('revision_findings')->where('ai_generation_id', $item->ai_generation_id)->orderBy('number')->get();
            foreach ($findings as $finding) {
                $this->line("  指摘{$finding->number} {$finding->item_key}（" . (self::JUDGMENTS[$finding->judgment] ?? $finding->judgment) . "）：対応 " . ($finding->response_status ?? '-') . '・確認 ' . ($finding->check_status ?? '-')
                    . ($finding->response_status === 'not_fixed' ? '（' . mb_substr((string) $finding->response_note, 0, 60) . '）' : ''));
            }

            // 図・内部リンク・回遊・収益化の項目の判定の理由（目印が見えているか）
            if ($item->evaluation) {
                foreach (DB::table('article_evaluation_details')->where('article_evaluation_id', $item->evaluation->id)->whereIn('item_key', self::MARKER_ITEMS)->get() as $detail) {
                    $this->line("  {$detail->item_key} " . (self::JUDGMENTS[$detail->judgment] ?? $detail->judgment) . '：' . mb_substr((string) $detail->comment, 0, 120));
                }
            }
        }

        // 今の編集案の目印の数
        $draftId = $items->last()->draft_id;
        $content = $draftId ? (string) DB::table('article_drafts')->where('id', $draftId)->value('content_raw') : '';
        $this->newLine();
        $this->line("■ 編集案 #{$draftId} の本文の目印：画像 " . preg_match_all('/\[\[画像:/u', $content) . '・記事 ' . preg_match_all('/\[\[記事:/u', $content) . '・教材 ' . preg_match_all('/\[\[教材:/u', $content)
            . '・img タグ ' . preg_match_all('/<img\b/i', $content));
        $histories = DB::table('article_draft_histories')->where('article_draft_id', $draftId)->where('field', 'content_raw')->count();
        $this->line("  本文の変更の記録：{$histories}件（回ごとに同じ編集案を上書きしているかの確認）");
    }
}
