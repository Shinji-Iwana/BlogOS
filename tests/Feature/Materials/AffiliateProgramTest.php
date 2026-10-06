<?php

namespace Tests\Feature\Materials;

use App\Enums\AffiliateLinkCheckResult;
use App\Enums\AffiliateProgramStatus;
use App\Enums\MaterialKind;
use App\Enums\MaterialStatus;
use App\Models\AffiliateProgram;
use App\Models\Blog;
use App\Models\Category;
use App\Models\Material;
use App\Models\Post;
use App\Models\User;
use App\Services\Materials\MaterialLinkService;
use App\Services\Materials\MaterialMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * アフィリエイトのプログラム（提携先の広告）の状態と、問題集・オンライン教材の種類（D-33-08）。
 */
class AffiliateProgramTest extends TestCase
{
    use RefreshDatabase;

    protected const SCHOOL = '//af.moshimo.com/af/c/click?a_id=3&p_id=1000&pc_id=1380&pl_id=72072';

    protected const QUESTION_BANK = '//af.moshimo.com/af/c/click?a_id=4&p_id=5256&pc_id=14256&pl_id=68863&url=https%3A%2F%2Fzero2one.jp%2Fproduct%2Faws-training-clf%2F';

    protected Blog $blog;

    protected Category $aws;

    protected Post $post;

    protected function setUp(): void
    {
        parent::setUp();

        $this->blog = Blog::create(['home' => 'https://blog.example.test', 'display_name' => 'Example Blog', 'is_selected' => true, 'quality_profile' => 'si-note']);
        $this->aws = Category::create(['blog_id' => $this->blog->id, 'wordpress_id' => 20, 'name' => 'AWS']);
        $this->post = Post::create([
            'blog_id' => $this->blog->id, 'wordpress_id' => 200, 'title_raw' => 'CLFとSAAの違い', 'status' => 'publish',
            'content_raw' => '<div class="cert-box"><h3>CLF対応オンライン問題集</h3><p><a href="' . self::QUESTION_BANK . '">CLF対応オンライン問題集はこちら</a></p></div>'
                . '<div class="school-box"><h3>スクール</h3><p><a href="' . self::SCHOOL . '">DMM WEBCAMP 学習コース</a></p></div>',
            'link' => 'https://blog.example.test/aws/200.html', 'normalized_path' => '/aws/200.html', 'wordpress_modified_gmt' => '2026-09-01 00:00:00',
        ]);
        $this->post->categories()->attach($this->aws->id);

        $this->actingAs(User::factory()->create());
    }

    protected function selected(array $data = []): array
    {
        return array_merge(['selected_blog_id' => $this->blog->id], $data);
    }

    public function test_programs_are_registered_from_links_and_kind_follows_program(): void
    {
        $this->post(route('materials.programs.register'), $this->selected())->assertRedirect(route('materials.programs.index'));

        $programs = AffiliateProgram::all()->keyBy('program_key');
        $this->assertSame(['moshimo:1000', 'moshimo:5256'], $programs->keys()->sort()->values()->all());
        $this->assertTrue($programs->every(fn ($program) => $program->status === AffiliateProgramStatus::Unconfirmed));
        $this->assertSame(MaterialKind::QuestionBank, $programs['moshimo:5256']->material_kind);
        $this->assertSame('zero to one（もしも）', $programs['moshimo:5256']->name);

        // リンクから教材を登録するときは、プログラムの教材の種類に合わせる
        $detected = collect(app(MaterialLinkService::class)->detect($this->blog))->keyBy(fn ($item) => $item['kind']->value);
        $this->assertSame('CLF対応オンライン問題集', $detected['question_bank']['name']);
        $this->assertArrayHasKey('school', $detected->all());

        $this->get(route('materials.programs.index'))->assertOk()->assertSee('zero to one（もしも）')->assertSee('未確認');
    }

