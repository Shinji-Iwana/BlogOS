<?php

namespace App\Console\Commands;

use App\Enums\AiExecutionMethod;
use App\Models\AiGeneration;
use App\Services\Ai\AiApiPolicy;
use Illuminate\Console\Command;

/**
 * API実行の費用の目安を、保存済みのトークン数と今の料金表で計算し直す（D-31-02）。
 *
 * 料金表の設定の誤り（計算の漏れ）を直したときに使う。OpenAIが料金を変えた場合は、過去の実行はその時の料金で請求されているため、使わない。
 * --dry-run では、変わる金額を表示するだけで保存しない。
 */
class RecalculateAiCosts extends Command
{
    protected $signature = 'ai:recalculate-costs
        {--dry-run : 変わる金額を表示するだけで、保存しない}';

    protected $description = 'API実行の費用の目安を、今の料金表で計算し直す（料金表の設定の誤りを直したとき用）';

    public function handle(AiApiPolicy $policy): int
    {
        $models = array_keys($policy->models());
        $before = 0.0;
        $after = 0.0;
        $changed = 0;

        AiGeneration::where('execution_method', AiExecutionMethod::Api)->whereNotNull('input_tokens')->orderBy('id')
            ->chunkById(200, function ($generations) use ($policy, $models, &$before, &$after, &$changed) {
                foreach ($generations as $generation) {
                    // 応答のモデル名（日付付きなど）を、料金表のモデル名に戻す
                    $model = collect($models)->first(fn ($name) => $generation->model === $name || str_starts_with((string) $generation->model, "{$name}-"));
                    if ($model === null) {
                        continue;
                    }

                    $cost = $policy->cost($model, (int) $generation->input_tokens, (int) $generation->cached_input_tokens, (int) $generation->output_tokens, (int) $generation->web_search_calls);
                    $before += (float) $generation->estimated_cost;
                    $after += (float) $cost;

                    if ($cost !== null && abs($cost - (float) $generation->estimated_cost) >= 0.00005) {
                        $changed++;
                        if (! $this->option('dry-run')) {
                            $generation->update(['estimated_cost' => $cost]);
                        }
                    }
                }
            });

        $this->info(sprintf('%s：%d件の費用の目安が変わります。合計 $%.4f → $%.4f', $this->option('dry-run') ? '確認だけ' : '保存しました', $changed, $before, $after));

        return self::SUCCESS;
    }
}
