<?php

namespace App\Services\Ai;

use App\Enums\AiMode;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * AI実行テンプレート（resources/ai/templates/。書き方は同じフォルダの README.md）。
 */
class AiTemplate
{
    /**
     * @param array<int, string> $qualityFiles 品質基準のファイル（resources/quality/ からのパス。{profile} を含む）
     */
    public function __construct(
        public readonly string $key,
        public readonly string $version,
        public readonly array $qualityFiles,
        public readonly string $body,
    ) {
    }

    public static function load(AiMode $mode): self
    {
        $path = resource_path("ai/templates/{$mode->value}.md");

        if (! File::exists($path)) {
            throw new RuntimeException("AI実行テンプレートが見つかりません：resources/ai/templates/{$mode->value}.md");
        }

        $text = str_replace("\r\n", "\n", File::get($path));

        if (! preg_match('/\*\*テンプレートバージョン:\*\*\s*([0-9]+\.[0-9]+\.[0-9]+)/u', $text, $version)) {
            throw new RuntimeException("AI実行テンプレートにバージョンがありません：{$mode->value}.md");
        }

        $files = preg_match('/\*\*品質基準のファイル:\*\*\s*(.+)$/um', $text, $matches)
            ? array_values(array_filter(array_map('trim', explode(',', $matches[1]))))
            : [];

        $position = mb_strpos($text, "\n## 指示文\n");
        if ($position === false) {
            throw new RuntimeException("AI実行テンプレートに「## 指示文」がありません：{$mode->value}.md");
        }

        return new self($mode->value, $version[1], $files, trim(mb_substr($text, $position + mb_strlen("\n## 指示文\n"))));
    }
}
