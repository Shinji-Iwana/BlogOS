<?php

namespace App\Http\Controllers\Database;

use App\Http\Controllers\Controller;

use App\Repositories\BlogRepository;
use App\Repositories\AuthorRepository;

class AuthorListController extends Controller
{
    public function __construct(protected BlogRepository $blogRepository, protected AuthorRepository $authorRepository)
    {
    }

    public function index(int $blogId)
    {
        $blog = $this->blogRepository->findById($blogId);

        abort_if($blog === null, 404);

        $users = $this->authorRepository->getAll($blogId);

        return view('database.author-list', [
            'blog'  => $blog,
            'users' => $users,
            'total' => $users->count(),
        ]);
    }
}
