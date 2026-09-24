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

    public function index(int $blogId)
    {
        $typeService = new TypeService(
            $blogId,
            $this->blogRepository
        );

        $types = $typeService->getTypes();

        $types = $types ?? [];

        $total = count($types);

        return view('api.type-list', [
            'blogId' => $blogId,
            'types'  => $types,
            'total'  => $total,
        ]);
    }
}
