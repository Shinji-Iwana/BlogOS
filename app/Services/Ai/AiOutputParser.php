<?php

namespace App\Services\Ai;

use App\Enums\Judgment;
use App\Enums\MaterialKind;
use App\Models\Material;
use App\Support\AffiliateLink;

/**
 * AIの出力を読み取る（出力の形式は resources/ai/templates/ の各テンプレートで指定している）。
 */
class AiOutputParser
{
    /**
     * 品質診断の出力（```json のコードブロック）
     *
     * @return array{judgments: array<string, Judgment>, comments: array<string, string>, findings: array<string, array{location: string|null, problem: string|null, fix: string|null}>, summary: string|null}
     *
     * @throws AiException
     */
    public function diagnosis(string $output): array
    {
        $output = $this->withoutCitationMarkers($output);
        $json = preg_match('/```json\s*(\{.*\})\s*```/su', $output, $matches) ? $matches[1] : $this->outermostObject($output);
        $data = $json !== null ? $this->decode($json) : null;

        if (! is_array($data) || (! isset($data['items']) && ! isset($data['required']))) {
            throw new AiException('出力からJSON（required・items）を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $judgments = [];
        $comments = [];
        $findings = [];
        foreach (['required', 'items'] as $group) {
            foreach ((array) ($data[$group] ?? []) as $key => $value) {
                $judgment = Judgment::fromSymbol(is_array($value) ? ($value['judgment'] ?? null) : (string) $value);
                if ($judgment === null) {
                    continue;
                }
                $judgments[(string) $key] = $judgment;
                if (is_array($value) && filled($value['comment'] ?? null)) {
                    $comments[(string) $key] = (string) $value['comment'];
                }
                // 指摘：どこが・何が足りないか・どう直すか（品質基準 2.0.0。D-47）
                if (is_array($value) && (filled($value['location'] ?? null) || filled($value['problem'] ?? null) || filled($value['fix'] ?? null))) {
                    $findings[(string) $key] = [
                        'location' => $this->text($value['location'] ?? null),
                        'problem'  => $this->text($value['problem'] ?? null),
                        'fix'      => $this->text($value['fix'] ?? null),
                    ];
                }
            }
        }

        if ($judgments === []) {
            throw new AiException('出力に判定（○・△・×・要人間確認）が1つもありませんでした。');
        }

        $summary = trim((string) ($data['summary'] ?? ''));
        $improvements = array_filter(array_map('strval', (array) ($data['improvements'] ?? [])));
        if ($improvements !== []) {
            $summary .= "\n\n改善点：\n- " . implode("\n- ", $improvements);
        }

        return ['judgments' => $judgments, 'comments' => $comments, 'findings' => $findings, 'summary' => trim($summary) ?: null];
    }

    /**
     * 管理情報の案の出力（```json のコードブロック。D-27）
     *
     * @return array{article_type: string|null, article_subtype: string|null, main_keyword: string|null, sub_keywords: list<string>, main_search_intent: string|null, sub_search_intents: list<string>, target_versions: string|null, reason: string|null}
     *
     * @throws AiException
     */
    public function managementSuggestion(string $output): array
    {
        $output = $this->withoutCitationMarkers($output);
        $json = preg_match('/```json\s*(\{.*\})\s*```/su', $output, $matches) ? $matches[1] : $this->outermostObject($output);
        $data = $json !== null ? $this->decode($json) : null;

        if (! is_array($data) || ! array_key_exists('main_keyword', $data)) {
            throw new AiException('出力からJSON（main_keyword など）を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $text = fn ($value) => is_string($value) && trim($value) !== '' && strtolower(trim($value)) !== 'null' ? trim($value) : null;
        $list = fn ($value, int $max) => array_slice(array_values(array_filter(array_map($text, (array) $value))), 0, $max);

        return [
            'article_type'       => $text($data['article_type'] ?? null),
            'article_subtype'    => $text($data['article_subtype'] ?? null),
            'main_keyword'       => $text($data['main_keyword'] ?? null),
            'sub_keywords'       => $list($data['sub_keywords'] ?? [], 5),
            'main_search_intent' => $text($data['main_search_intent'] ?? null),
            'sub_search_intents' => $list($data['sub_search_intents'] ?? [], 3),
            'target_versions'    => is_array($data['target_versions'] ?? null) ? (implode('、', $list($data['target_versions'], 10)) ?: null) : $text($data['target_versions'] ?? null),
            'reason'             => $text($data['reason'] ?? null),
        ];
    }

    /**
     * 教材の調査の出力（```json のコードブロック。D-30）
     *
     * @return array{material: array<string, mixed>, availability: string|null, newer: list<array<string, mixed>>, sources: list<string>, reason: string|null}
     *
     * @throws AiException
     */
    public function materialResearch(string $output): array
    {
        $data = $this->json($output);
        if (! is_array($data['material'] ?? null)) {
            throw new AiException('出力からJSON（material）を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $sources = $this->urls($data['sources'] ?? []);
        $newer = [];
        foreach (array_slice((array) ($data['newer'] ?? []), 0, 10) as $item) {
            $candidate = is_array($item) ? $this->material($item) : null;
            if ($candidate !== null && $candidate['name'] !== null) {
                $newer[] = $candidate + [
                    'relation' => in_array($item['relation'] ?? null, ['new_edition', 'successor', 'same_author'], true) ? $item['relation'] : 'same_author',
                    'reason'   => $this->text($item['reason'] ?? null),
                ];
            }
        }

        return [
            'material'     => ['sources' => $sources] + $this->material($data['material']),
            'availability' => $this->text($data['material']['availability'] ?? null),
            'newer'        => $newer,
            'sources'      => $sources,
            'reason'       => $this->text($data['reason'] ?? null),
        ];
    }

    /**
     * 教材の候補探しの出力（D-30）
     *
     * @return array{candidates: list<array<string, mixed>>, reason: string|null}
     *
     * @throws AiException
     */
    public function materialCandidates(string $output): array
    {
        $data = $this->json($output);
        if (! array_key_exists('candidates', $data)) {
            throw new AiException('出力からJSON（candidates）を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $candidates = [];
        foreach (array_slice((array) $data['candidates'], 0, 20) as $item) {
            $candidate = is_array($item) ? $this->material($item) : null;
            if ($candidate !== null && $candidate['name'] !== null) {
                $candidates[] = $candidate + ['sources' => $this->urls($item['sources'] ?? []), 'reason' => $this->text($item['reason'] ?? null)];
            }
        }

        return ['candidates' => $candidates, 'reason' => $this->text($data['reason'] ?? null)];
    }

    /**
     * 記事の教材の見直しの出力（D-30）
     *
     * @param array<int, int> $allowedIds 使ってよい教材ID（候補と、今使っている教材）
     * @return array{current: list<array{material_id: int, judgment: string, replace_with: int|null, reason: string|null}>, additions: list<array{material_id: int, reason: string|null}>, summary: string|null}
     *
     * @throws AiException
     */
    public function materialReview(string $output, array $allowedIds): array
    {
        $data = $this->json($output);
        if (! array_key_exists('current', $data) && ! array_key_exists('additions', $data)) {
            throw new AiException('出力からJSON（current・additions）を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $id = fn ($value) => is_numeric($value) && in_array((int) $value, $allowedIds, true) ? (int) $value : null;

        $current = [];
        foreach ((array) ($data['current'] ?? []) as $item) {
            if (! is_array($item) || ($materialId = $id($item['material_id'] ?? null)) === null) {
                continue;
            }
            $judgment = in_array($item['judgment'] ?? null, ['keep', 'replace', 'remove'], true) ? $item['judgment'] : 'keep';
            $replaceWith = $judgment === 'replace' ? $id($item['replace_with'] ?? null) : null;
            $current[] = [
                'material_id'  => $materialId,
                'judgment'     => $judgment === 'replace' && $replaceWith === null ? 'remove' : $judgment,
                'replace_with' => $replaceWith,
                'reason'       => $this->text($item['reason'] ?? null),
            ];
        }

        $additions = [];
        foreach ((array) ($data['additions'] ?? []) as $item) {
            if (is_array($item) && ($materialId = $id($item['material_id'] ?? null)) !== null) {
                $additions[] = ['material_id' => $materialId, 'reason' => $this->text($item['reason'] ?? null)];
            }
        }

        return ['current' => $current, 'additions' => $additions, 'summary' => $this->text($data['summary'] ?? null)];
    }

    /**
     * 図の作成の出力（D-32）
     *
     * @return array{format: string, reason: string|null, title: string|null, svg: string|null, illustration_prompt: string|null, alt: string|null, caption: string|null, filename: string|null}
     *
     * @throws AiException
     */
    public function imageDesign(string $output): array
    {
        $data = $this->json($output);
        $format = in_array($data['format'] ?? null, ['svg', 'illustration'], true) ? $data['format'] : null;
        if ($format === null) {
            throw new AiException('出力から format（svg または illustration）を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $svg = is_string($data['svg'] ?? null) && str_contains($data['svg'], '<svg') ? trim($data['svg']) : null;
        if ($format === 'svg' && $svg === null) {
            throw new AiException('format が svg ですが、出力に SVG のコードがありませんでした。');
        }

        return [
            'format'              => $format,
            'reason'              => $this->text($data['reason'] ?? null),
            'title'               => $this->text($data['title'] ?? null),
            'svg'                 => $format === 'svg' ? $svg : null,
            'illustration_prompt' => $this->text($data['illustration_prompt'] ?? null),
            'alt'                 => $this->text($data['alt'] ?? null),
            'caption'             => $this->text($data['caption'] ?? null),
            'filename'            => $this->text($data['filename'] ?? null),
        ];
    }

    /**
     * 教材の情報（materials の列と同じ名前）。値の形を整え、選べない値は除く
     *
     * @return array<string, mixed>
     */
    protected function material(array $item): array
    {
        $list = fn ($value, int $max) => array_slice(array_values(array_filter(array_map(fn ($v) => $this->text(is_scalar($v) ? (string) $v : null), (array) $value))), 0, $max);
        $choices = fn ($value, array $allowed) => array_values(array_intersect(array_unique(array_map('strval', array_filter((array) $value, 'is_scalar'))), array_keys($allowed)));

        $isbn = preg_replace('/[^0-9X]/i', '', (string) ($item['isbn'] ?? ''));
        $isbn = strlen($isbn) === 10 ? AffiliateLink::isbn13FromAsin(strtoupper($isbn)) : (strlen($isbn) === 13 ? $isbn : null);

        // 書籍の Amazon・楽天の商品ページは、それぞれの欄に分ける（product_url に入っていた場合も。D-30-09）
        $productUrl = $this->urls([$item['product_url'] ?? null])[0] ?? null;
        $isStorePage = AffiliateLink::amazonProductUrl($productUrl) !== null || AffiliateLink::rakutenProductUrl($productUrl) !== null;

        return [
            'kind'            => MaterialKind::tryFrom((string) ($item['kind'] ?? ''))?->value,
            'name'            => $this->text($item['name'] ?? null),
            'creator'         => $this->text($item['creator'] ?? null),
            'publisher'       => $this->text($item['publisher'] ?? null),
            'edition'         => $this->text($item['edition'] ?? null),
            'published_on'    => $this->date($item['published_on'] ?? null),
            'isbn'            => $isbn,
            'product_url'         => $isStorePage ? null : $productUrl,
            'amazon_product_url'  => AffiliateLink::amazonProductUrl($this->urls([$item['amazon_product_url'] ?? null])[0] ?? $productUrl),
            'rakuten_product_url' => AffiliateLink::rakutenProductUrl($this->urls([$item['rakuten_product_url'] ?? null])[0] ?? $productUrl),
            'category_ids'    => array_values(array_unique(array_map('intval', array_filter((array) ($item['category_ids'] ?? []), 'is_numeric')))),
            'topics'          => $list($item['topics'] ?? [], 10),
            'target_versions' => $list($item['target_versions'] ?? [], 10),
            'levels'          => $choices($item['levels'] ?? [], Material::LEVELS),
            'scenes'          => $choices($item['scenes'] ?? [], Material::SCENES),
            'summary'         => $this->text($item['summary'] ?? null),
            'target_readers'  => $this->text($item['target_readers'] ?? null),
            'not_for'         => $this->text($item['not_for'] ?? null),
            'merits'          => $list($item['merits'] ?? [], 6),
            'cautions'        => $list($item['cautions'] ?? [], 6),
            'cost_note'       => $this->text($item['cost_note'] ?? null),
            'duration_note'   => $this->text($item['duration_note'] ?? null),
        ];
    }

    /**
     * @throws AiException
     */
    protected function json(string $output): array
    {
        $output = $this->withoutCitationMarkers($output);
        $json = preg_match('/```json\s*(\{.*\})\s*```/su', $output, $matches) ? $matches[1] : $this->outermostObject($output);
        $data = $json !== null ? $this->decode($json) : null;

        if (! is_array($data)) {
            throw new AiException('出力からJSONを読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        return $data;
    }

    protected function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' && strtolower(trim($value)) !== 'null' ? trim($value) : null;
    }

    /**
     * YYYY-MM-DD・YYYY-MM・YYYY を日付にする（月・日が分からない場合は1日・1月とする）
     */
    protected function date(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^(\d{4})(?:[-\/年](\d{1,2}))?(?:[-\/月](\d{1,2}))?/u', trim($value), $m)) {
            return null;
        }

        $year = (int) $m[1];
        $month = (int) ($m[2] ?? 1) ?: 1;
        $day = (int) ($m[3] ?? 1) ?: 1;

        return checkdate($month, $day, $year) && $year >= 1980 ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * @return list<string> http(s) のURLだけ
     */
    protected function urls(mixed $values): array
    {
        return array_values(array_unique(array_filter(array_map(
            fn ($url) => is_string($url) && preg_match('#^https?://[^\s]+$#i', trim($url)) ? trim($url) : null,
            (array) $values
        ))));
    }

    /**
     * 記事改修・新規記事作成の出力（=== 見出し === で区切った節）
     *
     * @return array<string, string> 見出し => 内容（タイトル・スラッグ・抜粋・本文・変更点・自己評価・確認が必要な点）
     *
     * @throws AiException
     */
    public function article(string $output): array
    {
        $parts = preg_split('/^===\s*(.+?)\s*===\s*$/mu', str_replace("\r\n", "\n", $this->withoutCitationMarkers($output)), -1, PREG_SPLIT_DELIM_CAPTURE);

        $sections = [];
        for ($i = 1; $i < count($parts); $i += 2) {
            $sections[trim($parts[$i])] = trim($parts[$i + 1] ?? '');
        }

        if (blank($sections['本文'] ?? null) || blank($sections['タイトル'] ?? null)) {
            throw new AiException('出力から「=== タイトル ===」と「=== 本文 ===」を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        // 本文をコードブロックで囲んで返すことがあるため、外側の ``` を外す
        $sections['本文'] = preg_replace('/^```[a-z]*\n(.*)\n```$/su', '$1', $sections['本文']);

        return $sections;
    }

    /**
     * 記事の企画の出力（D-40）
     *
     * @return array{summary: string|null, articles: list<array<string, mixed>>, categories: list<array<string, mixed>>}
     *
     * @throws AiException
     */
    public function topicPlanning(string $output): array
    {
        $json = preg_match('/```(?:json)?\s*(.*?)```/su', $output, $m) ? $m[1] : $this->outermostObject($output);
        $data = $json !== null ? $this->decode($json) : null;
        if (! is_array($data)) {
            throw new AiException('出力からJSONを読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $article = function (mixed $item): ?array {
            if (! is_array($item) || blank($item['title'] ?? null)) {
                return null;
            }

            return [
                'title'           => mb_substr(trim((string) $item['title']), 0, 255),
                'main_keyword'    => filled($item['main_keyword'] ?? null) ? mb_substr(trim((string) $item['main_keyword']), 0, 191) : null,
                'sub_keywords'    => array_values(array_filter(array_map(fn ($value) => is_string($value) ? trim($value) : null, (array) ($item['sub_keywords'] ?? [])))),
                'search_intent'   => filled($item['search_intent'] ?? null) ? trim((string) $item['search_intent']) : null,
                'article_type'    => filled($item['article_type'] ?? null) ? mb_substr((string) $item['article_type'], 0, 50) : null,
                'article_subtype' => filled($item['article_subtype'] ?? null) ? mb_substr((string) $item['article_subtype'], 0, 50) : null,
                'roadmap_step'    => filled($item['roadmap_step'] ?? null) ? mb_substr(trim((string) $item['roadmap_step']), 0, 255) : null,
                'priority'        => in_array($item['priority'] ?? null, ['high', 'medium', 'low'], true) ? $item['priority'] : null,
                'reason'          => filled($item['reason'] ?? null) ? trim((string) $item['reason']) : null,
                'sources'         => $this->urls($item['sources'] ?? []),
            ];
        };

        $categories = [];
        foreach ((array) ($data['categories'] ?? []) as $item) {
            if (! is_array($item) || blank($item['name'] ?? null)) {
                continue;
            }
            $categories[] = [
                'name'           => mb_substr(trim((string) $item['name']), 0, 255),
                'slug'           => filled($item['slug'] ?? null) ? mb_substr(preg_replace('/[^a-z0-9-]/', '', strtolower((string) $item['slug'])), 0, 100) : null,
                'scope'          => filled($item['scope'] ?? null) ? trim((string) $item['scope']) : null,
                'position'       => filled($item['position'] ?? null) ? mb_substr(trim((string) $item['position']), 0, 255) : null,
                'priority'       => in_array($item['priority'] ?? null, ['high', 'medium', 'low'], true) ? $item['priority'] : null,
                'reason'         => filled($item['reason'] ?? null) ? trim((string) $item['reason']) : null,
                'sources'        => $this->urls($item['sources'] ?? []),
                'first_articles' => array_values(array_filter(array_map($article, (array) ($item['first_articles'] ?? [])))),
            ];
        }

        return [
            'summary'    => filled($data['summary'] ?? null) ? trim((string) $data['summary']) : null,
            'articles'   => array_values(array_filter(array_map($article, (array) ($data['articles'] ?? [])))),
            'categories' => $categories,
        ];
    }

    /**
     * 記事改修・新規記事作成の「=== 画像の依頼 ===」の節（JSON の配列。D-34）。書かれていない・読み取れない場合は空
     *
     * @return list<array{key: string, kind: string, title: string, description: string, alt: string|null, illustration_prompt: string|null}>
     */
    public function imageRequests(?string $section): array
    {
        $section = trim((string) $section);
        if ($section === '' || preg_match('/^(なし|none|\[\s*\])$/iu', $section)) {
            return [];
        }

        $json = preg_match('/```(?:json)?\s*(.*?)```/su', $section, $m) ? $m[1] : $section;
        $start = strpos($json, '[');
        $end = strrpos($json, ']');
        $data = $start !== false && $end !== false && $end > $start ? $this->decode(substr($json, $start, $end - $start + 1)) : null;
        if (! is_array($data)) {
            return [];
        }

        $requests = [];
        foreach ($data as $item) {
            if (! is_array($item)) {
                continue;
            }
            $key = trim((string) ($item['key'] ?? ''));
            $kind = (string) ($item['kind'] ?? '');
            $title = trim((string) ($item['title'] ?? ''));
            if ($key === '' || $title === '' || ! in_array($kind, ['diagram', 'illustration', 'screenshot'], true)) {
                continue;
            }
            $requests[] = [
                'key'                 => self::imageKey($key),
                'kind'                => $kind,
                'title'               => mb_substr($title, 0, 255),
                'description'         => trim((string) ($item['description'] ?? '')),
                'alt'                 => filled($item['alt'] ?? null) ? trim((string) $item['alt']) : null,
                'illustration_prompt' => filled($item['illustration_prompt'] ?? null) ? trim((string) $item['illustration_prompt']) : null,
            ];
        }

        return $requests;
    }

    /**
     * ChatGPTの画面からコピーすると入る出典の目印（:contentReference[oaicite:0]{index=0} など）を取り除く。
     * 保存しても表示で意味を持たないため（D-22-11）。
     */
    protected function withoutCitationMarkers(string $text): string
    {
        return preg_replace([
            '/\s?:contentReference\[oaicite:\d+\]\{index=\d+\}/u',
            '/【oaicite:\d+】/u',
            '/\x{E200}cite\x{E202}[^\x{E201}]*\x{E201}/u',
        ], '', $text) ?? $text;
    }

    /**
     * JSONを読み取る。AIがよくする小さな書き間違い（閉じかっこの直前の余分な「,」）は、直してから読み取る
     */
    /**
     * 画像の依頼の key を、本文の目印の形（新規1）にする。AI が例の説明文ごと書いた場合（「新規1（本文の [[画像:新規1]] と同じ）」）や、
     * 数字だけ（「1」）の場合も、本文の [[画像:新規1]] と結び付くようにする（D-34-06）
     */
    public static function imageKey(string $key): string
    {
        $key = trim($key);
        if (preg_match('/新規\s*(\d+)/u', $key, $m)) {
            return "新規{$m[1]}";
        }
        if (preg_match('/^\d+$/', $key)) {
            return "新規{$key}";
        }

        return mb_substr(preg_replace('/^\[\[画像:|\]\]$/u', '', $key), 0, 30);
    }

    protected function decode(string $json): mixed
    {
        $data = json_decode($json, true);
        if ($data !== null || json_last_error() === JSON_ERROR_NONE) {
            return $data;
        }

        // 文字列の中の「,」は変えないよう、文字列とそれ以外に分けて直す
        $fixed = preg_replace_callback('/"(?:[^"\\\\]|\\\\.)*"|[^"]+/s', fn ($m) => $m[0][0] === '"' ? $m[0] : preg_replace('/,(\s*[}\]])/', '$1', $m[0]), $json);
        $data = $fixed !== null ? json_decode($fixed, true) : null;
        if ($data !== null || $fixed === null) {
            return $data;
        }

        // 最後に余分な「}」「]」がある（全体を閉じた後に、もう一度閉じている）場合は、最初の値が閉じたところまでを読む
        $balanced = $this->firstBalancedValue($fixed);

        return $balanced !== null && $balanced !== trim($fixed) ? json_decode($balanced, true) : null;
    }

    /**
     * 先頭の { または [ から、対応する閉じ括弧までの部分（文字列の中の括弧は数えない）。閉じていなければ null
     */
    protected function firstBalancedValue(string $json): ?string
    {
        $json = trim($json);
        if ($json === '' || ! in_array($json[0], ['{', '['], true)) {
            return null;
        }

        $depth = 0;
        $inString = false;
        $length = strlen($json);
        for ($i = 0; $i < $length; $i++) {
            $char = $json[$i];
            if ($inString) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }
            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif (($char === '}' || $char === ']') && --$depth === 0) {
                return substr($json, 0, $i + 1);
            }
        }

        return null;
    }

    protected function outermostObject(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start !== false && $end !== false && $end > $start ? substr($text, $start, $end - $start + 1) : null;
    }
}
