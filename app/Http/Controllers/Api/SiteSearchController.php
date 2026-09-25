<?php

namespace App\Http\Controllers\Api;

use App\Clients\WordPress\WordPressApiClient;
use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;

/**
 * WordPressのサイト内検索（Search API）の確認画面。
 *
 * Search APIは将来の候補（D-10-05）で、段階3のAPI確認画面の作り直しで扱いを見直す。
 * それまでは、選択中のブログを対象とし、ブログごとの認証情報で通信する（D-02-05、D-03-02）。
 */
class SiteSearchController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function index(Request $request)
    {
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');

        $keyword = trim((string) $request->query('q', ''));
        $results = [];

        if ($keyword !== '') {
            $results = $this->fetchSearchResults(WordPressApiClient::forBlog($blog), $keyword);
        }

        return view('api.site-search', [
            'keyword' => $keyword,
            'results' => $results,
            'total'   => count($results),
        ]);
    }

    private function fetchSearchResults(WordPressApiClient $client, string $keyword): array
    {
        $allResults = [];
        $page = 1;
        $totalPages = 1;

        do {
            try {
                $response = $client->get('/wp-json/wp/v2/search', [
                    'search'   => $keyword,
                    'per_page' => 100,
                    'page'     => $page,
                ]);
            } catch (ConnectionException $e) {
                break;
            }

            if ($response->failed()) {
                break;
            }

            $data = $response->json();
            if (empty($data)) {
                break;
            }

            $allResults = array_merge($allResults, $data);
            $totalPages = (int) $response->header('X-WP-TotalPages');
            $page++;
        } while ($page <= $totalPages);

        return $allResults;
    }
}
