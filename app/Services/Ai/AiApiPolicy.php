<?php

namespace App\Services\Ai;

use App\Enums\AiMode;
use App\Repositories\AiGenerationRepository;
use Illuminate\Support\Carbon;

/**
 * API実行の設定・費用の目安・費用の上限（D-07-08、D-24）。
 *
 * 料金は config/blogos.php の ai.api.models（1Mトークンあたりの米ドル）で計算する。実際の請求はOpenAIの画面で確認する。
 */
class AiApiPolicy
{
    public function __construct(
        protected AiGenerationRepository $generations,
    ) {
    }

    public function isConfigured(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * @return array<string, array{input: float, cached_input: float, cache_write: float, output: float, efforts: list<string>}>
     */
    public function models(): array
    {
        return (array) config('blogos.ai.api.models', []);
    }

    /**
     * @return array{model: string, effort: string}
     */
    public function defaults(AiMode $mode): array
    {
        return (array) config("blogos.ai.api.defaults.{$mode->value}");
    }

    /**
     * 画面で選んだモデル・推論の深さが、選べるものか確かめる
     *
     * @throws AiException
     */
    public function assertSelectable(string $model, string $effort): void
    {
        $models = $this->models();
        if (! isset($models[$model])) {
            throw new AiException("モデル {$model} は選べません（config/blogos.php の ai.api.models）。");
        }
        if (! in_array($effort, $models[$model]['efforts'], true)) {
            throw new AiException("モデル {$model} では、推論の深さ {$effort} を選べません。");
        }
    }

    /**
     * 費用の目安（米ドル）。キャッシュが効いた入力と、それ以外の入力は単価が違う。推論のトークンは出力に含まれる
     */
    public function cost(string $model, int $inputTokens, int $cachedInputTokens, int $outputTokens, int $webSearchCalls = 0): ?float
    {
        $price = $this->models()[$model] ?? null;
        if ($price === null) {
            return null;
        }

        // キャッシュされていない入力は、OpenAIが自動でキャッシュに書き込むため、書き込みの料金になる（短い入力はキャッシュされない。D-31-01）
        $cached = min($cachedInputTokens, $inputTokens);
        $uncachedRate = $inputTokens >= (int) config('blogos.ai.api.cache_min_tokens', 1024) ? ($price['cache_write'] ?? $price['input']) : $price['input'];

        // 長い入力は、その1回すべてを高い料金で計算する
        $long = (array) config('blogos.ai.api.long_context');
        $isLong = $inputTokens > (int) ($long['threshold_tokens'] ?? PHP_INT_MAX);
        $inputMultiplier = $isLong ? (float) $long['input_multiplier'] : 1.0;
        $outputMultiplier = $isLong ? (float) $long['output_multiplier'] : 1.0;

        return round((($inputTokens - $cached) * $uncachedRate * $inputMultiplier + $cached * $price['cached_input'] * $inputMultiplier + $outputTokens * $price['output'] * $outputMultiplier) / 1_000_000
            + $webSearchCalls * $this->webSearch()['cost_per_call'], 4);
    }

    /**
     * 1回の実行でかかりうる最大の費用。入力のトークン数は、多めに見積もるため文字数とする（日本語は1文字1トークン前後）。
     * Web検索を使う場合は、検索の回数の上限までの料金を加える（検索の結果もトークンとして入力に加わるため、入力を倍に見積もる）
     */
    public function maxCost(string $model, string $input, bool $webSearch = false): float
    {
        $inputTokens = mb_strlen($input) * ($webSearch ? 2 : 1);

        return (float) $this->cost($model, $inputTokens, 0, $this->maxOutputTokens(), $webSearch ? $this->webSearch()['max_calls'] : 0);
    }

    /**
     * Web検索の設定（D-30）
     *
     * @return array{tool: string, cost_per_call: float, max_calls: int}
     */
    public function webSearch(): array
    {
        return (array) config('blogos.ai.api.web_search');
    }

    public function maxOutputTokens(): int
    {
        return (int) config('blogos.ai.api.max_output_tokens');
    }

    public function monthlyBudget(): float
    {
        return (float) config('blogos.ai.api.monthly_budget_usd');
    }

    /**
     * 今月（日本時間の月初から）のAPI実行の費用の目安
     */
    public function spentThisMonth(): float
    {
        $from = Carbon::now(config('blogos.display_timezone'))->startOfMonth()->utc();

        return $this->generations->apiCostSince($from);
    }

    /**
     * @throws AiApiUnavailableException
     */
    public function assertCanRun(string $model, string $input, bool $webSearch = false): void
    {
        if (! $this->isConfigured()) {
            throw new AiApiUnavailableException('OpenAIのAPIキーが設定されていません（.env の OPENAI_API_KEY）。手動実行を選ぶか、APIキーを設定してください。');
        }

        $spent = $this->spentThisMonth();
        $max = $this->maxCost($model, $input, $webSearch);
        $budget = $this->monthlyBudget();

        if ($spent + $max > $budget) {
            throw new AiApiUnavailableException(sprintf(
                '月の費用の上限を超えるおそれがあるため、実行しません（今月の費用の目安 $%.2f ＋ 今回の最大 $%.2f ＞ 上限 $%.2f）。上限は .env の BLOGOS_AI_MONTHLY_BUDGET_USD で変えられます。',
                $spent,
                $max,
                $budget
            ));
        }
    }
}
