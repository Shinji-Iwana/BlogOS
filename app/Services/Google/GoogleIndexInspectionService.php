<?php

namespace App\Services\Google;

use App\Clients\Google\GoogleApiClient;
use App\Clients\Google\GoogleApiException;
use App\Enums\GoogleIndexCategory;
use App\Enums\GoogleService;
use App\Models\Blog;
use App\Models\GoogleIndexStatus;
use App\Models\Page;
use App\Models\Post;
use App\Repositories\GoogleAccountRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * 記事ごとのインデックスの登録状態を、Search Console の URL 検査 API で調べる（D-37）。
 *
 * 1日に調べる数には上限がある（Search Console のプロパティごとに1日2,000件）。まだ調べていない記事、反映で変わった記事、
 * 前に調べてから日数が過ぎた記事（登録済みは30日、未登録は7日）の順に調べる。今の読み取りの権限（webmasters.readonly）で使える。
 */
class GoogleIndexInspectionService
{
    public const ENDPOINT = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';

    public function __construct(
        protected GoogleAccountRepository $accounts,
        protected GoogleTokenService $tokens,
    ) {
    }

    public function isConfigured(Blog $blog): bool
    {
        return $this->accounts->propertiesForBlog($blog->id)->has(GoogleService::SearchConsole->value);
    }

    /**
     * 調べる順の記事（公開中の投稿・固定ページ）
     *
     * @return Collection<int, Post|Page>
     */
    public function due(Blog $blog, bool $all = false): Collection
    {
        $statuses = GoogleIndexStatus::where('blog_id', $blog->id)->get()->keyBy(fn ($row) => $row->post_id ? "post:{$row->post_id}" : "page:{$row->page_id}");
        $settings = (array) config('blogos.google.index_inspection');
        $now = Carbon::now();

        $rows = [];
        foreach ([Post::class => 'post:', Page::class => 'page:'] as $modelClass => $prefix) {
            foreach ($modelClass::where('blog_id', $blog->id)->existing()->where('status', 'publish')->get(['id', 'blog_id', 'link', 'wordpress_modified_gmt']) as $article) {
                $status = $statuses[$prefix . $article->id] ?? null;
                $inspected = $status?->inspected_at;
                $days = $status?->category === GoogleIndexCategory::Indexed ? (int) ($settings['recheck_days_indexed'] ?? 30) : (int) ($settings['recheck_days_not_indexed'] ?? 7);

                $priority = match (true) {
                    $inspected === null                                                                  => 0,
                    $article->wordpress_modified_gmt !== null && $article->wordpress_modified_gmt->gt($inspected) => 1,
                    $all || $inspected->lt($now->copy()->subDays($days))                                 => 2,
                    default                                                                              => null,
                };
                if ($priority !== null) {
                    $rows[] = [$priority, $inspected?->timestamp ?? 0, $article];
                }
            }
        }

        usort($rows, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return collect(array_column($rows, 2));
    }

    /**
     * @return array{inspected: int, errors: int, remaining: int, stopped: string|null}
     *
     * @throws GoogleApiException Search Console の連携がない・トークンを更新できない場合
     */
    public function inspect(Blog $blog, ?int $limit = null, bool $all = false, int $pauseMilliseconds = 0): array
    {
        $property = $this->accounts->propertiesForBlog($blog->id)->get(GoogleService::SearchConsole->value)
            ?? throw new GoogleApiException('Search Console のプロパティが設定されていません。Google連携の設定で選んでください。', 'POST', self::ENDPOINT);
        $client = $this->tokens->clientFor($property->account);

        $limit ??= (int) config('blogos.google.index_inspection.daily_limit', 200);
        $due = $this->due($blog, $all);
        $inspected = 0;
        $errors = 0;
        $stopped = null;

        foreach ($due->take($limit) as $index => $article) {
            if ($index > 0 && $pauseMilliseconds > 0) {
                usleep($pauseMilliseconds * 1000);
            }

            try {
                $data = $client->post(self::ENDPOINT, ['inspectionUrl' => $article->link, 'siteUrl' => $property->resource_name, 'languageCode' => 'en-US']);
                $this->save($blog, $article, (array) ($data['inspectionResult']['indexStatusResult'] ?? []), null);
                $inspected++;
            } catch (GoogleApiException $e) {
                // 1日の上限・権限の問題は、続けても同じため止める
                if (in_array($e->status, [401, 403, 429], true)) {
                    $stopped = $e->getMessage();
                    break;
                }
                $this->save($blog, $article, [], $e->getMessage());
                $errors++;
            }
        }

        return ['inspected' => $inspected, 'errors' => $errors, 'remaining' => max(0, $due->count() - $inspected - $errors), 'stopped' => $stopped];
    }

    /**
     * @param array<string, mixed> $result indexStatusResult
     */
    protected function save(Blog $blog, Post|Page $article, array $result, ?string $error): void
    {
        $column = $article instanceof Post ? 'post_id' : 'page_id';
        $status = GoogleIndexStatus::firstOrNew([$column => $article->id], ['blog_id' => $blog->id]);

        if ($error !== null) {
            $status->fill(['url' => (string) $article->link, 'error' => mb_substr($error, 0, 2000), 'inspected_at' => now()])->save();

            return;
        }

        $category = GoogleIndexCategory::fromCoverage($result['coverageState'] ?? null, $result['verdict'] ?? null);
        if ($status->exists && $status->category !== null && $status->category !== $category) {
            $status->previous_category = $status->category;
            $status->category_changed_at = now();
        }

        $status->fill([
            'url'              => (string) $article->link,
            'category'         => $category,
            'coverage_state'   => isset($result['coverageState']) ? mb_substr((string) $result['coverageState'], 0, 255) : null,
            'verdict'          => $result['verdict'] ?? null,
            'indexing_state'   => $result['indexingState'] ?? null,
            'robots_txt_state' => $result['robotsTxtState'] ?? null,
            'page_fetch_state' => $result['pageFetchState'] ?? null,
            'last_crawl_at'    => isset($result['lastCrawlTime']) ? Carbon::parse($result['lastCrawlTime']) : null,
            'google_canonical' => $result['googleCanonical'] ?? null,
            'user_canonical'   => $result['userCanonical'] ?? null,
            'error'            => null,
            'inspected_at'     => now(),
        ])->save();
    }
}
