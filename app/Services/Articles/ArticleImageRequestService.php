<?php

namespace App\Services\Articles;

use App\Enums\ImageKind;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Image;
use App\Services\Ai\AiException;
use App\Services\Ai\AiOutputParser;
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
        protected AiOutputParser $parser,
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

    /**
     * 付け替えられずに本文に残った [[画像:新規N]] を、この編集案の画像と結び付け直す（D-34-06）。
     *
     * AI が画像の依頼の key を例の説明文ごと書いたため、目印と結び付かなかった編集案を直す。保存済みの AI の回答の画像の依頼を
     * 読み直し（key は読み取りの側で「新規N」にそろう）、画像の名前で、この編集案の画像のうち本文に目印がないものと照合する。
     * 名前で決まらず、残った目印と画像の数が同じなら、順番で結び付ける。
     *
     * @return array{content: string, repaired: int}
     */
    public function repairMarkers(ArticleDraft $draft, string $content): array
    {
        preg_match_all('/\[\[画像:(新規\d+)\]\]/u', $content, $matches);
        $keys = array_values(array_unique($matches[1]));
        $unplaced = Image::where('article_draft_id', $draft->id)->orderBy('id')->get()
            ->reject(fn (Image $image) => str_contains($content, "[[画像:{$image->id}]]"))->values();
        if ($keys === [] || $unplaced->isEmpty()) {
            return ['content' => $content, 'repaired' => 0];
        }

        $map = [];
        $generations = AiGeneration::where(fn ($query) => $query->where('article_draft_id', $draft->id)->orWhere('id', $draft->ai_generation_id))
            ->whereNotNull('output')->latest('id')->get(['id', 'output']);
        foreach ($generations as $generation) {
            try {
                $requests = $this->parser->imageRequests($this->parser->article((string) $generation->output)['画像の依頼'] ?? null);
            } catch (AiException) {
                continue;
            }
            foreach ($requests as $request) {
                if (! in_array($request['key'], $keys, true) || isset($map[$request['key']])) {
                    continue;
                }
                $image = $unplaced->first(fn (Image $image) => $image->title === $request['title'] && ! in_array($image->id, $map, true));
                if ($image !== null) {
                    $map[$request['key']] = $image->id;
                }
            }
        }

        $restKeys = array_values(array_diff($keys, array_keys($map)));
        $restImages = $unplaced->reject(fn (Image $image) => in_array($image->id, $map, true))->values();
        if ($restKeys !== [] && count($restKeys) === $restImages->count()) {
            sort($restKeys, SORT_NATURAL);
            foreach ($restKeys as $index => $key) {
                $map[$key] = $restImages[$index]->id;
            }
        }

        return ['content' => $this->mapKeys($content, $map), 'repaired' => count($map)];
    }
}
