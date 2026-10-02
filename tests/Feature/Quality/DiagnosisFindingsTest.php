<?php

namespace Tests\Feature\Quality;

use App\Enums\AiMode;
use App\Enums\ChangeSource;
use App\Models\AiGeneration;
use App\Models\ArticleEvaluation;
use App\Models\Blog;
use App\Models\Post;
use App\Models\User;
use App\Repositories\ArticleManagementRepository;
use App\Services\Ai\PromptBuilder;
use App\Services\Quality\QualityStandardLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 品質基準 2.0.0 の品質診断：記事の型の項目・★・観点ごとの適合度・指摘（どこが・何が足りないか・どう直すか）（D-47）。
 */
class DiagnosisFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_diagnosis_records_findings_gate_and_axes_and_passes_findings_to_revision(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
        $post = Post::create([
            'blog_id' => $blog->id, 'wordpress_id' => 100, 'title_raw' => 'onsubmitの使い方', 'status' => 'publish', 'content_raw' => '<h2>書き方</h2><pre><code>form.onsubmit = check;</code></pre>',
            'link' => 'https://blog.example.test/js/100.html', 'normalized_path' => '/js/100.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
        // 集客記事の Do（記事の型の項目で採点する）
        app(ArticleManagementRepository::class)->save($post, ['article_type' => 'acquisition', 'article_subtype' => 'do'], 'onsubmit', [], ChangeSource::BlogosManual, null);

        $this->post(route('ai.generations.store'), ['selected_blog_id' => $blog->id, 'mode' => 'quality_diagnosis', 'target' => "posts:{$post->id}"])->assertRedirect();
        $generation = AiGeneration::sole();
        // 指示文：記事の型と、判定の基準・★
        $this->assertStringContainsString('記事の型：do', $generation->input);
        $this->assertStringContainsString('| type.do_result | ② 記事の型 | 実行結果（★必須） | 3 | AI・人 | ○ コード・手順ごとに結果', $generation->input);
        $this->assertStringContainsString('location（どこが', $generation->input);

        $standard = app(QualityStandardLoader::class)->load('si-note');
        $required = array_keys($standard->required);
        $items = array_map(fn () => ['judgment' => '○', 'comment' => 'OK'], array_fill_keys(array_keys($standard->applicableItems('acquisition', 'do')), true));
        $items['type.do_result'] = ['judgment' => '×', 'comment' => '実行結果がない', 'location' => '見出し「書き方」のコードの後', 'problem' => '送信が止まる様子の結果がない', 'fix' => 'コードの直後に、空欄で送信したときの表示を実行結果として示す'];
        $items['seo.title_appeal'] = ['judgment' => '△', 'comment' => 'ありふれた表現', 'location' => 'タイトル', 'problem' => '何ができるかが具体的でない', 'fix' => '「送信前に入力をチェック」のように、できることを入れる'];
        $output = "```json\n" . json_encode(['required' => array_fill_keys($required, ['judgment' => '○', 'comment' => 'OK']), 'items' => $items, 'summary' => '実行結果が足りない'], JSON_UNESCAPED_UNICODE) . "\n```";
        $this->post(route('ai.generations.submit', ['id' => $generation->id]), ['selected_blog_id' => $blog->id, 'output' => $output, 'model' => 'gpt-6-luna'])->assertSessionHasNoErrors();

        $evaluation = ArticleEvaluation::sole();
        $this->assertSame('do', $evaluation->article_subtype);
        $this->assertSame(['type.do_result'], $evaluation->type_failures);
        $this->assertEquals(80, $evaluation->axis_scores['type']);
        $this->assertLessThan(100.0, $evaluation->axis_scores['ctr']);
        $detail = $evaluation->details()->where('item_key', 'type.do_result')->sole();
        $this->assertSame('見出し「書き方」のコードの後', $detail->location);
        $this->assertSame('コードの直後に、空欄で送信したときの表示を実行結果として示す', $detail->fix);
        // ○ の項目には指摘を残さない
        $this->assertNull($evaluation->details()->where('item_key', 'intent.main')->value('location'));

        $this->get(route('evaluations.show', ['id' => $evaluation->id]))->assertOk()
            ->assertSee('公開不可（記事の型の必須の項目を満たしていない）')
            ->assertSee('観点ごとの適合度')->assertSee('検索結果で選ばれるか')
            ->assertSee('どう直すか：')->assertSee('コードの直後に、空欄で送信したときの表示を実行結果として示す');

        // 記事改修の指示文に、指摘が入る
        $prompt = app(PromptBuilder::class)->build(AiMode::Revision, $blog, $post, null, [], null)['prompt'];
        $this->assertStringContainsString('：type.do_result（実行結果）：×', $prompt);
        $this->assertStringContainsString('  - どう直すか：コードの直後に、空欄で送信したときの表示を実行結果として示す', $prompt);
    }
}
