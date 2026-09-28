<?php

namespace App\Services\Images;

use App\Clients\OpenAi\OpenAiClient;
use App\Clients\OpenAi\OpenAiException;
use App\Enums\AiExecutionMethod;
use App\Enums\AiGenerationStatus;
use App\Enums\AiMode;
use App\Enums\ImageKind;
use App\Enums\ImageSource;
use App\Jobs\GenerateImageJob;
use App\Models\AiGeneration;
use App\Models\Image;
use App\Repositories\AiGenerationRepository;
use App\Services\Ai\AiApiPolicy;
use App\Services\Ai\AiException;

/**
 * 画像モデル（gpt-image 系）で画像を作る（イラスト・アイキャッチ。D-32-01）。
 *
 * API実行だけ（手動なら、ChatGPT 等で作った画像をアップロードする）。AI実行記録（purpose：image_generation）に残し、
 * 費用は応答のトークン数から計算して、残高の見込みに含める。料金がかかるため、失敗しても自動では再実行しない。
 */
class ImageGenerationService
{
    public const TEMPLATE_VERSION = '1.0.0';

    public function __construct(
        protected AiGenerationRepository $generations,
        protected AiApiPolicy $apiPolicy,
        protected ImageService $images,
    ) {
    }

    /**
     * @throws AiException
     */
    public function start(Image $image, ?string $prompt, ?string $quality, ?int $userId): AiGeneration
    {
        $prompt = trim((string) ($prompt ?? $image->image_prompt));
        if ($prompt === '') {
            throw new AiException('画像を作る指示文がありません。');
        }
        if (in_array($image->kind, [ImageKind::Screenshot, ImageKind::Diagram], true)) {
            throw new AiException("{$image->kind->label()}は、画像モデルでは作りません（スクリーンショットはアップロード、図解は SVG で作ります）。");
        }

        $model = (string) config('blogos.ai.image.model');
        $quality ??= (string) config('blogos.ai.image.quality');
        $this->apiPolicy->assertCanRunImage($model, $prompt, $quality);
        $size = (string) config('blogos.ai.image.sizes.' . ($image->kind === ImageKind::Eyecatch ? 'eyecatch' : 'illustration'));

        $image->update(['image_prompt' => $prompt]);

        $generation = $this->generations->create([
            'blog_id'          => $image->blog_id,
            'image_id'         => $image->id,
            'purpose'          => AiMode::ImageGeneration,
            'parameters'       => ['大きさ' => $size, '品質' => $quality],
            'execution_method' => AiExecutionMethod::Api,
            'provider'         => config('blogos.ai.api.provider'),
            'model'            => $model,
            'template_key'     => AiMode::ImageGeneration->value,
            'template_version' => self::TEMPLATE_VERSION,
            'input'            => $prompt,
            'status'           => AiGenerationStatus::Running,
            'requested_by'     => $userId,
        ]);

        GenerateImageJob::dispatch($generation->id)->afterCommit();

        return $generation;
    }

    /**
     * 画像モデルを呼び、画像を保存する（GenerateImageJob から呼ぶ）。失敗は記録し、例外は投げない
     */
    public function run(AiGeneration $generation): void
    {
        if (! $this->generations->claimForApiRun($generation)) {
            return;
        }

        // Queue の処理（queue:work）が、画像の機能を入れる前の設定のまま動いていると、料金を計算できない。料金がかかる前に止める
        if (! isset($this->apiPolicy->imageModels()[(string) $generation->model])) {
            $this->markFailed($generation, "画像モデル {$generation->model} の料金が分かりません。Queue の処理（queue:work）が古い設定のまま動いている可能性があります。queue:work を起動し直してから、画像の画面で作り直してください（料金はかかっていません）。");

            return;
        }

        $image = $generation->loadMissing('image')->image;
        $parameters = (array) $generation->parameters;
        $client = new OpenAiClient((string) config('services.openai.key'), (string) config('services.openai.base_url'), (int) config('blogos.ai.api.timeout'));

        try {
            $result = $client->generateImage((string) $generation->model, $generation->input, (string) ($parameters['大きさ'] ?? '1024x1024'), (string) ($parameters['品質'] ?? 'medium'));
        } catch (OpenAiException $e) {
            $this->generations->update($generation, [
                'status'       => AiGenerationStatus::Failed,
                'error'        => $e->getMessage(),
                'completed_at' => now(),
            ] + ($e->usage !== null ? [
                'input_tokens'   => $e->usage['input_tokens'],
                'output_tokens'  => $e->usage['output_tokens'],
                'estimated_cost' => $this->apiPolicy->imageCost((string) $generation->model, $e->usage['input_tokens'], 0, $e->usage['output_tokens']),
            ] : []));

            return;
        }

        $usage = [
            'input_tokens'   => $result['input_tokens'],
            'output_tokens'  => $result['output_tokens'],
            'estimated_cost' => $this->apiPolicy->imageCost((string) $generation->model, $result['text_input_tokens'], $result['image_input_tokens'], $result['output_tokens']),
        ];

        try {
            if ($image === null) {
                throw new ImageException('対象の画像が見つかりません（削除された可能性があります）。');
            }
            $this->images->storeBytes($image, $result['bytes'], ImageSource::AiImage);
        } catch (ImageException $e) {
            $this->generations->update($generation, $usage + ['status' => AiGenerationStatus::Failed, 'error' => $e->getMessage(), 'completed_at' => now()]);

            return;
        }

        $image->refresh();
        $this->generations->update($generation, $usage + [
            'output'       => "画像を作りました（{$image->width}×{$image->height}、" . number_format((int) $image->file_size / 1024) . 'KB）',
            'status'       => AiGenerationStatus::Succeeded,
            'error'        => null,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(AiGeneration $generation, string $error): void
    {
        if ($generation->status === AiGenerationStatus::Running) {
            $this->generations->update($generation, ['status' => AiGenerationStatus::Failed, 'error' => $error, 'completed_at' => now()]);
        }
    }
}
