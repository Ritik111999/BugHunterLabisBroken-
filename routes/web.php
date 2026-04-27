<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/tester', function () {
    return view('api-tester');
});

Route::get('/demo', function () {
    return view('meeting-demo');
});
