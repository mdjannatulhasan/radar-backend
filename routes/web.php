<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => 'PPS Backend',
        'status' => 'ok',
        'docs' => '/api/v1/pps/dashboard/summary',
    ]);
});
