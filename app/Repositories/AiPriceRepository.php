<?php

namespace App\Repositories;

use App\Enums\AiPriceChangeStatus;
use App\Models\AiPrice;
use App\Models\AiPriceChange;
use App\Models\AiPriceCheck;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * API実行の料金表（ai_prices）と、料金の変更・公式のページとの照合の記録。D-31-03。
 */
class AiPriceRepository
{
    /**
     * @return Collection<string, AiPrice> price_key をキーにする
     */
    public function all(): Collection
    {
        return AiPrice::orderBy('id')->get()->keyBy('price_key');
    }

    public function find(string $priceKey): ?AiPrice
    {
        return AiPrice::where('price_key', $priceKey)->first();
    }

    public function markChecked(AiPrice $price): void
    {
        $price->update(['checked_at' => now()]);
    }

    /**
     * 料金を変えて、変更を記録する
     */
    public function apply(AiPrice $price, string $field, float $value, ?int $userId = null, ?AiPriceChange $pending = null): void
    {
        DB::transaction(function () use ($price, $field, $value, $userId, $pending) {
            $old = $price->getAttribute($field);
            $price->update([$field => $value]);

            $attributes = ['status' => AiPriceChangeStatus::Applied, 'decided_by' => $userId, 'decided_at' => now()];
            if ($pending !== null) {
                $pending->update($attributes);
            } else {
                AiPriceChange::create($attributes + ['price_key' => $price->price_key, 'field' => $field, 'old_value' => $old, 'new_value' => $value]);
            }
        });
    }

    /**
     * 値下がりを、人の確認待ちとして記録する（同じ値の確認待ちがあれば、そのまま）
     */
    public function addPending(AiPrice $price, string $field, float $value): bool
    {
        $existing = $this->pendingFor($price->price_key, $field);
        if ($existing !== null && abs($existing->new_value - $value) < 0.0000005) {
            return false;
        }

        DB::transaction(function () use ($price, $field, $value, $existing) {
            $existing?->update(['status' => AiPriceChangeStatus::Superseded, 'decided_at' => now()]);
            AiPriceChange::create([
                'price_key' => $price->price_key,
                'field'     => $field,
                'old_value' => $price->getAttribute($field),
                'new_value' => $value,
                'status'    => AiPriceChangeStatus::Pending,
            ]);
        });

        return true;
    }

    /**
     * 公式のページの値が料金表と同じ・高くなった項目の確認待ちは、確認しなくてよい
     */
    public function supersedePending(string $priceKey, string $field): void
    {
        AiPriceChange::where('price_key', $priceKey)->where('field', $field)->where('status', AiPriceChangeStatus::Pending)
            ->update(['status' => AiPriceChangeStatus::Superseded, 'decided_at' => now()]);
    }

    public function pendingFor(string $priceKey, string $field): ?AiPriceChange
    {
        return AiPriceChange::where('price_key', $priceKey)->where('field', $field)->where('status', AiPriceChangeStatus::Pending)->latest('id')->first();
    }

    /**
     * @return Collection<int, AiPriceChange>
     */
    public function pending(): Collection
    {
        return AiPriceChange::where('status', AiPriceChangeStatus::Pending)->orderBy('id')->get();
    }

    public function findPending(int $id): ?AiPriceChange
    {
        return AiPriceChange::where('status', AiPriceChangeStatus::Pending)->find($id);
    }

    public function reject(AiPriceChange $change, ?int $userId): void
    {
        $change->update(['status' => AiPriceChangeStatus::Rejected, 'decided_by' => $userId, 'decided_at' => now()]);
    }

    /**
     * @return Collection<int, AiPriceChange>
     */
    public function recentChanges(int $limit = 30): Collection
    {
        return AiPriceChange::with('decider:id,name')->where('status', '!=', AiPriceChangeStatus::Pending)->latest('id')->limit($limit)->get();
    }

    /**
     * この日時より後に反映した変更の数
     */
    public function appliedSince(\DateTimeInterface $since): int
    {
        return AiPriceChange::where('status', AiPriceChangeStatus::Applied)->where('decided_at', '>=', $since)->count();
    }

    public function recordCheck(string $status, array $messages, int $applied, int $pending): AiPriceCheck
    {
        return AiPriceCheck::create(['status' => $status, 'messages' => $messages ?: null, 'applied_count' => $applied, 'pending_count' => $pending]);
    }

    public function latestCheck(): ?AiPriceCheck
    {
        return AiPriceCheck::latest('id')->first();
    }
}
