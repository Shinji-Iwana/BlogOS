<?php

namespace Tests\Unit;

use App\Enums\Judgment;
use App\Services\Quality\QualityStandardLoader;
use App\Services\Quality\ScoreCalculator;
use Tests\TestCase;

/**
 * 品質基準の読み込みと点数の計算（resources/quality/common/scoring.md 2.0.0。D-47）。実際の品質基準のファイルを読む。
 */
class QualityStandardTest extends TestCase
{
    public function test_loads_items_axes_type_items_and_versions(): void
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');

        $this->assertSame('2.0.0', $standard->commonVersion);
        $this->assertSame('2.0.0', $standard->profileVersion);
        $this->assertCount(5, $standard->required);
        $this->assertFalse($standard->required['req.verified']['ai']);

        // 11分類。共通の項目は85点、記事の型の項目（15点）と合わせて100点
        $this->assertCount(11, $standard->categories);
        $this->assertSame(85, array_sum(array_column($standard->items, 'points')));
        $this->assertSame(4, $standard->items['intent.main']['points']);
        $this->assertFalse($standard->items['intent.competitors']['ai']);
        $this->assertStringContainsString('先頭28文字以内', $standard->items['seo.title_keyword']['criteria']);
        $this->assertSame(['seo', 'ctr'], $standard->items['seo.title_keyword']['axes']);

        // 観点（9つ）
        $this->assertSame(['intent', 'type', 'people', 'seo', 'ctr', 'reader', 'ux', 'nav', 'mon'], array_keys($standard->axes));

        // 記事の型の項目（各15点）。集客記事は細分類で、それ以外は記事種類で決まる
        foreach (['know', 'do', 'solve', 'compare', 'practice', 'parent_roadmap', 'child_roadmap', 'revenue'] as $form) {
            $this->assertSame(15, array_sum(array_column($standard->typeItems[$form], 'points')), $form);
        }
        $this->assertTrue($standard->typeItems['do']['type.do_result']['required']);
        $this->assertFalse($standard->typeItems['do']['type.do_errors']['required']);
        $this->assertSame(['type', 'reader'], $standard->typeItems['do']['type.do_result']['axes']);
        $this->assertSame('do', $standard->formFor('acquisition', 'do'));
        $this->assertSame('revenue', $standard->formFor('revenue', null));
        $this->assertNull($standard->formFor('acquisition', null));
        $this->assertSame(100, array_sum(array_column($standard->applicableItems('acquisition', 'do'), 'points')));

        // 記事種類ごとの対象外と、観点の重要度
        $this->assertSame(['nav.parent', 'coverage.common_problems'], $standard->typeExclusions['parent_roadmap']);
        $this->assertSame('重要', $standard->importance('revenue', 'mon'));
        $this->assertSame('推奨', $standard->importance('acquisition', 'mon'));
    }

    public function test_score_gate_and_axes(): void
    {
        $standard = app(QualityStandardLoader::class)->load('si-note');
        $calculator = new ScoreCalculator();

        $all = array_fill_keys(array_merge(array_keys($standard->allItems()), array_keys($standard->required)), Judgment::Good);
        $result = $calculator->calculate($standard, 'acquisition', $all, 'do');
        $this->assertSame(100.0, $result['score']);
        $this->assertTrue($result['passed']);
        $this->assertSame(100.0, $result['axes']['ctr']);

        // △は配点の50%。4点の項目が△なら 98点
        $result = $calculator->calculate($standard, 'acquisition', ['intent.main' => Judgment::Partial] + $all, 'do');
        $this->assertSame(98.0, $result['score']);

        // 記事の型の必須（★）が × なら、点数が高くても公開不可
        $result = $calculator->calculate($standard, 'acquisition', ['type.do_result' => Judgment::Bad] + $all, 'do');
        $this->assertSame(97.0, $result['score']);
        $this->assertSame(['type.do_result'], $result['type_failures']);
        $this->assertFalse($result['passed']);
        $this->assertSame('公開不可（記事の型の必須の項目を満たしていない）', ScoreCalculator::verdict($result['score'], true, $result['type_failures']));
        // 観点：記事の型は 12/15、理解しやすさは do_result（3点）を含む
        $this->assertSame(80.0, $result['axes']['type']);

        // タイトルの訴求が × なら、CTR の観点が下がる（ctr：intent.consistency 3・title_keyword 2・title_appeal 2・title_form 1・meta 2 = 10点）
        $result = $calculator->calculate($standard, 'acquisition', ['seo.title_appeal' => Judgment::Bad] + $all, 'do');
        $this->assertSame(80.0, $result['axes']['ctr']);

        // 細分類が未登録の集客記事は、記事の型の項目を採点しない（85点を100点に換算）
        $result = $calculator->calculate($standard, 'acquisition', $all);
        $this->assertSame(100.0, $result['score']);
        $this->assertSame(85, $result['max']);
        $this->assertNull($result['axes']['type']);

        // 要人間確認は点数に含めず、合格にしない
        $result = $calculator->calculate($standard, 'acquisition', ['intent.competitors' => Judgment::NeedsHuman] + $all, 'do');
        $this->assertSame(97, $result['max']);
        $this->assertFalse($result['passed']);

        // 必須条件の×は、点数に関係なく公開不可
        $result = $calculator->calculate($standard, 'acquisition', ['req.no_misinformation' => Judgment::Bad] + $all, 'do');
        $this->assertFalse($result['required_passed']);
        $this->assertSame('公開不可（必須条件を満たしていない）', ScoreCalculator::verdict($result['score'], $result['required_passed']));
    }
}
