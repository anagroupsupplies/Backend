<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    $startedAt = Cache::rememberForever(
        'antenkayume_api_started_at',
        fn () => now()->toIso8601String()
    );

    return view('welcome', compact('startedAt'));
});
