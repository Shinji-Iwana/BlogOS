<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\TypeService;

class TypeDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId, int $typeId)
    {
        $typeService = new TypeService(
            $blogId,
            $this->blogRepository
        );

        $type = $typeService->getType(
            $typeId
        );

        return view('api.type-detail', [
            'blogId' => $blogId,
            'typeId' => $typeId,
            'type'   => $type,
        ]);
    }
}
