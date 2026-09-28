<?php

namespace App\Support;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * SVG を、図として安全な形にする（D-32-02）。
 *
 * AI が作った・人が直した SVG から、スクリプト・イベント属性・外部への参照・HTML の埋め込み（foreignObject）を取り除く。
 * BlogOS の画面では <img> で表示するためスクリプトは動かないが、保存する内容そのものも安全にしておく。
 */
class SvgSanitizer
{
    /**
     * 使ってよい要素（図に必要なものだけ）
     */
    protected const ALLOWED_ELEMENTS = [
        'svg', 'g', 'defs', 'title', 'desc', 'symbol', 'use', 'marker', 'clipPath', 'mask', 'pattern',
        'linearGradient', 'radialGradient', 'stop', 'style',
        'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path',
        'text', 'tspan', 'textPath',
    ];

    public const MAX_BYTES = 500_000;

    /**
     * @throws \InvalidArgumentException 読み取れない・大きすぎる SVG
     */
    public static function sanitize(string $svg): string
    {
        $svg = trim($svg);
        // AI がコードブロックで囲んで返すことがあるため、外側の ``` を外す
        $svg = preg_replace('/^```[a-z]*\s*(.*?)\s*```$/su', '$1', $svg) ?? $svg;

        if ($svg === '' || strlen($svg) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('SVG が空か、大きすぎます（500KB まで）。');
        }

        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // 外部の実体（XXE）を読み込まない
        $loaded = $document->loadXML($svg, LIBXML_NONET | LIBXML_NOBLANKS);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded || $document->documentElement === null || $document->documentElement->localName !== 'svg') {
            throw new \InvalidArgumentException('SVG として読み取れませんでした（<svg> で始まる、正しい形の SVG にしてください）。');
        }

        // DOCTYPE（実体の定義）は使わない
        if ($document->doctype !== null) {
            $document->removeChild($document->doctype);
        }

        $xpath = new DOMXPath($document);
        foreach (iterator_to_array($xpath->query('//*')) as $element) {
            /** @var DOMElement $element */
            if (! in_array($element->localName, self::ALLOWED_ELEMENTS, true)) {
                $element->parentNode?->removeChild($element);

                continue;
            }

            foreach (iterator_to_array($element->attributes) as $attribute) {
                $name = strtolower($attribute->nodeName);
                $value = trim($attribute->nodeValue ?? '');
                $isEvent = str_starts_with($name, 'on');
                $isLink = in_array($name, ['href', 'xlink:href', 'src'], true) && ! str_starts_with($value, '#');
                $isScriptUrl = preg_match('/(javascript|vbscript|data)\s*:/i', $value) === 1 && $name !== 'd';
                if ($isEvent || $isLink || $isScriptUrl) {
                    $element->removeAttributeNode($attribute);
                }
            }

            // style の中の外部の読み込み（@import・url(http…)）を取り除く
            if ($element->localName === 'style') {
                $element->textContent = preg_replace(['/@import[^;]*;?/i', '/url\(\s*[\'"]?(?!#)[^)]*\)/i'], '', $element->textContent) ?? '';
            }
        }

        $root = $document->documentElement;
        if (! $root->hasAttribute('xmlns')) {
            $root->setAttribute('xmlns', 'http://www.w3.org/2000/svg');
        }

        return (string) $document->saveXML($root);
    }

    /**
     * 表示する大きさ（width・height、なければ viewBox）
     *
     * @return array{0: int, 1: int}|null
     */
    public static function size(string $svg): ?array
    {
        $number = fn (string $name) => preg_match('/<svg[^>]*\s' . $name . '\s*=\s*["\']\s*([0-9.]+)\s*(px)?["\']/i', $svg, $m) ? (float) $m[1] : null;
        $width = $number('width');
        $height = $number('height');

        if (($width === null || $height === null) && preg_match('/<svg[^>]*\sviewBox\s*=\s*["\']\s*[-0-9.]+[\s,]+[-0-9.]+[\s,]+([0-9.]+)[\s,]+([0-9.]+)\s*["\']/i', $svg, $m)) {
            $width ??= (float) $m[1];
            $height ??= (float) $m[2];
        }

        return $width && $height ? [(int) round($width), (int) round($height)] : null;
    }
}
