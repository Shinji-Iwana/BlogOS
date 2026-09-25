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

    public function index()
    {
        // 対象のブログはURLで受け取らず、選択中のブログとする（BLOGOS_DECISIONS.md D-02-05）
        $blog = $this->blogRepository->findSelected();
        abort_if($blog === null, 404, 'ブログが選択されていません。');
        $blogId = $blog->id;

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
