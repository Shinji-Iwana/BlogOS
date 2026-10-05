<?php

namespace App\Services\Ai;

use App\Enums\AiMode;
use App\Models\AiPrice;
use App\Repositories\AiGenerationRepository;
use App\Repositories\AiPriceRepository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * API実行の設定・費用の目安・費用の上限（D-07-08、D-24）。
 *
 * 選べるモデルと推論の深さは config/blogos.php の ai.api.models で決める。料金（1Mトークンあたりの米ドル）は、
 * 毎日OpenAIの公式のページと照合するDBの料金表（ai_prices。D-31-03）を使い、DBにないモデルは設定の値を使う。
 * 実際の請求はOpenAIの画面で確認する。
 */
class AiApiPolicy
{
    /**
     * @var array<string, array<string, mixed>>|null
     */
    protected ?array $models = null;

    public function __construct(
        protected AiGenerationRepository $generations,
        protected AiPriceRepository $prices,
        protected AiCreditService $credits,
    ) {
    }

    public function isConfigured(): bool
    {
        return filled(config('services.openai.key'));
    }

    /**
     * @return array<string, array{input: float, cached_input: float, cache_write: float, output: float, efforts: list<string>, long_context: array{threshold_tokens: int, input_multiplier: float, output_multiplier: float}}>
     */
    public function models(): array
    {
        if ($this->models !== null) {
            return $this->models;
        }

        $prices = $this->storedPrices();
        $defaultLong = (array) config('blogos.ai.api.long_context');
        $models = [];
        foreach ((array) config('blogos.ai.api.models', []) as $name => $config) {
            $stored = $prices->get($name);
            $models[$name] = [
                'input'        => $stored?->input ?? $config['input'],
                'cached_input' => $stored?->cached_input ?? $config['cached_input'],
                'cache_write'  => $stored?->cache_write ?? $config['cache_write'] ?? $config['input'],
                'output'       => $stored?->output ?? $config['output'],
                'efforts'      => $config['efforts'],
                'long_context' => [
                    'threshold_tokens'  => $stored?->long_context_threshold ?? (int) ($defaultLong['threshold_tokens'] ?? PHP_INT_MAX),
                    'input_multiplier'  => $stored?->long_input_multiplier ?? (float) ($defaultLong['input_multiplier'] ?? 1),
                    'output_multiplier' => $stored?->long_output_multiplier ?? (float) ($defaultLong['output_multiplier'] ?? 1),
                ],
            ];
        }

        return $this->models = $models;
    }

    /**
     * 画像モデルと料金（1Mトークンあたり。D-32）。料金表（ai_prices）にあればその値
     *
     * @return array<string, array{text_input: float, text_cached_input: float, image_input: float, image_cached_input: float, image_output: float}>
     */
    public function imageModels(): array
    {
        $prices = $this->storedPrices();
        $models = [];
        foreach ((array) config('blogos.ai.image.models', []) as $name => $config) {
            $stored = $prices->get($name);
            $models[$name] = [
                'text_input'         => $stored?->input ?? $config['text_input'],
                'text_cached_input'  => $stored?->cached_input ?? $config['text_cached_input'],
                'image_input'        => $stored?->image_input ?? $config['image_input'],
                'image_cached_input' => $stored?->image_cached_input ?? $config['image_cached_input'],
                'image_output'       => $stored?->output ?? $config['image_output'],
            ];
        }

        return $models;
    }

    /**
     * 画像の品質と、1枚の出力のトークン数の見積もり
     *
     * @return array<string, int>
     */
    public function imageQualities(): array
    {
        return (array) config('blogos.ai.image.qualities');
    }

    /**
     * 画像の生成の費用（応答のトークン数から）
     */
    public function imageCost(string $model, int $textInputTokens, int $imageInputTokens, int $outputTokens): ?float
    {
        $price = $this->imageModels()[$model] ?? null;

        return $price === null ? null : round(($textInputTokens * $price['text_input'] + $imageInputTokens * $price['image_input'] + $outputTokens * $price['image_output']) / 1_000_000, 4);
    }

