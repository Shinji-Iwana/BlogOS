<?php

namespace Tests\Unit;

use App\Enums\Judgment;
use App\Services\Quality\QualityStandardLoader;
use App\Services\Quality\ScoreCalculator;
use Tests\TestCase;

/**
 * 品質基準の読み込みと点数の計算（resources/quality/common/scoring.md）。実際の品質基準のファイルを読む。
 */
class QualityStandardTest extends TestCase
{
    public function test_loads_items_required_conditions_and_versions(): void
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');

        $this->assertSame('1.0.1', $standard->commonVersion);
        $this->assertSame('1.0.0', $standard->profileVersion);
        $this->assertCount(5, $standard->required);
        $this->assertFalse($standard->required['req.verified']['ai']);
        $this->assertTrue($standard->required['req.title_match']['ai']);

        // 9分類・49項目・合計100点（D-14-05）
        $this->assertCount(9, $standard->categories);
        $this->assertCount(49, $standard->items);
        $this->assertSame(100, array_sum(array_column($standard->items, 'points')));
        $this->assertSame(5, $standard->items['intent.main']['points']);
        $this->assertFalse($standard->items['intent.competitors']['ai']);

        // 記事種類ごとの対象外（article-types.md 6-1）
        $this->assertSame(['nav.parent', 'coverage.common_problems', 'coverage.solutions', 'reader.try_it'], $standard->typeExclusions['parent_roadmap']);
        $this->assertSame([], $standard->typeExclusions['acquisition']);
        $this->assertSame([], $standard->blogExclusions);
    }

    public function test_score_calculation(): void
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');
        $calculator = new ScoreCalculator();

        $all = array_fill_keys(array_merge(array_keys($standard->items), array_keys($standard->required)), Judgment::Good);
        $result = $calculator->calculate($standard, 'acquisition', $all);
        $this->assertSame(100.0, $result['score']);
        $this->assertTrue($result['passed']);

        // △は配点の50%（切り上げない）。5点の項目が△なら 97.5点
        $result = $calculator->calculate($standard, 'acquisition', ['intent.main' => Judgment::Partial] + $all);
        $this->assertSame(97.5, $result['score']);

        // 対象外（親ロードマップ：8点分）を除いて100点に換算する。3点の項目が×なら 89/92 → 96.7（小数第2位を切り捨て）
        $result = $calculator->calculate($standard, 'parent_roadmap', ['reader.terms' => Judgment::Bad] + $all);
        $this->assertSame(96.7, $result['score']);
        $this->assertContains('nav.parent', $result['excluded']);

        // 要人間確認は点数に含めず、合格にしない
        $result = $calculator->calculate($standard, 'acquisition', ['intent.competitors' => Judgment::NeedsHuman] + $all);
        $this->assertSame(100.0, $result['score']);
        $this->assertSame(96, $result['max']);
        $this->assertFalse($result['passed']);

        // 必須条件の×は、点数に関係なく公開不可
        $result = $calculator->calculate($standard, 'acquisition', ['req.no_misinformation' => Judgment::Bad] + $all);
        $this->assertFalse($result['required_passed']);
        $this->assertFalse($result['passed']);
        $this->assertSame('公開不可（必須条件を満たしていない）', ScoreCalculator::verdict($result['score'], $result['required_passed']));
    }
}
