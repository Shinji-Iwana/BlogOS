<?php

namespace App\Services\Images;

use App\Enums\AiExecutionMethod;
use App\Enums\ImageKind;
use App\Models\AiGeneration;
use App\Services\Ai\AiException;
use App\Services\Ai\AiOutputParser;

/**
 * 図の作成の結果を、画像の案として保存する（D-32）。AiRunService から、取り込みのトランザクションの中で呼ぶ。
 *
 * SVG の図なら SVG を保存する（PNG にするのは人が画面で行う）。イラストなら指示文を保存し、API実行なら続けて画像モデルで作る
 * （手動実行なら、ChatGPT 等で作った画像をアップロードするか、画面のボタンで画像モデルを使う）。
 */
class ImageAiResultService
{
    public function __construct(
        protected AiOutputParser $parser,
        protected ImageService $images,
        protected ImageGenerationService $generator,
    ) {
    }

    /**
     * @throws AiException
     */
    public function saveDesign(AiGeneration $generation): void
    {
        $image = $generation->loadMissing('image')->image ?? throw new AiException('対象の画像が見つかりません。');
        $parsed = $this->parser->imageDesign((string) $generation->output);

        if ($parsed['format'] === 'svg') {
            try {
                $this->images->saveSvg($image, (string) $parsed['svg']);
            } catch (ImageException $e) {
                throw new AiException("SVG を保存できませんでした：{$e->getMessage()}");
            }
        }

        // AI の案は、人がまだ入れていない項目だけに入れる（人が直した値を消さない）
        $this->images->updateInfo($image, array_filter([
            'kind'         => $parsed['format'] === 'svg' ? ImageKind::Diagram : ImageKind::Illustration,
            'image_prompt' => $parsed['illustration_prompt'],
            'ai_note'      => trim(($parsed['format'] === 'svg' ? 'SVG の図' : 'イラスト') . 'を選びました。' . ($parsed['reason'] ?? '')),
            'alt'          => blank($image->alt) ? $parsed['alt'] : null,
            'caption'      => blank($image->caption) ? $parsed['caption'] : null,
            'filename'     => blank($image->filename) ? $parsed['filename'] : null,
        ], fn ($value) => $value !== null));

        // イラストを選び、API実行だった場合は、続けて画像モデルで作る（形式の判断をAIに任せたため）
        // 画像モデルで作れない場合（残高の見込みなど）も、図の作成の結果（指示文など）は残し、画面から作り直せるようにする
        if ($parsed['format'] === 'illustration' && $generation->execution_method === AiExecutionMethod::Api && filled($parsed['illustration_prompt'])) {
            try {
                $this->generator->start($image->fresh(), $parsed['illustration_prompt'], null, $generation->requested_by);
            } catch (AiException $e) {
                $image->update(['ai_note' => trim("{$image->ai_note}\n画像モデルで作れませんでした：{$e->getMessage()}")]);
            }
        }
    }
}
