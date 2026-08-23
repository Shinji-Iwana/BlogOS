<?php

use Illuminate\Support\Facades\Route;
use App\Services\ThemeService;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BlogRegisterController;

// ログイン関連（認証不要）
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
Route::post('/login', [LoginController::class, 'login']);
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// トップページ（認証必須）
Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view(ThemeService::index()); // ご自身のトップページのビュー名に合わせて調整してください
    });

    // ブログ登録
    Route::get('/blog-register', [BlogRegisterController::class, 'index'])->name('blog-register');
    Route::post('/blog-register/check', [BlogRegisterController::class, 'check'])->name('blog-register.check');
    Route::post('/blog-register/store', [BlogRegisterController::class, 'store'])->name('blog-register.store');
});
