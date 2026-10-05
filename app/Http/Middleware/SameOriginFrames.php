<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * BlogOS の画面を、ほかのサイトの中に入れて表示させない（D-59）。
 *
 * BlogOS 自身は、トップページの横のパネル（画面のパネル）の中に、画面を入れて表示する。
 * それ以外のサイトが BlogOS の画面を入れて、利用者に押させる（クリックジャッキング）ことを防ぐ。
 * 画面が自分で決めている場合（編集案のプレビューの Content-Security-Policy など）は、そのままにする。
 */
class SameOriginFrames
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('X-Frame-Options')) {
            $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        }

        return $response;
    }
}
