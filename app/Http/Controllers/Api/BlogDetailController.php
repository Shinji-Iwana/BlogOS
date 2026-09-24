<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\BlogService;

class BlogDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        if ($blog === null) {
            abort(404, '指定されたブログが見つかりません。');
        }

        $blogService = new BlogService($blog);

        $data = $blogService->getSite();

        $apiData = $data ?? [];

        $basicInfo = collect($apiData)
            ->except([
                'namespaces',
                'routes',
            ])
            ->map(function ($value) {
                return is_array($value)
                    ? json_encode($value, JSON_UNESCAPED_UNICODE)
                    : $value;
            })
            ->toArray();

        $namespaces = $apiData['namespaces'] ?? [];

        $routes = [];

        foreach (($apiData['routes'] ?? []) as $path => $routeInfo) {
            $routes[] = [
                'path' => $path,
                'info' => $routeInfo,
            ];
        }

        return view('api.blog-detail', [
            'blogId'     => $blogId,
            'apiData'    => $apiData,
            'basicInfo'  => $basicInfo,
            'namespaces' => $namespaces,
            'routes'     => $routes,
            'total'      => count($routes),
        ]);
    }
}
