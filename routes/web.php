<?php

use Illuminate\Support\Facades\Route;
use App\Services\ThemeService;

Route::get('/', function () {
    return view(ThemeService::index());
});
