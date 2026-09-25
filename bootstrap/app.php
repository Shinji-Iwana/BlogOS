<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // エラーをJSONで返すかどうかは、Laravel標準の判定（リクエストがJSONを求めているか）に任せる。
        // 以前は URL が api/* のときにJSONで返していたが、api/* にはHTMLのAPI確認画面があり、
        // 画面のエラーがJSONで表示されてしまうため削除した（BLOGOS_CURRENT_STATUS.md P6）。
        //
    })->create();
