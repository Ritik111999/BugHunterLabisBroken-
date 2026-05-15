<?php

use App\Services\DeepgramLiveRelay;
use App\Support\MlPythonEnv;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('deepgram:relay {--host=} {--port=}', function () {
    $host = (string) ($this->option('host') ?: config('services.wechirp.relay_host', '127.0.0.1'));
    $port = (int) ($this->option('port') ?: config('services.wechirp.relay_port', 9001));

    $this->info("Starting Deepgram relay on ws://{$host}:{$port}");
    app(DeepgramLiveRelay::class)->run($host, $port);
})->purpose('Run Deepgram Live websocket relay server');

Artisan::command('voiceprint:verify', function () {
    $python = (string) config('meeting_analytics.analyzer.python', 'python3');
    $script = base_path('scripts/verify_voiceprint.py');

    if (! is_file($script)) {
        $this->error('Missing scripts/verify_voiceprint.py');

        return 1;
    }

    $this->info("Python: {$python}");
    if (MlPythonEnv::venvPythonPath() !== '') {
        $this->line('venv: '.MlPythonEnv::venvPythonPath());
    } else {
        $this->warn('scripts/.venv not found — run: bash scripts/setup-voiceprint.sh');
    }

    $process = new Process([$python, $script]);
    $process->setTimeout(200);
    $process->setEnv(array_merge($_SERVER, $_ENV, MlPythonEnv::forSubprocess()));
    $process->run();

    $this->line(trim((string) $process->getOutput()));
    if ($process->getErrorOutput() !== '') {
        $this->line(trim((string) $process->getErrorOutput()));
    }

    if ($process->isSuccessful()) {
        $this->info('Voice fingerprint pipeline OK.');

        return 0;
    }

    $this->error('Voice fingerprint verify failed (exit '.$process->getExitCode().').');

    return 1;
})->purpose('Verify SpeechBrain ECAPA voiceprint (embed_audio.py)');
