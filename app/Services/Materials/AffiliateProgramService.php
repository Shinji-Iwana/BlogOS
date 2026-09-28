<?php

namespace App\Services\Materials;

use App\Enums\AffiliateProgramStatus;
use App\Enums\MaterialKind;
use App\Models\AffiliateProgram;
use App\Models\Blog;
use App\Models\Material;
use App\Models\Page;
use App\Models\Post;
use App\Support\AffiliateLink;
use Illuminate\Support\Collection;

/**
 * アフィリエイトのプログラム（提携先の広告）の状態と、教材のリンクが紹介に使えるか（D-33-08）。
 *
 * 提携中でない（申請中・否認・提携終了の）プログラムのリンクは、記事で紹介に使わない。
 * 未確認（記事のリンクから自動で登録したもの）は、今の記事を止めないよう使える扱いにし、画面で確認を促す。
 */
class AffiliateProgramService
{
    /**
     * よく使うプログラムの名前と教材の種類（リンクから登録するときの初期値）
     */
    protected const KNOWN = [
        'moshimo:170'  => ['Amazon.co.jp（もしも）', null],
        'moshimo:54'   => ['楽天市場（もしも）', null],
        'moshimo:1000' => ['DMM WEBCAMP（もしも）', MaterialKind::School],
        'moshimo:5256' => ['zero to one（もしも）', MaterialKind::QuestionBank],
        'udemy'        => ['Udemy', MaterialKind::Udemy],
        'rakuten'      => ['楽天アフィリエイト', null],
        'amazon'       => ['Amazonアソシエイト', null],
    ];

    /**
     * @var array<int, Collection<string, AffiliateProgram>> ブログごとの「プログラムの識別子 => プログラム」
     */
    protected array $programs = [];

    public function __construct(
        protected MaterialLinkScanner $scanner,
    ) {
    }

    /**
     * @return Collection<string, AffiliateProgram>
     */
    public function programs(int $blogId): Collection
    {
        return $this->programs[$blogId] ??= AffiliateProgram::where('blog_id', $blogId)->orderBy('asp')->orderBy('name')->get()->keyBy('program_key');
    }

    public function forget(int $blogId): void
    {
        unset($this->programs[$blogId]);
    }

    /**
     * 紹介に使えるリンク（提携中・未確認のプログラム、またはプログラムが分からないリンク）
     *
     * @return array<string, string> 表示名 => URL
     */
    public function usableLinks(Material $material): array
    {
        $programs = $this->programs($material->blog_id);
        $keys = $material->programKeys();

        return array_filter($material->affiliateLinks(), function ($url, $label) use ($programs, $keys) {
            $program = $keys[$label] !== null ? $programs->get($keys[$label]) : null;

            return $program === null || $program->isUsable();
        }, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * 記事で紹介に使えるか（使えるリンクが1つ以上ある）。リンクがまだない教材は、リンクの状態では判断しない
     */
    public function isUsable(Material $material): bool
    {
        return $material->affiliateLinks() === [] || $this->usableLinks($material) !== [];
    }

    /**
     * 使えないリンクの説明（画面の表示用）
     *
     * @return list<string> 例：「楽天：否認（楽天市場（もしも））」
     */
    public function problems(Material $material): array
    {
        $programs = $this->programs($material->blog_id);
        $problems = [];
        foreach ($material->programKeys() as $label => $key) {
            $program = $key !== null ? $programs->get($key) : null;
            if ($program !== null && ! $program->isUsable()) {
                $problems[] = "{$label}：{$program->status->label()}（{$program->name}）";
            }
        }

        return $problems;
    }

    /**
     * 記事の本文にあるリンクから、プログラムごとに使っている記事を集める
     *
     * @return array<string, list<Post|Page>> プログラムの識別子 => 記事
     */
    public function usage(Blog $blog): array
    {
        $usage = [];
        foreach ([Post::class, Page::class] as $modelClass) {
            foreach ($modelClass::where('blog_id', $blog->id)->existing()->get(['id', 'blog_id', 'title_raw', 'status', 'content_raw']) as $article) {
                $keys = [];
                foreach ($this->scanner->scan((string) $article->content_raw) as $link) {
                    if (($key = AffiliateLink::programKey($link['url'])) !== null) {
                        $keys[$key] = true;
                    }
                }
                foreach (array_keys($keys) as $key) {
                    $usage[$key][] = $article;
                }
            }
        }

        return $usage;
    }

    /**
     * プログラムごとに、記事でいちばん多く使っているリンク（リンクの定期確認に使う。D-33-09）
     *
     * @return array<string, string> プログラムの識別子 => URL
     */
    public function representativeUrls(Blog $blog): array
    {
        $counts = [];
        foreach ([Post::class, Page::class] as $modelClass) {
            foreach ($modelClass::where('blog_id', $blog->id)->existing()->get(['id', 'content_raw']) as $article) {
                foreach ($this->scanner->scan((string) $article->content_raw) as $link) {
                    if (($key = AffiliateLink::programKey($link['url'])) !== null) {
                        $counts[$key][$link['url']] = ($counts[$key][$link['url']] ?? 0) + 1;
                    }
                }
            }
        }

        return array_map(function (array $urls) {
            arsort($urls);

            return (string) array_key_first($urls);
        }, $counts);
    }

    /**
     * 記事のリンクにあるプログラムのうち、まだ登録していないものを「未確認」で登録する
     *
     * @return int 登録した数
     */
    public function registerFromLinks(Blog $blog): int
    {
        $existing = $this->programs($blog->id);
        $created = 0;

        foreach (array_keys($this->usage($blog)) as $key) {
            if ($existing->has($key)) {
                continue;
            }
            [$name, $kind] = self::KNOWN[$key] ?? [$key, null];
            AffiliateProgram::create([
                'blog_id'       => $blog->id,
                'program_key'   => $key,
                'asp'           => AffiliateLink::aspOf($key),
                'name'          => $name,
                'material_kind' => $kind,
                'status'        => AffiliateProgramStatus::Unconfirmed,
            ]);
            $created++;
        }

        $this->forget($blog->id);

        return $created;
    }

    /**
     * プログラムの教材（リンクのどれかがこのプログラムの教材）
     *
     * @param Collection<int, Material> $materials
     * @return Collection<int, Material>
     */
    public function materialsOf(AffiliateProgram $program, Collection $materials): Collection
    {
        return $materials->filter(fn (Material $material) => in_array($program->program_key, $material->programKeys(), true))->values();
    }
}
