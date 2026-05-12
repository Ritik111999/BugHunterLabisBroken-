<?php

namespace App\Services;

use Symfony\Component\Process\Process;

class VoiceprintService
{
    /**
     * Compute a normalized SpeechBrain ECAPA embedding for an audio file.
     *
     * @return array{embedding: array<int,float>, engine: string}|null
     */
    public function computeEcapa(string $absolutePath, ?float $maxSeconds = null): ?array
    {
        $python = (string) config('meeting_analytics.analyzer.python', 'python3');
        $script = base_path('scripts/embed_audio.py');
        $timeout = (int) config('meeting_analytics.analyzer.timeout_seconds', 60);

        $process = new Process([
            $python,
            $script,
            '--file',
            $absolutePath,
            '--timeout',
            (string) $timeout,
            '--max-seconds',
            (string) ($maxSeconds ?? 0),
        ]);
        $process->setTimeout($timeout + 20);
        $process->setEnv(array_merge($_SERVER, $_ENV, [
            'PATH' => $this->buildPath(),
        ]));
        $process->run();

        if (!$process->isSuccessful()) {
            return null;
        }

        $decoded = json_decode((string) $process->getOutput(), true);
        if (!is_array($decoded) || !is_array($decoded['embedding'] ?? null)) {
            return null;
        }

        $vec = [];
        foreach ($decoded['embedding'] as $v) {
            if (is_numeric($v)) {
                $vec[] = (float) $v;
            }
        }
        if (count($vec) < 32) {
            return null;
        }

        $engine = is_string($decoded['engine'] ?? null) ? (string) $decoded['engine'] : 'speechbrain_ecapa';

        return [
            'embedding' => $vec,
            'engine' => $engine,
        ];
    }

    private function buildPath(): string
    {
        $existing = $_SERVER['PATH'] ?? $_ENV['PATH'] ?? getenv('PATH') ?? '';

        $candidates = [
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
            '/usr/local/ffmpeg/bin',
            '/opt/cpanel/ffmpeg/bin',
        ];

        $detected = null;
        foreach ($candidates as $dir) {
            if (is_executable($dir . '/ffprobe')) {
                $detected = $dir;
                break;
            }
        }

        $parts = array_filter(array_unique(array_merge(
            $detected ? [$detected] : [],
            $existing !== '' ? explode(':', $existing) : [],
            $candidates,
        )));

        return implode(':', $parts);
    }
}

