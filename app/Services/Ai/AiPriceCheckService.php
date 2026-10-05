<?php

namespace App\Services\Ai;

use App\Models\AiPrice;
use App\Repositories\AiPriceRepository;
use App\Services\Voice\VoicePrices;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * API実行の料金表を、OpenAIの公式のページと照合する（毎日。D-31-03）。
 *
 * ページはAIを使わずに読み、料金の部分だけを取り出す（料金はかからない）。
 * 値上がりは自動で反映する（費用の目安が高めになる方向で、上限の判定は安全側のため）。
 * 値下がりは、ページの読み違いで使いすぎを止められなくなるのを避けるため、人が確認して反映する。
 * ページから料金を読み取れない場合は、今の料金表のままにして、画面で知らせる。
 * 音声の操作のモデル（聞き取り・返事の声・リアルタイム会話）も照合する（D-68-02）。
 */
class AiPriceCheckService
{
    /**
     * 料金ページの文字（1回の照合で1回だけ読む）
     */
    protected ?string $pricingText = null;

    public function __construct(
        protected AiPriceRepository $prices,
    ) {
    }

    /**
     * @return array{status: string, messages: list<string>, applied: int, pending: int}
     */
    public function check(bool $dryRun = false): array
    {
        $this->pricingText = null;
        $messages = [];
        $applied = 0;
        $pending = 0;
        $failed = false;

        $found = [];
        foreach (array_keys((array) config('blogos.ai.api.models')) as $model) {
            try {
                $found[$model] = $this->modelPrices($model);
            } catch (AiException $e) {
                $failed = true;
                $messages[] = "{$model}：{$e->getMessage()}";
            }
        }
        try {
            $found[AiPrice::WEB_SEARCH] = ['per_call' => $this->webSearchPrice()];
        } catch (AiException $e) {
            $failed = true;
            $messages[] = "Web検索：{$e->getMessage()}";
        }
        // 画像モデル（料金ページの Image generation の表。D-32）
        foreach (array_keys((array) config('blogos.ai.image.models')) as $model) {
            try {
                $found[$model] = $this->imageModelPrices($model);
            } catch (AiException $e) {
                $failed = true;
                $messages[] = "{$model}：{$e->getMessage()}";
            }
        }

        // 音声の操作のモデル（D-68-02）：聞き取りとリアルタイム会話は料金ページの表、返事の声はモデルのページ
        $voiceChecks = [
            [VoicePrices::transcribeModels(), fn (string $model) => ['per_call' => $this->transcriptionPrice($model)]],
            [VoicePrices::ttsModels(), fn (string $model) => $this->ttsPrices($model)],
            [VoicePrices::realtimeModels(), fn (string $model) => $this->realtimePrices($model)],
        ];
        foreach ($voiceChecks as [$models, $read]) {
            foreach ($models as $model) {
                try {
                    $found[$model] = $read($model);
                } catch (AiException $e) {
                    $failed = true;
                    $messages[] = "{$model}：{$e->getMessage()}";
                }
            }
        }

        foreach ($found as $key => $values) {
            $price = $this->prices->find($key) ?? ($dryRun ? new AiPrice(['price_key' => $key]) : AiPrice::create(['price_key' => $key]));
            foreach ($values as $field => $value) {
                $current = $price->getAttribute($field);
                if ($current !== null && abs((float) $current - $value) < 0.0000005) {
                    if (! $dryRun) {
                        $this->prices->supersedePending($key, $field);
                    }

                    continue;
                }

                $label = self::label($key, $field);
                if ($current === null || $value > (float) $current) {
                    $messages[] = "{$label}：{$this->format($current)} → {$this->format($value)}（値上がり。自動で反映" . ($dryRun ? 'する予定' : 'しました') . '）';
                    $applied++;
                    if (! $dryRun) {
                        $this->prices->supersedePending($key, $field);
                        $this->prices->apply($price, $field, $value);
                    }
                } else {
                    $messages[] = "{$label}：{$this->format($current)} → {$this->format($value)}（値下がり。確認してから反映してください）";
                    $pending++;
                    if (! $dryRun) {
                        $this->prices->addPending($price, $field, $value);
                    }
                }
            }
            if (! $dryRun && $price->exists) {
                $this->prices->markChecked($price);
            }
        }

        $status = $failed ? 'failed' : 'succeeded';
        if (! $dryRun) {
            $this->prices->recordCheck($status, $messages, $applied, $pending);
        }

        return ['status' => $status, 'messages' => $messages, 'applied' => $applied, 'pending' => $pending];
    }

