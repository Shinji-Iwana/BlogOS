<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Models\AiCreditEntry;
use App\Repositories\AiGenerationRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiCreditService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * AIの費用と残高（全ブログ共通。D-31-04）。
 *
 * OpenAI の画面で見た残高と、課金した額を登録する。BlogOS の日ごと・モデルごとの記録を、OpenAI の Usage の画面と比べられるように表示する。
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
            'days'       => $days,
            'spent'      => $this->apiPolicy->spentThisMonth(),
            'budget'     => $this->apiPolicy->monthlyBudget(),
            'configured' => $this->apiPolicy->isConfigured(),
            'reconcileDays' => (int) config('blogos.ai.credit.reconcile_days'),
        ]);
    }

    /**
     * OpenAI の画面で見た残高を登録する（見込みの起点を、実際の残高に合わせる）
     */
    public function storeBalance(Request $request)
    {
        [$amount, $occurredAt, $note] = $this->validated($request);
        $entry = $this->credits->recordBalance($amount, $occurredAt, $note, $request->user()?->id);

        $difference = $entry->estimated_balance !== null ? sprintf('（その時点の BlogOS の見込み $%.2f、差 $%+.2f）', $entry->estimated_balance, $amount - $entry->estimated_balance) : '';

        return redirect()->route('ai.credits.index')->with('status', sprintf('残高 $%.2f を登録しました%s。', $amount, $difference));
    }

    public function storePurchase(Request $request)
    {
        [$amount, $occurredAt, $note] = $this->validated($request);
        $this->credits->recordPurchase($amount, $occurredAt, $note, $request->user()?->id);

        return redirect()->route('ai.credits.index')->with('status', sprintf('課金 $%.2f を登録しました。', $amount));
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
            'occurred_at' => ['nullable', 'date', 'before_or_equal:now'],
            'note'        => ['nullable', 'string', 'max:1000'],
        ], [
            'amount.required'             => '金額（米ドル）を入力してください。',
            'occurred_at.before_or_equal' => '日時は、今より前にしてください。',
        ]);

        // 画面の日時は日本時間で入力する
        $occurredAt = filled($validated['occurred_at'] ?? null)
            ? Carbon::parse($validated['occurred_at'], config('blogos.display_timezone'))->utc()
            : now();

        return [(float) $validated['amount'], $occurredAt, $validated['note'] ?? null];
    }
}
