<?php

namespace App\Services\Materials;

use App\Enums\AffiliateLinkCheckResult;
use App\Models\AffiliateProgram;
use App\Models\Blog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * プログラムのリンクの定期確認（D-33-09）。提携が終わったプログラムのリンクを見つける。
 *
 * もしもアフィリエイトは、提携が終わった広告のリンクを「見つかりません」のページ（/af/www/expiration など）へ転送する。
 * もしものリンクは、最初の転送先だけを見る（広告主のサイトは開かない）。それ以外のリンクは、転送を最後までたどり、
 * 行き先がエラー（400番台・500番台）でないかを見る。確認はプログラムごとに1本だけ（記事でいちばん多く使っているリンク）とし、
 * クリックの数への影響を小さくする。状態（提携中など）は自動では変えず、「提携終了の疑い」として人に知らせる。
 */
class AffiliateLinkChecker
{
    /**
     * もしもの「見つかりません」のページのパス
     */
    protected const MOSHIMO_ERROR_PATH = '#^/af/www/#';

    protected const MAX_REDIRECTS = 5;

    public function __construct(
        protected AffiliateProgramService $programs,
    ) {
    }

    /**
     * ブログの、記事で使っているプログラム（提携中・未確認）を確かめる
     *
     * @return array<string, AffiliateLinkCheckResult> プログラムの識別子 => 結果
     */
    public function checkBlog(Blog $blog, int $pauseMilliseconds = 0): array
    {
        $this->programs->forget($blog->id);
        $urls = $this->programs->representativeUrls($blog);
        $results = [];

        foreach ($this->programs->programs($blog->id) as $key => $program) {
            if (! isset($urls[$key]) || ! $program->isUsable()) {
                continue;
            }
            if ($results !== [] && $pauseMilliseconds > 0) {
                usleep($pauseMilliseconds * 1000);
            }
            $results[$key] = $this->check($program, $urls[$key]);
        }

        $this->programs->forget($blog->id);

        return $results;
    }

    public function check(AffiliateProgram $program, string $url): AffiliateLinkCheckResult
    {
        [$result, $detail] = $this->inspect($url);

        $program->update([
            'check_url'    => $url,
            'check_result' => $result,
            'check_detail' => $detail,
            'checked_at'   => now(),
        ]);

        return $result;
    }

    /**
     * @return array{0: AffiliateLinkCheckResult, 1: string}
     */
    protected function inspect(string $url): array
    {
        $current = $url;

        try {
            for ($hop = 0; $hop <= self::MAX_REDIRECTS; $hop++) {
                $response = $this->request($current);
                $host = strtolower((string) parse_url($current, PHP_URL_HOST));
                $location = $response->redirect() ? $this->absolute((string) $response->header('Location'), $current) : null;

                if ($host === 'af.moshimo.com') {
                    if ($location === null) {
                        return [AffiliateLinkCheckResult::Suspect, "もしもが転送しませんでした（HTTP {$response->status()}）。"];
                    }
                    $next = parse_url($location);
                    if (strtolower((string) ($next['host'] ?? '')) === 'af.moshimo.com' && preg_match(self::MOSHIMO_ERROR_PATH, (string) ($next['path'] ?? ''))) {
                        return [AffiliateLinkCheckResult::Suspect, "行き先が、もしもの「見つかりません」のページ（{$location}）でした。"];
                    }

                    // 広告主のサイトへ転送された。広告主のサイトは開かない
                    return [AffiliateLinkCheckResult::Ok, "広告主のサイト（{$next['host']}）へ転送されました。"];
                }

                if ($location !== null) {
                    $current = $location;

                    continue;
                }

                return $response->status() >= 400
                    ? [AffiliateLinkCheckResult::Suspect, "行き先がエラーでした（HTTP {$response->status()}：{$current}）。"]
                    : [AffiliateLinkCheckResult::Ok, "行き先が表示できました（{$current}）。"];
            }
        } catch (ConnectionException $e) {
            return [AffiliateLinkCheckResult::Error, "接続できませんでした：{$e->getMessage()}"];
        }

        return [AffiliateLinkCheckResult::Error, '転送が多すぎるため、確認をやめました。'];
    }

    protected function request(string $url): Response
    {
        return Http::withOptions(['allow_redirects' => false])
            ->withHeaders(['User-Agent' => 'BlogOS link check'])
            ->timeout(15)
            ->get($url);
    }

    protected function absolute(string $location, string $base): string
    {
        if ($location === '' || preg_match('#^https?://#i', $location)) {
            return $location;
        }
        if (str_starts_with($location, '//')) {
            return 'https:' . $location;
        }

        return parse_url($base, PHP_URL_SCHEME) . '://' . parse_url($base, PHP_URL_HOST) . '/' . ltrim($location, '/');
    }
}
