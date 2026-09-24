<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Repositories\BlogRepository;
use App\Services\WordPress\StatusService;

class StatusListController extends Controller
{
    protected BlogRepository $blogRepository;

    public function __construct(BlogRepository $blogRepository)
    {
        $this->blogRepository = $blogRepository;
    }

    public function index(int $blogId)
    {
        $statusService = new StatusService(
            $blogId,
            $this->blogRepository
        );

        $statuses = $statusService->getStatuses();

        $statuses = $statuses ?? [];

        $total = count($statuses);

        return view('api.status-list', [
            'blogId'   => $blogId,
            'statuses' => $statuses,
            'total'    => $total,
        ]);
    }
}