    /**
     * モデルのページの料金（1Mトークンあたり）と、長い入力の規則
     *
     * @return array<string, float|int>
     *
     * @throws AiException
     */
    public function modelPrices(string $model): array
    {
        $text = $this->pageText(str_replace('{model}', $model, (string) config('blogos.ai.api.price_check.model_url')));

        if (! preg_match('/Input\s*\$([0-9.]+)\s*Cached input\s*\$([0-9.]+)\s*Cache writes\s*\$([0-9.]+)\s*Output\s*\$([0-9.]+)/u', $text, $m)) {
            throw new AiException('モデルのページから、料金（Input・Cached input・Cache writes・Output）を読み取れませんでした。ページの形が変わった可能性があります。');
        }
        [$input, $cached, $write, $output] = array_map('floatval', array_slice($m, 1, 4));

        // 読み違いを避けるため、ありえない値は使わない
        if ($input <= 0 || $cached <= 0 || $output <= 0 || $cached >= $input || $write < $input || $output > 1000 || $input > 1000) {
            throw new AiException("モデルのページから読み取った料金が、ありえない値でした（入力 {$input}・キャッシュ {$cached}・書き込み {$write}・出力 {$output}）。");
        }

        if (! preg_match('/more than\s*([0-9]+)K\s*input tokens are priced at\s*([0-9.]+)x\s*input and cache rates and\s*([0-9.]+)x\s*output/u', $text, $l)) {
            throw new AiException('モデルのページから、長い入力の規則（more than …K input tokens …）を読み取れませんでした。');
        }

        return [
            'input'                  => $input,
            'cached_input'           => $cached,
            'cache_write'            => $write,
            'output'                 => $output,
            'long_context_threshold' => (int) $l[1] * 1000,
            'long_input_multiplier'  => (float) $l[2],
            'long_output_multiplier' => (float) $l[3],
        ];
    }

    /**
     * Web検索の1回あたりの料金（料金ページの「Web search (all models) $10.00 / 1k calls」）
     *
     * @throws AiException
     */
    public function webSearchPrice(): float
    {
        $text = $this->pricingPageText();

        if (! preg_match('/Web search \(all models\)\s*\$([0-9.]+)\s*\/\s*1k calls/u', $text, $m) || (float) $m[1] <= 0 || (float) $m[1] > 1000) {
            throw new AiException('料金ページから、Web検索の料金（Web search (all models) … / 1k calls）を読み取れませんでした。');
        }

        return round((float) $m[1] / 1000, 6);
    }

    /**
     * 画像モデルの料金（料金ページの「{モデル}Image $入力 $キャッシュ $出力 Text $入力 $キャッシュ」。最初の表が標準の処理）
     *
     * @return array<string, float>
     *
     * @throws AiException
     */
    public function imageModelPrices(string $model): array
    {
        $text = $this->pricingPageText();

        if (! preg_match('/' . preg_quote($model, '/') . '\s*Image\s*\$([0-9.]+)\s*\$([0-9.]+)\s*\$([0-9.]+)\s*Text\s*\$([0-9.]+)\s*\$([0-9.]+)/u', $text, $m)) {
            throw new AiException('料金ページから、画像モデルの料金（Image・Text の入力・キャッシュ・出力）を読み取れませんでした。ページの形が変わった可能性があります。');
        }
        [$imageInput, $imageCached, $output, $textInput, $textCached] = array_map('floatval', array_slice($m, 1, 5));
        if ($imageInput <= 0 || $output <= 0 || $textInput <= 0 || $imageCached > $imageInput || $textCached > $textInput || $output > 1000) {
            throw new AiException("料金ページから読み取った画像モデルの料金が、ありえない値でした（画像の入力 {$imageInput}・出力 {$output}・文章の入力 {$textInput}）。");
        }

        return [
            'input'              => $textInput,
            'cached_input'       => $textCached,
            'image_input'        => $imageInput,
            'image_cached_input' => $imageCached,
            'output'             => $output,
        ];
    }

    /**
     * 聞き取りのモデルの1分あたりの料金（料金ページの Transcription models の表の「{モデル}Transcription … $0.003 / minute」）
     *
     * @throws AiException
     */
    public function transcriptionPrice(string $model): float
    {
        $text = $this->pricingPageText();

        // 入力・出力の列は、料金（$…）か「-」。モデル名のすぐ後の文字（Transcription）で、ほかのモデルと取り違えない
        if (! preg_match('/' . preg_quote($model, '/') . '\s*(?:Live\s*)?Transcription\s*(?:-|\$[0-9.]+)\s*(?:-|\$[0-9.]+)\s*\$([0-9.]+)\s*\/\s*minute/u', $text, $m)) {
            throw new AiException('料金ページから、聞き取りのモデルの料金（Transcription … / minute）を読み取れませんでした。ページの形が変わった可能性があります。');
        }
        $value = (float) $m[1];
        if ($value <= 0 || $value > 1) {
            throw new AiException("料金ページから読み取った聞き取りの料金が、ありえない値でした（1分 {$value}）。");
        }

        return $value;
    }