    /**
     * 画像の生成でかかりうる最大の費用（指示文の文字数と、品質ごとの出力の見積もりから）
     */
    public function imageMaxCost(string $model, string $prompt, string $quality): float
    {
        return (float) $this->imageCost($model, mb_strlen($prompt) * 2, 0, (int) ($this->imageQualities()[$quality] ?? max($this->imageQualities())));
    }

    /**
     * 料金表が変わったときに、読み直す
     */
    public function forgetPrices(): void
    {
        $this->models = null;
    }

    /**
     * @return Collection<string, AiPrice>
     */
    protected function storedPrices(): Collection
    {
        try {
            return $this->prices->all();
        } catch (QueryException) {
            // 料金表のテーブルを作る前（Migrationの前）は、設定の値を使う
            return new Collection();
        }
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
        $long = $price['long_context'];
        $isLong = $inputTokens > (int) $long['threshold_tokens'];
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
        $config = (array) config('blogos.ai.api.web_search');
        $stored = $this->storedPrices()->get(AiPrice::WEB_SEARCH)?->per_call;

        return ['cost_per_call' => $stored ?? (float) $config['cost_per_call']] + $config;
    }

    public function maxOutputTokens(): int
    {
        return (int) config('blogos.ai.api.max_output_tokens');
    }

    /**
     * 月の支出の上限（任意。設定がなければ null で、上限を設けない。D-31-04）
     */
    public function monthlyBudget(): ?float
    {
        $budget = config('blogos.ai.api.monthly_budget_usd');

        return $budget === null || $budget === '' ? null : (float) $budget;
    }

    /**
     * OpenAI の残高の見込み（残高が未登録なら null。D-31-04）
     */
    public function estimatedBalance(): ?float
    {
        return $this->credits->status()['balance'];
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

        $this->assertAffordable($this->maxCost($model, $input, $webSearch));
    }

    /**
     * 画像の生成を実行できるか（D-32）
     *
     * @throws AiApiUnavailableException
     * @throws AiException
     */
    public function assertCanRunImage(string $model, string $prompt, string $quality): void
    {
        if (! $this->isConfigured()) {
            throw new AiApiUnavailableException('OpenAIのAPIキーが設定されていません（.env の OPENAI_API_KEY）。ChatGPT 等で作った画像をアップロードしてください。');
        }
        if (! isset($this->imageModels()[$model])) {
            throw new AiException("画像モデル {$model} は使えません（config/blogos.php の ai.image.models）。");
        }
        if (! isset($this->imageQualities()[$quality])) {
            throw new AiException("画像の品質 {$quality} は選べません。");
        }

        $this->assertAffordable($this->imageMaxCost($model, $prompt, $quality));
    }

    /**
     * 今回の最大の費用で、残高の見込み・月の支出の上限を超えないか確かめる（音声の操作からも使う。D-58）
     *
     * @throws AiApiUnavailableException
     */
    public function assertAffordable(float $max): void
    {
        // OpenAI の残高の見込み：今回の最大の費用を引いて、残しておく額を下回るなら実行しない（残高が未登録なら判定しない。画面で登録を促す）
        $credit = $this->credits->status();
        if ($credit['balance'] !== null && $credit['balance'] - $max < $credit['reserve']) {
            throw new AiApiUnavailableException(sprintf(
                'OpenAI の残高が足りなくなる見込みのため、実行しません（残高の見込み $%.2f − 今回の最大 $%.2f ＜ 残しておく額 $%.2f）。OpenAI の画面（Billing）で残高を確認し、課金した場合は、BlogOS の「AIの費用と残高」に課金した額を登録してください。見込みと実際の残高が違う場合は、実際の残高を登録してください。',
                $credit['balance'],
                $max,
                $credit['reserve']
            ));
        }

        // 月の支出の上限（設定した場合だけ）
        $budget = $this->monthlyBudget();
        $spent = $this->spentThisMonth();
        if ($budget !== null && $spent + $max > $budget) {
            throw new AiApiUnavailableException(sprintf(
                '月の支出の上限を超えるおそれがあるため、実行しません（今月の費用の目安 $%.2f ＋ 今回の最大 $%.2f ＞ 上限 $%.2f）。上限は .env の BLOGOS_AI_MONTHLY_BUDGET_USD で変えられます（空にすると上限なし）。',
                $spent,
                $max,
                $budget
            ));
        }
    }
}
