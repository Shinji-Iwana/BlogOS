<?php

namespace App\Services\Articles;

use App\Clients\WordPress\WordPressApiClient;
use App\Models\ArticleDraft;
use App\Models\Blog;
use App\Models\Page;
use App\Repositories\BlogCredentialRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * 編集案のプレビュー（変更前と編集案を、実際のサイトの表示で比べる。D-28）。
 *
 * WordPress側の拡張（テーマの blogos-connector.php）に内容を渡し、保存せずに表示したページを、
 * BlogOSのサーバーが受け取って返す。スクリプトは取り除く。
 * ブラウザでスクリプトが動かないため、アクセス解析（GA4）・広告（AdSense）には数えられない。
 * WordPress側でも、プレビューの表示はテーマの表示回数に数えない。
 */
class DraftPreviewService
{
    public const SIDES = ['before', 'after'];

    public const DEVICES = ['pc', 'mobile'];

    /**
     * スマートフォン・PCの表示を選ぶためのブラウザの名乗り（テーマはこれでスマートフォンかを判定する）
     */
    protected const USER_AGENTS = [
        'pc'     => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0 Safari/537.36 BlogOS-Preview',
        'mobile' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1 BlogOS-Preview',
    ];

    protected const TIMEOUT_SECONDS = 30;

    public function __construct(
        protected BlogCredentialRepository $credentials,
    ) {
    }

    /**
     * プレビューのページ（スクリプトを取り除いたHTML）
     *
     * @param string $side   before：変更前（WordPressの今の記事） / after：編集案
     * @param string $device pc / mobile
     *
     * @throws DraftPreviewException
     */
    public function render(Blog $blog, ArticleDraft $draft, string $side, string $device): string
    {
        $article = $draft->article();

        if ($side === 'before' && ($article === null || $article->wordpress_id === null)) {
            throw new DraftPreviewException('新規記事のため、変更前の記事はありません。');
        }
        if ($this->credentials->findForBlog($blog->id)?->connector_extension === false) {
            throw new DraftPreviewException('WordPress側の拡張（テーマの BlogOS 連携）が無効のため、プレビューできません。ブログの画面で接続確認をしてください。');
        }

        $postType = $article instanceof Page || $draft->target_type?->value === 'page' ? 'page' : 'post';
        $body = [
            'post_id'   => (int) ($article?->wordpress_id ?? 0),
            'post_type' => $postType,
        ];
        if ($side === 'after') {
            $body += [
                'title'   => (string) $draft->title_raw,
                'content' => (string) $draft->content_raw,
                'excerpt' => (string) $draft->excerpt_raw,
            ];
        }

        try {
            $response = WordPressApiClient::forBlog($blog)->post('/wp-json/blogos/v1/preview', $body);
        } catch (ConnectionException $e) {
            throw new DraftPreviewException("WordPressに接続できませんでした：{$e->getMessage()}");
        }

        if ($response->status() === 404 && $response->json('code') === 'rest_no_route') {
            throw new DraftPreviewException('WordPress側にプレビューの機能がありません。テーマ（Theme-SI-Original）を更新してください。');
        }
        if ($response->failed()) {
            throw new DraftPreviewException('プレビューを作れませんでした：' . ($response->json('message') ?? "HTTP {$response->status()}"));
        }

        $url = (string) $response->json('url');
        if (! $this->isBlogUrl($url, $blog->home)) {
            throw new DraftPreviewException('WordPressが返したプレビューのURLが、ブログのURLではありません。');
        }

        try {
            $page = Http::timeout(self::TIMEOUT_SECONDS)
                ->withHeaders(['User-Agent' => self::USER_AGENTS[$device] ?? self::USER_AGENTS['pc']])
                ->get($url);
        } catch (ConnectionException $e) {
            throw new DraftPreviewException("プレビューのページを取得できませんでした：{$e->getMessage()}");
        }

        if ($page->failed()) {
            throw new DraftPreviewException("プレビューのページを取得できませんでした（HTTP {$page->status()}）。");
        }

        return $this->sanitize($page->body(), $blog->home);
    }

    /**
     * スクリプト（アクセス解析・広告を含む）を取り除き、リンクは新しいタブで開くようにする
     */
    public function sanitize(string $html, string $home): string
    {
        $html = (string) preg_replace([
            '#<script\b[^>]*>.*?</script\s*>#is',
            '#<script\b[^>]*/>#i',
            '#<noscript\b[^>]*>.*?</noscript\s*>#is',
            '#<meta\b[^>]*http-equiv\s*=\s*["\']?refresh[^>]*>#i',
            // onclick="..." などのイベント属性
            '#\s+on[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)#i',
        ], '', $html);

        // 相対URLの画像・CSSを読み込めるよう、ブログのURLを基準にする。リンクは新しいタブで開く
        $base = '<base href="' . e(rtrim($home, '/') . '/') . '" target="_blank">';

        return preg_match('#<head\b[^>]*>#i', $html)
            ? (string) preg_replace('#<head\b[^>]*>#i', '$0' . $base, $html, 1)
            : $base . $html;
    }

    protected function isBlogUrl(string $url, string $home): bool
    {
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && strcasecmp($host, (string) parse_url($home, PHP_URL_HOST)) === 0
            && in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true);
    }
}
