<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\MlPythonEnv;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class MeetingServicesHealthController extends Controller
{
    public function show(): JsonResponse
    {
        $sttProvider = strtolower(trim((string) env('MEETING_STT_PROVIDER', 'deepgram')));
        $deepgramKey = trim((string) (config('services.deepgram.api_key') ?: env('DEEPGRAM_API_KEY', '')));
        $pulseKey = trim((string) (config('services.pulse.api_key') ?: env('PULSE_API_KEY', '')));
        $sttConfigured = $sttProvider === 'pulse' ? $pulseKey !== '' : $deepgramKey !== '';

        $relayHost = (string) config('services.wechirp.relay_host', '127.0.0.1');
        $relayPort = (int) config('services.wechirp.relay_port', 9001);
        $relayWsUrl = (string) config('services.wechirp.relay_ws_url');
        $relayHttp = str_replace(['wss://', 'ws://'], ['https://', 'http://'], $relayWsUrl);

        $relayReachable = false;
        try {
            $res = Http::timeout(2)->get(rtrim($relayHttp, '/').'/up');
            $relayReachable = $res->successful() && trim((string) $res->body()) === 'ok';
        } catch (\Throwable) {
            $relayReachable = false;
        }

        $python = (string) config('meeting_analytics.analyzer.python', 'python3');
        $venvPython = MlPythonEnv::venvPythonPath();
        $voiceprintPython = $venvPython !== '' ? $venvPython : $python;
        $voiceprintVenvReady = $venvPython !== '';
        $voiceprintVerified = MlPythonEnv::isVoiceprintReady();

        $openAiKey = trim((string) (config('meeting_analytics.openai_api_key') ?: env('OPENAI_API_KEY', '')));

        $audioQueueDepth = 0;
        $defaultQueueDepth = 0;
        if (config('queue.default') === 'database' && \Schema::hasTable('jobs')) {
            try {
                $audioQueueDepth = (int) DB::table('jobs')->where('queue', 'audio')->count();
                $defaultQueueDepth = (int) DB::table('jobs')->where('queue', 'default')->count();
            } catch (\Throwable) {
                // ignore
            }
        }

        return response()->json([
            'stack_version' => (string) config('wechirp.stack_version', ''),
            'stack' => [
                'stt' => config('wechirp.stt.provider', 'deepgram'),
                'stt_live_model' => config('wechirp.stt.live_model', 'nova-3'),
                'ai' => config('wechirp.ai.provider', 'openai'),
                'voiceprint' => config('wechirp.voiceprint.engine', 'speechbrain'),
                'relay' => config('wechirp.relay.engine', 'php-amphp'),
                'client' => config('wechirp.client.mobile', 'capacitor'),
                'audio_disk' => (string) config('wechirp.storage.audio_disk', 'meeting_audio'),
                'queue_connection' => (string) config('queue.default'),
            ],
            'stt_provider' => $sttProvider,
            'stt_configured' => $sttConfigured,
            'stt_deepgram_only' => true,
            'relay_ws_url' => $relayWsUrl,
            'relay_host' => $relayHost,
            'relay_port' => $relayPort,
            'relay_reachable' => $relayReachable,
            'ready_for_live_ws' => $sttConfigured && $relayReachable,
            'ready_for_live_http' => $sttConfigured,
            'openai_configured' => $openAiKey !== '',
            'queue_audio_depth' => $audioQueueDepth,
            'queue_default_depth' => $defaultQueueDepth,
            'voiceprint_python' => $voiceprintPython,
            'voiceprint_venv_ready' => $voiceprintVenvReady,
            'voiceprint_verified' => $voiceprintVerified,
            'voiceprint_setup_command' => 'bash scripts/setup-voiceprint.sh',
            'voiceprint_verify_command' => 'php artisan voiceprint:verify',
        ]);
    }
}
