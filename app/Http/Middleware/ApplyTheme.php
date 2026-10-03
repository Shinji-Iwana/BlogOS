<?php

namespace App\Http\Middleware;

use App\Services\ThemeService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 選んだテーマの View を、共通の画面より先に探すようにする（D-49）。
 */
class ApplyTheme
{
    public function __construct(
        protected ThemeService $themes
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->themes->apply();

        return $next($request);
    }
}
