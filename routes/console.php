<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('deepgram:relay {--host=127.0.0.1} {--port=8082}', function () {
    $host = (string) $this->option('host');
    $port = (int) $this->option('port');

    $this->info("Starting Deepgram relay on ws://{$host}:{$port}");
    app(\App\Services\DeepgramLiveRelay::class)->run($host, $port);
})->purpose('Run Deepgram Live websocket relay server');
