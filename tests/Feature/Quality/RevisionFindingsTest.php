<?php

namespace Tests\Feature\Quality;

use App\Enums\ChangeSource;
use App\Enums\EvaluatorType;
use App\Enums\Judgment;
use App\Enums\AiMode;
use App\Models\AiGeneration;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Post;
use App\Models\RevisionFinding;
use App\Models\User;
use App\Repositories\ArticleManagementRepository;
use App\Services\Quality\EvaluationService;
use App\Services\Quality\QualityStandardLoader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 指摘 → 記事改修での対応 → 改修後の確認（D-47 S3）。
 */
class RevisionFindingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_findings_are_numbered_answered_and_checked(): void
    {
        $blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->actingAs(User::factory()->create());
        $post = Post::create([
            'blog_id' => $blog->id, 'wordpress_id' => 100, 'title_raw' => 'onsubmitの使い方', 'status' => 'publish', 'content_raw' => '<h2>書き方</h2><p>本文</p>',
            'link' => 'https://blog.example.test/js/100.html', 'normalized_path' => '/js/100.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
        app(ArticleManagementRepository::class)->save($post, ['article_type' => 'acquisition', 'article_subtype' => 'do'], 'onsubmit', [], ChangeSource::BlogosManual, null);

        // 記事の AI の評価：2つの指摘（○ でない項目）
        $standard = app(QualityStandardLoader::class)->load('si-note');
        $judgments = array_fill_keys(array_keys($standard->applicableItems('acquisition', 'do')), Judgment::Good);
        $judgments['type.do_result'] = Judgment::Bad;
        $judgments['seo.title_appeal'] = Judgment::Partial;
        $before = app(EvaluationService::class)->save($blog, $post, EvaluatorType::Ai, $judgments, [], '評価', null, null, findings: [
            'type.do_result'   => ['location' => '見出し「書き方」', 'problem' => '実行結果がない', 'fix' => 'コードの直後に実行結果を示す'],
            'seo.title_appeal' => ['location' => 'タイトル', 'problem' => 'できることが具体的でない', 'fix' => '「送信前に入力をチェック」を入れる'],
        ]);

        // 記事改修：指摘を番号付きで渡し、記録する
        $this->post(route('ai.generations.store'), ['selected_blog_id' => $blog->id, 'mode' => 'revision', 'target' => "posts:{$post->id}", 'execution_method' => 'manual'])->assertRedirect();
        $revision = AiGeneration::where('purpose', AiMode::Revision)->sole();
        $this->assertStringContainsString('# 直すべき指摘', $revision->input);
        $this->assertStringContainsString('- 指摘1：seo.title_appeal', $revision->input);
        $this->assertStringContainsString('- 指摘2：type.do_result', $revision->input);
        $this->assertStringContainsString('  - どう直すか：コードの直後に実行結果を示す', $revision->input);
        $this->assertSame(2, RevisionFinding::where('ai_generation_id', $revision->id)->count());

        $output = "=== タイトル ===\nonsubmitの使い方｜送信前に入力をチェック\n=== 本文 ===\n<h2>書き方</h2><p>本文</p><p><strong>実行結果：</strong>止まる</p>\n"
            . "=== 指摘への対応 ===\n```json\n[{\"no\": 1, \"status\": \"直した\", \"detail\": \"タイトルに、できることを入れた\"}, {\"no\": 2, \"status\": \"直した\", \"detail\": \"見出し「書き方」に実行結果を加えた\"}]\n```\n=== 変更点 ===\n- 直した\n";
        $this->post(route('ai.generations.submit', ['id' => $revision->id]), ['selected_blog_id' => $blog->id, 'output' => $output, 'model' => 'gpt-6-luna'])->assertSessionHasNoErrors();
        $draft = ArticleDraft::where('post_id', $post->id)->sole();
        $finding = RevisionFinding::where('number', 2)->sole();
        $this->assertSame($draft->id, $finding->article_draft_id);
        $this->assertSame('fixed', $finding->response_status);
        $this->assertSame('見出し「書き方」に実行結果を加えた', $finding->response_note);

        // 編集案の品質診断：前回の指摘ごとに、解消したかを判定させる
        $this->post(route('ai.generations.store'), ['selected_blog_id' => $blog->id, 'mode' => 'quality_diagnosis', 'target' => "drafts:{$draft->id}", 'execution_method' => 'manual'])->assertRedirect();
        $diagnosis = AiGeneration::where('purpose', AiMode::QualityDiagnosis)->sole();
        $this->assertStringContainsString("- 指摘2：type.do_result（改修前：×）", $diagnosis->input);
        $this->assertStringContainsString('  - 改修での対応：直した（見出し「書き方」に実行結果を加えた）', $diagnosis->input);

        $items = array_map(fn () => ['judgment' => '○', 'comment' => 'OK'], array_fill_keys(array_keys($standard->applicableItems('acquisition', 'do')), true));
        $items['seo.title_appeal'] = ['judgment' => '△', 'comment' => 'まだ一般的', 'location' => 'タイトル', 'problem' => '他の記事との違いが弱い', 'fix' => '対象を入れる'];
        $json = ['required' => array_fill_keys(array_keys($standard->required), ['judgment' => '○']), 'items' => $items,
            'findings_check' => ['1' => ['status' => '一部解消', 'comment' => 'できることは入ったが、違いが弱い'], '2' => ['status' => '解消', 'comment' => '実行結果がある']], 'summary' => '改善した'];
        $this->post(route('ai.generations.submit', ['id' => $diagnosis->id]), ['selected_blog_id' => $blog->id, 'output' => "```json\n" . json_encode($json, JSON_UNESCAPED_UNICODE) . "\n```", 'model' => 'gpt-6-luna'])->assertSessionHasNoErrors();

        $this->assertSame('resolved', RevisionFinding::where('number', 2)->value('check_status'));
        $this->assertSame('partial', RevisionFinding::where('number', 1)->value('check_status'));
        $this->assertNotNull(RevisionFinding::where('number', 1)->value('check_evaluation_id'));

        $this->get(route('drafts.edit', ['id' => $draft->id]))->assertOk()
            ->assertSee('指摘と対応')->assertSee('解消 1件・一部解消 1件・未解消 0件')
            ->assertSee('見出し「書き方」に実行結果を加えた')->assertSee('できることは入ったが、違いが弱い')
            ->assertSee(number_format($before->score, 1) . '点');

        // 一部解消の指摘は、次の改修では、編集案の評価の指摘として引き継がれる
        $this->post(route('ai.generations.store'), ['selected_blog_id' => $blog->id, 'mode' => 'revision', 'target' => "drafts:{$draft->id}", 'execution_method' => 'manual'])->assertRedirect();
        $second = AiGeneration::where('purpose', AiMode::Revision)->latest('id')->first();
        $this->assertStringContainsString('- 指摘1：seo.title_appeal', $second->input);
        $this->assertStringContainsString('  - どう直すか：対象を入れる', $second->input);
        $this->assertStringNotContainsString('type.do_result（', $second->input);
    }
}
