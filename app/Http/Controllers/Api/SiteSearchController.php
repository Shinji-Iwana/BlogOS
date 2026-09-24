<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SiteSearchController extends Controller
{
    protected string $apiBase = 'https://si-note.com/wp-json/wp/v2';

    public function index(Request $request)
    {
        $keyword = trim((string) $request->query('q', ''));
        $results = [];

        if ($keyword !== '') {
            $results = $this->fetchSearchResults($keyword);
        }

        return view('api.site-search', [
            'keyword' => $keyword,
            'results' => $results,
            'total'   => count($results),
        ]);
    }

    private function fetchSearchResults(string $keyword): array
    {
        $allResults = [];
        $page = 1;
        $perPage = 100;
        $totalPages = 1;

        do {
            $response = $this->request()->get("{$this->apiBase}/search", [
                'search'   => $keyword,
                'per_page' => $perPage,
                'page'     => $page,
            ]);

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

    private function request()
    {
        if ($this->hasAuth()) {
            return Http::withBasicAuth(
                config('services.wp.username'),
                config('services.wp.app_password')
            );
        }

        return Http::withOptions([]);
    }

    private function hasAuth(): bool
    {
        return filled(config('services.wp.username')) && filled(config('services.wp.app_password'));
    }
}
