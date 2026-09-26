<?php

namespace Tests\Unit;

use App\Enums\Judgment;
use App\Services\Ai\AiOutputParser;
use PHPUnit\Framework\TestCase;

/**
 * AIの出力の読み取り（resources/ai/templates/ の「出力の形式」。D-22-11）。
 */
class AiOutputParserTest extends TestCase
{
    public function test_diagnosis_without_code_fence_and_with_chatgpt_citation_markers(): void
    {
        $output = <<<'TEXT'
            {
              "required": {
                "req.verified": {"judgment": "要人間確認", "comment": "人が確認する項目です。"}
              },
              "items": {
                "accuracy.official": {"judgment": "×", "comment": "公式情報と異なります。:contentReference[oaicite:1]{index=1}"},
                "seo.title": {"judgment": "○", "comment": "適切です。"}
              },
              "summary": "製品体系が古い。:contentReference[oaicite:5]{index=5} 修正が必要。",
              "improvements": ["最優先：公式情報に合わせる。:contentReference[oaicite:6]{index=6}"]
            }
            TEXT;

        $result = (new AiOutputParser())->diagnosis($output);

        $this->assertSame(Judgment::NeedsHuman, $result['judgments']['req.verified']);
        $this->assertSame(Judgment::Bad, $result['judgments']['accuracy.official']);
        $this->assertSame(Judgment::Good, $result['judgments']['seo.title']);
        $this->assertSame('公式情報と異なります。', $result['comments']['accuracy.official']);
        $this->assertSame("製品体系が古い。 修正が必要。\n\n改善点：\n- 最優先：公式情報に合わせる。", $result['summary']);
    }

    public function test_article_sections_without_citation_markers(): void
    {
        $output = "=== タイトル ===\nMAとは\n=== メタディスクリプション ===\nMAの説明。:contentReference[oaicite:0]{index=0}\n=== 本文 ===\n<p>本文</p>";

        $sections = (new AiOutputParser())->article($output);

        $this->assertSame('MAの説明。', $sections['メタディスクリプション']);
        $this->assertSame('<p>本文</p>', $sections['本文']);
    }
}
