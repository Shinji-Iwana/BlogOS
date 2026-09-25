<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\TypeService;

class TypeListController extends Controller
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

        $typeService = new TypeService($blog);

        // getTypes() は投稿タイプ全体を1つのDTO（slugをキーにした配列）で返す
        $types = $typeService->getTypes()?->data ?? [];

        $total = count($types);

        return view('api.type-list', [
            'blogId' => $blogId,
            'types'  => $types,
            'total'  => $total,
        ]);
    }
}
