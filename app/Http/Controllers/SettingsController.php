<?php

namespace App\Http\Controllers;

use App\Repositories\BlogRepository;

class SettingsController extends Controller
{
    public function __construct(
        protected BlogRepository $blogRepository
    ) {
    }

    public function index()
    {
        $selectedBlog = $this->blogRepository->getSelectedOrFirst();

        return view('settings', [
            'blogId' => $selectedBlog?->id,
        ]);
    }
}
