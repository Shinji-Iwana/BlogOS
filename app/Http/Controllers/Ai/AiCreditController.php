<?php

namespace App\Http\Controllers\Ai;

use App\Enums\AiMode;
use App\Http\Controllers\Controller;
use App\Models\AiCreditEntry;
use App\Services\Voice\VoiceSettings;
use App\Repositories\AiGenerationRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * AIの費用と残高（全ブログ共通。D-31-04）。
 *
 * OpenAI の画面で見た残高と、課金した額を登録する（メニューの「設定 → AI」のポップアップ。ai/credits/modals。D-62）。
 * BlogOS の日ごと・モデルごとの記録を、OpenAI の Usage の画面と比べられるように表示する。
 * 処理ごと（品質診断・記事改修など）・きっかけごとの内訳も出す（何に費用がかかっているかを見るため。D-66）。
 */
class AiCreditController extends Controller
{
    public function __construct(
        protected AiCreditService $credits,
        protected AiGenerationRepository $generations,
        protected AiApiPolicy $apiPolicy,
    ) {
    }

    public function index(Request $request)
    {
        $days = min(90, max(1, (int) $request->query('days', 7)));
        $to = now();
        $from = now()->utc()->startOfDay()->subDays($days - 1);

        return view('ai.credits.index', [
            'status'     => $this->credits->status(),
            'history'    => $this->credits->history(),
            'usage'      => $this->generations->apiUsageByDay($from, $to),
            'byPurpose'  => $this->usageByPurpose($from, $to),
            'days'       => $days,
            'spent'      => $this->apiPolicy->spentThisMonth(),
            'budget'     => $this->apiPolicy->monthlyBudget(),
            'configured' => $this->apiPolicy->isConfigured(),
            'reconcileDays' => (int) config('blogos.ai.credit.reconcile_days'),
        ]);
    }

    /**
     * 処理ごと・きっかけごと・モデルごとの内訳（API実行と音声の操作。費用の多い順。D-66）
     *
     * @return list<array{label: string, trigger: string, model: string, requests: int, input_tokens: int, cached_input_tokens: int, output_tokens: int, web_search_calls: int|null, cost: float}>
     */
    protected function usageByPurpose(Carbon $from, Carbon $to): array
    {
        $rows = [];
        foreach ($this->generations->apiUsageByPurpose($from, $to) as $row) {
            $rows[] = [
                'label'               => AiMode::tryFrom((string) $row->purpose)?->label() ?? (string) $row->purpose,
                'trigger'             => (int) $row->automatic === 1 ? '自動（定期実行など）' : '人が実行',
                'model'               => (string) ($row->model ?? '-'),
                'requests'            => (int) $row->requests,
                'input_tokens'        => (int) $row->input_tokens,
                'cached_input_tokens' => (int) $row->cached_input_tokens,
                'output_tokens'       => (int) $row->output_tokens,
                'web_search_calls'    => (int) $row->web_search_calls,
                'cost'                => (float) $row->cost,
            ];
        }
        foreach ($this->generations->voiceUsageByMode($from, $to) as $row) {
            $rows[] = [
                // 画面での方式の呼び方（A・B・C。D-60-03）
                'label'               => '音声の操作（方式' . mb_substr(VoiceSettings::MODES[$row->mode] ?? (string) $row->mode, 0, 1) . '）',
                'trigger'             => '人が実行（声）',
                // 聞き取り・判断・返事の声のモデル（リアルタイム会話は、判断と返事の声が同じモデル）
                'model'               => implode('・', array_unique(array_filter([$row->transcribe_model, $row->text_model, $row->tts_model]))) ?: '-',
                'requests'            => (int) $row->requests,
                'input_tokens'        => (int) $row->input_tokens,
                'cached_input_tokens' => (int) $row->cached_input_tokens,
                'output_tokens'       => (int) $row->output_tokens,
                'web_search_calls'    => null,
                'cost'                => (float) $row->cost,
            ];
        }
        usort($rows, fn ($a, $b) => $b['cost'] <=> $a['cost']);

        return $rows;
    }

    /**
     * OpenAI の画面で見た残高を登録する（見込みの起点を、実際の残高に合わせる）
     */
    public function storeBalance(Request $request)
    {
        [$amount, $occurredAt, $note] = $this->validated($request);
        $entry = $this->credits->recordBalance($amount, $occurredAt, $note, $request->user()?->id);

        $difference = $entry->estimated_balance !== null ? sprintf('（その時点の BlogOS の見込み $%.2f、差 $%+.2f）', $entry->estimated_balance, $amount - $entry->estimated_balance) : '';

        // 開いていた画面に戻る（メニューのポップアップから登録する。D-62）
        return back()->with('status', sprintf('残高 $%.2f を登録しました%s。', $amount, $difference));
    }

    public function storePurchase(Request $request)
    {
        [$amount, $occurredAt, $note] = $this->validated($request);
        $this->credits->recordPurchase($amount, $occurredAt, $note, $request->user()?->id);

        return back()->with('status', sprintf('課金 $%.2f を登録しました。', $amount));
    }

    /**
     * 登録の誤りを消す
     */
    public function destroy(int $id)
    {
        $entry = AiCreditEntry::find($id);
        abort_if($entry === null, 404);
        $entry->delete();

        return redirect()->route('ai.credits.index')->with('status', "{$entry->type->label()}の記録を削除しました。");
    }

    /**
     * @return array{0: float, 1: Carbon, 2: string|null}
     */
    protected function validated(Request $request): array
    {
        $validated = $request->validate([
            'amount'      => ['required', 'numeric', 'min:0', 'max:100000'],
            'occurred_at' => ['nullable', 'date'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required' => '金額（米ドル）を入力してください。',
        ]);

        // 画面の日時は日本時間で入力する（初めは、ポップアップを開いた時刻が入っている。D-62）
        $occurredAt = filled($validated['occurred_at'] ?? null)
            ? Carbon::parse($validated['occurred_at'], config('blogos.display_timezone'))->utc()
            : now();

        // 今より後は登録できない。日本時間として読んでから比べる
        // （検証の before_or_equal:now は、入力を UTC として読むため、日本時間の「今」が9時間先と判断されてしまう）
        if ($occurredAt->isFuture()) {
            throw ValidationException::withMessages(['occurred_at' => '日時は、今より前にしてください。']);
        }

        return [(float) $validated['amount'], $occurredAt, $validated['note'] ?? null];
    }
}
