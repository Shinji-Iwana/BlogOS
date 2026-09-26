<?php

namespace App\Services\Ai;

use App\Enums\Judgment;

/**
 * AIの出力を読み取る（出力の形式は resources/ai/templates/ の各テンプレートで指定している）。
 */
class AiOutputParser
{
    /**
     * 品質診断の出力（```json のコードブロック）
     *
     * @return array{judgments: array<string, Judgment>, comments: array<string, string>, summary: string|null}
     *
     * @throws AiException
     */
    public function diagnosis(string $output): array
    {
        $output = $this->withoutCitationMarkers($output);
        $json = preg_match('/```json\s*(\{.*\})\s*```/su', $output, $matches) ? $matches[1] : $this->outermostObject($output);
        $data = $json !== null ? json_decode($json, true) : null;

        if (! is_array($data) || (! isset($data['items']) && ! isset($data['required']))) {
            throw new AiException('出力からJSON（required・items）を読み取れませんでした。テンプレートの「出力の形式」どおりか確認してください。');
        }

        $judgments = [];
        $comments = [];
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

        return ['judgments' => $judgments, 'comments' => $comments, 'summary' => trim($summary) ?: null];
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

    protected function outermostObject(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        return $start !== false && $end !== false && $end > $start ? substr($text, $start, $end - $start + 1) : null;
    }
}
