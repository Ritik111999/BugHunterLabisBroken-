<?php

namespace App\Support;

/**
 * Environment variables for Python ML subprocesses (SpeechBrain / HuggingFace caches).
 */
class MlPythonEnv
{
    /**
     * @return array<string, string>
     */
    public static function forSubprocess(): array
    {
        $root = base_path();
        $base = $root.'/storage/app/speechbrain_models';
        $ecapa = $base.'/ecapa';
        $hfHome = $base.'/huggingface';
        $hub = $hfHome.'/hub';
        $torch = $base.'/torch';

        foreach ([$ecapa, $hfHome, $hub, $torch] as $dir) {
            if (! is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
        }

        return [
            'SPEECHBRAIN_CACHE' => $ecapa,
            'HF_HOME' => $hfHome,
            'HF_HUB_CACHE' => $hub,
            'TORCH_HOME' => $torch,
            'XDG_CACHE_HOME' => $base,
        ];
    }

    public static function venvPythonPath(): string
    {
        $path = base_path('scripts/.venv/bin/python');

        return is_executable($path) ? $path : '';
    }

    public static function isVenvReady(): bool
    {
        return self::venvPythonPath() !== '';
    }

    /**
     * Fast check: venv Python exists and ECAPA model artifacts are on disk.
     * Avoids running a full embed on every /health request (that can take 60s+).
     */
    public static function isVoiceprintReady(): bool
    {
        if (! self::isVenvReady()) {
            return false;
        }

        $hyperparams = base_path('storage/app/speechbrain_models/ecapa/hyperparams.yaml');

        return is_file($hyperparams) || is_link($hyperparams);
    }
}