    public function test_programs_not_active_are_excluded_from_candidates(): void
    {
        $this->post(route('materials.programs.register'), $this->selected())->assertRedirect();
        $school = Material::create([
            'blog_id' => $this->blog->id, 'kind' => MaterialKind::School, 'status' => MaterialStatus::Active, 'name' => 'DMM WEBCAMP 学習コース',
            'affiliate_url' => 'https:' . self::SCHOOL, 'topics' => ['AWS'], 'summary' => '未経験から学べるスクール',
        ]);
        // もしも経由の問題集を、スクールとして登録してしまった場合
        $questionBank = Material::create([
            'blog_id' => $this->blog->id, 'kind' => MaterialKind::School, 'status' => MaterialStatus::Active, 'name' => 'CLF対応オンライン問題集',
            'affiliate_url' => 'https:' . self::QUESTION_BANK, 'topics' => ['AWS'], 'summary' => '試験対策のオンライン問題集',
        ]);
        app(MaterialLinkService::class)->syncBlog($this->blog);

        // 未確認のあいだは、候補に含める
        $ids = fn () => array_map(fn ($row) => $row['score'] > 0 ? $row['material']->id : null, app(MaterialMatcher::class)->candidates($this->blog, $this->post));
        $this->assertContains($school->id, $ids());

        // 否認にしたら、候補から外し、今使っている教材として差し替えを促す
        $dmm = AffiliateProgram::where('program_key', 'moshimo:1000')->sole();
        $this->put(route('materials.programs.update', ['id' => $dmm->id]), $this->selected(['name' => $dmm->name, 'material_kind' => 'school', 'status' => 'rejected']))
            ->assertRedirect(route('materials.programs.index'));
        $this->assertSame(AffiliateProgramStatus::Rejected, $dmm->fresh()->status);
        $this->assertNotNull($dmm->fresh()->status_changed_on);

        $candidates = collect(app(MaterialMatcher::class)->candidates($this->blog, $this->post))->keyBy(fn ($row) => $row['material']->id);
        $this->assertSame(0, $candidates[$school->id]['score']);
        $this->assertStringContainsString('提携中でないため紹介に使えない', implode(' ', $candidates[$school->id]['reasons']));
        $this->assertGreaterThan(0, $candidates[$questionBank->id]['score']);

        $this->get(route('materials.programs.index'))->assertOk()->assertSee('提携中でないプログラムのリンクがある記事')->assertSee('CLFとSAAの違い');
        $this->get(route('materials.index'))->assertOk()->assertSee('提携中でないリンク：リンク：否認');

        // プログラムの教材の種類に、登録済みの教材をそろえる
        $zero = AffiliateProgram::where('program_key', 'moshimo:5256')->sole();
        $this->put(route('materials.programs.update', ['id' => $zero->id]), $this->selected(['name' => $zero->name, 'material_kind' => 'question_bank', 'status' => 'active', 'align_kind' => '1']))
            ->assertSessionHas('status', fn ($message) => str_contains($message, '教材 1件の種類を「問題集・オンライン教材」にしました'));
        $this->assertSame(MaterialKind::QuestionBank, $questionBank->fresh()->kind);
    }

