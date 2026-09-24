<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\StatusService;

class StatusDetailController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId, int $statusId)
    {
        $statusService = new StatusService(
            $blogId,
            $this->blogRepository
        );

        $status = $statusService->getStatus(
            $statusId
        );

        return view('api.status-detail', [
            'blogId'   => $blogId,
            'statusId' => $statusId,
            'status'   => $status,
        ]);
    }
}
