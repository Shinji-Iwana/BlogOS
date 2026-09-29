<?php

namespace App\Services\Articles;

use App\Enums\ImageKind;
use App\Models\ArticleDraft;
use App\Models\Image;
use App\Services\Images\ImageService;

/**
 * AIの改修案・新規記事の「画像の依頼」から、画像の登録（案）を作る（D-34）。
 *
 * 図解は、この後 BlogOS が API で図を作る（SVG。費用が小さいため自動）。イラストは、人が比べたいときに画像の画面で作る。
 * スクリーンショットは、人が撮ってアップロードする（撮影の依頼の一覧になる）。本文の [[画像:新規1]] は、作った画像の ID に置き換える。
 */
class ArticleImageRequestService
{
    public function __construct(
        protected ImageService $images,
    ) {
    }

    /**
     * @param list<array{key: string, kind: string, title: string, description: string, alt: string|null, illustration_prompt: string|null}> $requests
     * @return array{map: array<string, int>, images: list<Image>, notes: list<string>}
     */
    public function create(ArticleDraft $draft, array $requests, ?int $userId): array
    {
        $draft->loadMissing('blog');
        $limits = (array) config('blogos.article_html.' . $draft->blog->quality_profile . '.image_limits', []);

        $map = [];
        $images = [];
        $notes = [];
        $counts = [];
        foreach ($requests as $request) {
            if (isset($map[$request['key']])) {
                continue;
            }
            $kind = ImageKind::from($request['kind']);
            $counts[$kind->value] = ($counts[$kind->value] ?? 0) + 1;
            if (isset($limits[$kind->value]) && $counts[$kind->value] > (int) $limits[$kind->value]) {
                $notes[] = "{$kind->label()}の依頼が上限（{$limits[$kind->value]}件）を超えたため、「{$request['title']}」は作りませんでした（本文の [[画像:{$request['key']}]] を削除してください）。";

                continue;
            }

            $image = $this->images->create($draft->blog, $kind, $request['title'], $request['description'] ?: null, $userId, array_filter([
                'article_draft_id' => $draft->id,
                'alt'              => $request['alt'],
                'image_prompt'     => $request['illustration_prompt'],
            ], fn ($value) => $value !== null));

            $map[$request['key']] = $image->id;
            $images[] = $image;
        }

        return ['map' => $map, 'images' => $images, 'notes' => $notes];
    }

    /**
     * 本文の [[画像:新規1]] を、作った画像の ID の目印にする
     *
     * @param array<string, int> $map
     */
    public function mapKeys(string $content, array $map): string
    {
        foreach ($map as $key => $id) {
            $content = str_replace("[[画像:{$key}]]", "[[画像:{$id}]]", $content);
        }

        return $content;
    }
}
