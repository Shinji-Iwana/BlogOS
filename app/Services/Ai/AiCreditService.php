<?php

namespace App\Services\Ai;

use App\Enums\AiCreditEntryType;
use App\Models\AiCreditEntry;
use App\Repositories\AiGenerationRepository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * OpenAI の残高（前払いのクレジット）の見込み（D-31-04）。
 *
 * BlogOS から OpenAI の残高は取得できない（管理者用のキーが必要なため使わない）。そのため、人が OpenAI の画面で見た残高と、
 * 課金した額を登録し、その後のAPI実行の費用の目安を引いて見込む：
 * 残高の見込み ＝ 最後に登録した残高 ＋ その後の課金 − その後の費用の目安。
 * 見込みが少なくなったら画面で知らせ、足りなくなる見込みなら実行しない（人が OpenAI で課金し、BlogOS に登録する）。
 */
class AiCreditService
{
    public function __construct(
        protected AiGenerationRepository $generations,
    ) {
    }

    /**
     * @return array{
     *     balance: float|null,
     *     level: string,
     *     base: AiCreditEntry|null,
     *     purchases: float,
     *     spent: float,
     *     warning: float,
     *     reserve: float,
     *     days_since_check: int|null
     * } level：unknown（残高が未登録）/ ok / warning（知らせる基準以下）/ critical（実行が止まっている、またはまもなく止まる）
     */
    public function status(): array
    {
        $warning = (float) config('blogos.ai.credit.warning_usd');
        $reserve = (float) config('blogos.ai.credit.reserve_usd');
        $base = $this->latestBalance();

        if ($base === null) {
            return ['balance' => null, 'level' => 'unknown', 'base' => null, 'purchases' => 0.0, 'spent' => 0.0, 'warning' => $warning, 'reserve' => $reserve, 'days_since_check' => null];
        }

        $purchases = (float) $this->purchasesAfter($base)->sum('amount');
        $spent = $this->generations->apiCostAfter($base->occurred_at);
        $balance = round($base->amount + $purchases - $spent, 4);

        return [
            'balance'          => $balance,
            // 実行を止めるのは「見込み − 今回の最大の費用 ＜ 残しておく額」のため、残しておく額の2倍以下で「止まる」と知らせる
            'level'            => match (true) {
                $balance <= $reserve * 2 => 'critical',
                $balance <= $warning => 'warning',
                default              => 'ok',
            },
            'base'             => $base,
            'purchases'        => $purchases,
            'spent'            => $spent,
            'warning'          => $warning,
            'reserve'          => $reserve,
            'days_since_check' => (int) $base->occurred_at->diffInDays(now()),
        ];
    }

    /**
     * OpenAI の画面で見た残高を登録する。その時点の BlogOS の見込みも残し、実際との差を示す
     */
    public function recordBalance(float $amount, Carbon $occurredAt, ?string $note, ?int $userId): AiCreditEntry
    {
        return AiCreditEntry::create([
            'type'              => AiCreditEntryType::Balance,
            'amount'            => $amount,
            'occurred_at'       => $occurredAt,
            'estimated_balance' => $this->estimateAt($occurredAt),
            'note'              => $note,
            'created_by'        => $userId,
        ]);
    }

    public function recordPurchase(float $amount, Carbon $occurredAt, ?string $note, ?int $userId): AiCreditEntry
    {
        return AiCreditEntry::create([
            'type'        => AiCreditEntryType::Purchase,
            'amount'      => $amount,
            'occurred_at' => $occurredAt,
            'note'        => $note,
            'created_by'  => $userId,
        ]);
    }

    /**
     * 指定した日時の時点の残高の見込み（それより前に登録した残高が起点。なければ null）
     */
    public function estimateAt(Carbon $at): ?float
    {
        $base = AiCreditEntry::where('type', AiCreditEntryType::Balance)->where('occurred_at', '<=', $at)->latest('occurred_at')->latest('id')->first();
        if ($base === null) {
            return null;
        }

        $purchases = (float) $this->purchasesAfter($base)->where('occurred_at', '<=', $at)->sum('amount');
        $spent = $this->generations->apiCostAfter($base->occurred_at) - $this->generations->apiCostAfter($at);

        return round($base->amount + $purchases - $spent, 4);
    }

    /**
     * @return Collection<int, AiCreditEntry>
     */
    public function history(int $limit = 50): Collection
    {
        return AiCreditEntry::with('creator:id,name')->latest('occurred_at')->latest('id')->limit($limit)->get();
    }

    /**
     * 残高の登録より後の課金（同じ日時なら、後から登録した課金を後とみなす）
     */
    protected function purchasesAfter(AiCreditEntry $base): Builder
    {
        return AiCreditEntry::where('type', AiCreditEntryType::Purchase)
            ->where(fn (Builder $query) => $query->where('occurred_at', '>', $base->occurred_at)
                ->orWhere(fn (Builder $same) => $same->where('occurred_at', $base->occurred_at)->where('id', '>', $base->id)));
    }

    protected function latestBalance(): ?AiCreditEntry
    {
        return AiCreditEntry::where('type', AiCreditEntryType::Balance)->latest('occurred_at')->latest('id')->first();
    }
}