    public function test_link_check_detects_ended_programs(): void
    {
        $this->post->update(['content_raw' => $this->post->content_raw . '<p><a href="https://trk.udemy.com/AbCdEf">講座（Udemy）</a></p>']);
        $this->post(route('materials.programs.register'), $this->selected())->assertRedirect();

        Http::preventStrayRequests();
        Http::fake([
            // 提携が終わった広告：もしもの「見つかりません」のページへ転送する
            'https://af.moshimo.com/af/c/click?a_id=3&p_id=1000*' => Http::response('', 302, ['Location' => 'https://af.moshimo.com/af/www/expiration']),
            // 提携中の広告：広告主のサイトへ転送する（広告主のサイトは開かない）
            'https://af.moshimo.com/af/c/click?a_id=4&p_id=5256*' => Http::response('', 302, ['Location' => 'https://zero2one.jp/product/aws-training-clf/']),
            // Udemy：転送をたどり、行き先がエラーなら疑いにする
            'https://trk.udemy.com/AbCdEf'                         => Http::response('', 301, ['Location' => '/course/removed/']),
            'https://trk.udemy.com/course/removed/'                => Http::response('not found', 404),
        ]);

        $this->post(route('materials.programs.check'), $this->selected())
            ->assertSessionHas('status', fn ($message) => str_contains($message, '提携終了の疑いが 2件'));

        $programs = AffiliateProgram::all()->keyBy('program_key');
        $this->assertSame(AffiliateLinkCheckResult::Suspect, $programs['moshimo:1000']->check_result);
        $this->assertStringContainsString('/af/www/expiration', $programs['moshimo:1000']->check_detail);
        $this->assertSame(AffiliateLinkCheckResult::Ok, $programs['moshimo:5256']->check_result);
        $this->assertSame(AffiliateLinkCheckResult::Suspect, $programs['udemy']->check_result);
        Http::assertNotSent(fn ($request) => parse_url($request->url(), PHP_URL_HOST) === 'zero2one.jp');
        // 状態は自動では変えない
        $this->assertSame(AffiliateProgramStatus::Unconfirmed, $programs['moshimo:1000']->status);

        $this->get(route('home'))->assertOk()->assertSee('提携終了の疑いがあるプログラムが2件');
        $this->get(route('materials.programs.index'))->assertOk()->assertSee('提携終了の疑いがあるプログラム');

        // 提携終了にしたプログラムは、確認の対象にしない
        $programs['moshimo:1000']->update(['status' => AffiliateProgramStatus::Ended]);
        $this->artisan('affiliate:check-links')->assertSuccessful();
        // 1回目：4件（もしも2件・Udemy 2件）、2回目：3件（提携終了の DMM を除く）
        Http::assertSentCount(7);
    }

    public function test_program_can_be_registered_by_hand(): void
    {
        // メニューの「設定」の「OpenAI」の下に「アフィリエイト提携先を登録」（順は D-63-26）。押すとポップアップを開く（D-63-20）
        $html = $this->get(route('scheduled-tasks.runs'))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<summary>OpenAI<\/summary>.*?data-modal-open="affiliate-program-modal" >アフィリエイト提携先を登録<\/a><\/li>\s*<li class="site-menu-sub">\s*<details>\s*<summary>即時実行<\/summary>/s', $html);
        $this->assertMatchesRegularExpression('/id="affiliate-program-modal".*?<h2>\s*アフィリエイト提携先を登録/s', $html);
        // 画面「アフィリエイトのプログラム」には、登録の欄を出さない
        $this->get(route('materials.programs.index'))->assertOk()->assertDontSee('<h2>プログラムを登録する</h2>', false);

        // 登録すると、開いていた画面に戻り、ポップアップを開いて結果を出す
        $this->from(route('scheduled-tasks.runs'))->post(route('materials.programs.store'), $this->selected([
            '_form' => 'affiliate-program', 'asp' => 'moshimo', 'external_id' => '9999', 'name' => 'CodeCamp（もしも）', 'material_kind' => 'school', 'status' => 'applying',
        ]))->assertRedirect(route('scheduled-tasks.runs'))->assertSessionHas('affiliate_program_modal', true);
        $this->assertMatchesRegularExpression('/id="affiliate-program-modal"\s+class="[^"]*"\s+data-modal-autoopen.*?プログラム「CodeCamp（もしも）」を登録しました/s', $this->get(route('scheduled-tasks.runs'))->getContent());

        $program = AffiliateProgram::where('program_key', 'moshimo:9999')->sole();
        $this->assertSame(AffiliateProgramStatus::Applying, $program->status);
        $this->assertFalse($program->isUsable());

        // 同じプログラムは二重に登録しない
        $this->from(route('scheduled-tasks.runs'))->post(route('materials.programs.store'), $this->selected(['_form' => 'affiliate-program', 'asp' => 'moshimo', 'external_id' => '9999', 'name' => '重複', 'status' => 'active']))
            ->assertRedirect(route('scheduled-tasks.runs'));
        // 入力の誤りは、ポップアップを開いたままにして、誤りと入力した値を出す
        $html = $this->get(route('scheduled-tasks.runs'))->getContent();
        $this->assertMatchesRegularExpression('/id="affiliate-program-modal"\s+class="[^"]*"\s+data-modal-autoopen.*?登録済みです.*?value="重複"/s', $html);
    }
}