    /**
     * リアルタイム会話のモデルの料金（料金ページの Realtime and audio generation models の表の「{モデル}Audio $入力 $キャッシュ $出力 Text $入力 $キャッシュ $出力」）
     *
     * @return array<string, float>
     *
     * @throws AiException
     */
    public function realtimePrices(string $model): array
    {
        $text = $this->pricingPageText();

        // モデル名のすぐ後の文字（Audio）で、ほかのモデル（例：gpt-realtime-2.1 と gpt-realtime-2.1-mini）と取り違えない
        if (! preg_match('/' . preg_quote($model, '/') . '\s*Audio\s*\$([0-9.]+)\s*\$([0-9.]+)\s*\$([0-9.]+)\s*Text\s*\$([0-9.]+)\s*\$([0-9.]+)\s*\$([0-9.]+)/u', $text, $m)) {
            throw new AiException('料金ページから、リアルタイム会話のモデルの料金（Audio・Text の入力・キャッシュ・出力）を読み取れませんでした。ページの形が変わった可能性があります。');
        }
        [$audioInput, $audioCached, $audioOutput, $textInput, $textCached, $textOutput] = array_map('floatval', array_slice($m, 1, 6));
        if ($audioInput <= 0 || $audioOutput <= 0 || $textInput <= 0 || $textOutput <= 0 || $audioCached > $audioInput || $textCached > $textInput || $audioOutput > 1000) {
            throw new AiException("料金ページから読み取ったリアルタイム会話の料金が、ありえない値でした（音声の入力 {$audioInput}・出力 {$audioOutput}・文字の入力 {$textInput}）。");
        }

        return [
            'input'              => $textInput,
            'cached_input'       => $textCached,
            'output'             => $textOutput,
            'audio_input'        => $audioInput,
            'audio_cached_input' => $audioCached,
            'audio_output'       => $audioOutput,
        ];
    }

    /**
     * 返事の声のモデルの料金（モデルのページの「Text tokens Per 1M tokens Input $…」「Audio tokens Per 1M tokens Output $…」）
     *
     * @return array<string, float>
     *
     * @throws AiException
     */
    public function ttsPrices(string $model): array
    {
        $text = $this->pageText(str_replace('{model}', $model, (string) config('blogos.ai.api.price_check.model_url')));

        if (! preg_match('/Text tokens\s*Per 1M tokens\s*Input\s*\$([0-9.]+)/u', $text, $input) || ! preg_match('/Audio tokens\s*Per 1M tokens\s*Output\s*\$([0-9.]+)/u', $text, $output)) {
            throw new AiException('モデルのページから、返事の声の料金（Text tokens の Input・Audio tokens の Output）を読み取れませんでした。ページの形が変わった可能性があります。');
        }
        [$textInput, $audioOutput] = [(float) $input[1], (float) $output[1]];
        if ($textInput <= 0 || $audioOutput <= 0 || $textInput > 1000 || $audioOutput > 1000) {
            throw new AiException("モデルのページから読み取った返事の声の料金が、ありえない値でした（文字の入力 {$textInput}・音声の出力 {$audioOutput}）。");
        }

        return ['input' => $textInput, 'audio_output' => $audioOutput];
    }

    /**
     * 料金ページ（1回の照合で1回だけ読む）
     *
     * @throws AiException
     */
    protected function pricingPageText(): string
    {
        return $this->pricingText ??= $this->pageText((string) config('blogos.ai.api.price_check.pricing_url'));
    }

    /**
     * @throws AiException
     */
    protected function pageText(string $url): string
    {
        try {
            $response = Http::timeout(30)->withHeaders(['Accept-Language' => 'en'])->get($url);
        } catch (ConnectionException $e) {
            throw new AiException("ページに接続できませんでした（{$url}）：{$e->getMessage()}");
        }

        if ($response->failed()) {
            Log::warning('OpenAIの料金のページを取得できませんでした。', ['url' => $url, 'status' => $response->status()]);

            throw new AiException("ページを取得できませんでした（{$url}、HTTP {$response->status()}）。");
        }

        return (string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($response->body()), ENT_QUOTES | ENT_HTML5));
    }

    public static function label(string $key, string $field): string
    {
        $fields = [
            'input'                  => '入力',
            'cached_input'           => 'キャッシュ済みの入力',
            'image_input'            => '画像の入力',
            'image_cached_input'     => 'キャッシュ済みの画像の入力',
            'cache_write'            => 'キャッシュの書き込み',
            'output'                 => '出力',
            'per_call'               => '1回あたり',
            'long_context_threshold' => '長い入力の境目（トークン）',
            'long_input_multiplier'  => '長い入力の倍率（入力）',
            'long_output_multiplier' => '長い入力の倍率（出力）',
        ];

        // 音声の操作のモデル（D-68-02）：聞き取りは1分あたり、ほかは文字と音声を分けて呼ぶ
        if (VoicePrices::isVoiceModel($key)) {
            $fields = [
                'per_call'           => '1分あたり',
                'input'              => '文字の入力',
                'cached_input'       => 'キャッシュ済みの文字の入力',
                'output'             => '文字の出力',
                'audio_input'        => '音声の入力',
                'audio_cached_input' => 'キャッシュ済みの音声の入力',
                'audio_output'       => '音声の出力',
            ] + $fields;
        }

        return ($key === AiPrice::WEB_SEARCH ? 'Web検索' : $key) . ' ' . ($fields[$field] ?? $field);
    }

    protected function format(mixed $value): string
    {
        return $value === null ? '未設定' : rtrim(rtrim(number_format((float) $value, 6, '.', ''), '0'), '.');
    }
}
