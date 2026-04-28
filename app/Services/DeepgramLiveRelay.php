<?php

namespace App\Services;

use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Amp\Websocket\Client\WebsocketConnection as ClientWebsocketConnection;
use Amp\Websocket\Client\WebsocketHandshake;
use Amp\Websocket\WebsocketMessage;
use function Amp\Websocket\Client\connect;
use Amp\Websocket\Server\Rfc6455Acceptor;
use Amp\Websocket\Server\Websocket;
use Amp\Websocket\Server\WebsocketClientHandler;
use Amp\Websocket\WebsocketClient;
use App\Models\Meeting;
use App\Models\MeetingAnalytic;
use App\Models\MeetingParticipant;
use App\Models\ParticipantStat;
use App\Models\SpeakerMapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\PersonalAccessToken;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
use Symfony\Component\Process\Process;
use Amp\Websocket\WebsocketCloseCode;
use Throwable;
use function Amp\trapSignal;

class DeepgramLiveRelay
{
    public function run(string $host = '127.0.0.1', int $port = 8081): void
    {
        $sockets = [new InternetAddress($host, $port)];
        $logger = new NullLogger();
        $server = SocketHttpServer::createForDirectAccess($logger);
        $server->expose(...$sockets);

        $errorHandler = new DefaultErrorHandler();
        $acceptor = new Rfc6455Acceptor();

        $clientHandler = new class implements WebsocketClientHandler {
            public function handleClient(WebsocketClient $client, Request $request, Response $response): void
            {
                try {
                    $path = $request->getUri()->getPath();
                    if (!preg_match('#^/meetings/(\\d+)/live$#', $path, $m)) {
                        $client->sendText(json_encode(['error' => 'not_found']));
                        $client->close();
                        return;
                    }

                    $meetingId = (int) $m[1];

                    $token = '';
                    parse_str((string) $request->getUri()->getQuery(), $qs);
                    if (is_array($qs) && isset($qs['token'])) {
                        $token = (string) $qs['token'];
                    }
                    if ($token === '') {
                        $auth = (string) $request->getHeader('authorization');
                        if (preg_match('/^Bearer\\s+(.+)$/i', $auth, $mm)) {
                            $token = trim((string) $mm[1]);
                        }
                    }

                    $format = 'webm';
                    if (is_array($qs) && isset($qs['format'])) {
                        $format = strtolower(trim((string) $qs['format']));
                    }
                    (new DeepgramLiveRelayConnection)->handle($client, $meetingId, $token, $format);
                } catch (Throwable $e) {
                    $client->sendText(json_encode(['error' => 'internal_error', 'message' => $e->getMessage()]));
                    $client->close();
                }
            }
        };

        $websocket = new Websocket(
            httpServer: $server,
            logger: $logger,
            acceptor: $acceptor,
            clientHandler: $clientHandler,
        );

        $handler = new class($websocket) implements \Amp\Http\Server\RequestHandler {
            public function __construct(private readonly Websocket $websocket)
            {
            }

            public function handleRequest(Request $request): Response
            {
                $path = $request->getUri()->getPath();
                if ($path === '/up') {
                    return new Response(status: 200, body: 'ok');
                }
                if (preg_match('#^/meetings/\\d+/live$#', $path)) {
                    return $this->websocket->handleRequest($request);
                }
                return new Response(status: 404, body: 'not found');
            }
        };

        $server->start($handler, $errorHandler);
        trapSignal([\SIGINT, \SIGTERM]);
        $server->stop();
    }
}

class DeepgramLiveRelayConnection
{
    /** @var array<string,float> */
    private array $speakerSeconds = [];
    private float $totalSeconds = 0.0;
    private float $overlapSeconds = 0.0;
    private ?float $lastPersistAt = null;
    private float $lastFrontendStatsAt = 0.0;
    private float $lastFrontendTranscriptAt = 0.0;
    private ?string $statsTimerId = null;
    private ?string $transcriptTimerId = null;

    /**
     * Maps speaker_label -> ['name' => string, 'count' => int]
     * Populated from live "my name is X" detection.
     *
     * @var array<string,array{name:string,count:int}>
     */
    private array $speakerDetectedName = [];
    private bool $allowIntroEnrollment = false;
    private bool $voiceDebug = false;

    /**
     * Participants enrolled via HTTP intro before meeting started.
     * Keyed by lowercase name for fast lookup.
     *
     * @var array<string,int>  name_lower => participant_id
     */
    private array $enrolledParticipants = [];


    /** @var array<string,string> */
    private array $cumulativeSpeakerText = [];

    /**
     * Dedup recently-seen transcript segments to avoid repeated interim results.
     *
     * @var array<string,float> key => seen_at (microtime)
     */
    private array $seenTranscriptSegments = [];

    /** @var array<int,array{start:float,end:float,label:string}> */
    private array $recentSpeakerIntervals = [];

    /** @var array<int,array{start:float,end:float,path:string,embedding:array<int,float>|null,assigned_label:string|null,embed_started:bool}> */
    private array $audioChunks = [];

    private float $audioCursorSeconds = 0.0;
    private int $audioChunkIndex = 0;
    private int $embedEveryN = 5;
    private int $bootstrapChunks = 8;

    private ?string $streamWebmPath = null;
    private string $audioFormat = 'webm'; // 'webm' | 'pcm16'
    private int $pcmSampleRate = 16000;
    private float $maxAudioKeepSeconds = 35.0;
    private int $maxTranscriptCharsPerLabel = 6000;

    /** @var array<string,array{sum:array<int,float>,count:int}> */
    private array $speakerVoiceprint = [];
    /** @var array<string,float> */
    private array $labelLastEmbedAt = [];

    private float $labelWindowSeconds = 8.0;
    private float $labelMinSpeechSeconds = 2.5;
    private float $labelEmbedCooldownSeconds = 4.0;
    private float $labelMinPurity = 0.65;

    /** @var array<int,array{participant_id:int,vector:array<int,float>}> */
    private array $enrolledVoiceprints = [];

    /**
     * Latest per-label voice matching diagnostics (for frontend debug).
     *
     * @var array<string,array{evidence_count:int,best_participant_id:int|null,best_participant_name:string,best_score:float,threshold:float,matched:bool}>
     */
    private array $lastVoiceMatching = [];

    /**
     * Build transcript lines with participant display names.
     *
     * @return array<int,array{label:string,name:string,text:string}>
     */
    private function buildTranscriptLines(int $meetingId): array
    {
        $labels = array_keys($this->cumulativeSpeakerText);
        if (count($labels) === 0) {
            return [];
        }

        // Only hit the DB for labels that don't already have an in-memory name.
        $labelsNeedingDb = array_values(array_filter(
            $labels,
            fn ($l) => !isset($this->speakerDetectedName[$l])
        ));

        $mappings = collect();
        if (count($labelsNeedingDb) > 0) {
            $mappings = SpeakerMapping::query()
                ->with('participant:id,name')
                ->where('meeting_id', $meetingId)
                ->whereIn('speaker_label', $labelsNeedingDb)
                ->get()
                ->keyBy('speaker_label');
        }

        $lines = [];
        foreach ($this->cumulativeSpeakerText as $label => $text) {
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            // In-memory detected name is authoritative and instant (no DB lag).
            $name = $this->speakerDetectedName[$label]['name']
                ?? (string) ($mappings->get((string) $label)?->participant?->name ?? $label);

            $lines[] = [
                'label' => (string) $label,
                'name' => $name,
                'text' => $text,
            ];
        }
        return $lines;
    }

    /**
     * @return array{keywords:array<int,string>,summary:string,action_items:array<int,string>,sentiment:array<string,mixed>}
     */
    private function buildMeetingNlp(): array
    {
        $full = trim(implode("\n", array_map(
            fn ($label, $t) => '[' . (string) $label . '] ' . trim((string) $t),
            array_keys($this->cumulativeSpeakerText),
            array_values($this->cumulativeSpeakerText),
        )));

        $keywords = $this->extractKeywords($full);
        $summary = $this->buildSummary($full);
        $sentiment = $this->simpleSentiment($full);

        return [
            'keywords' => $keywords,
            'summary' => $summary,
            'action_items' => [],
            'sentiment' => $sentiment,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function extractKeywords(string $text): array
    {
        $t = strtolower($text);
        $t = preg_replace('/[^a-z0-9\\s]/', ' ', $t) ?? $t;
        $t = preg_replace('/\\s+/', ' ', $t) ?? $t;
        $words = array_filter(explode(' ', trim($t)), fn ($w) => $w !== '');

        $stop = array_flip([
            'the','a','an','and','or','but','to','of','in','on','for','with','is','are','was','were','be','been',
            'i','you','we','they','he','she','it','my','your','our','their','me','him','her','them',
            'this','that','these','those','so','as','at','by','from','not','do','did','does','have','has','had',
            'can','could','will','would','should','may','might','am','im','i\'m','name','say','says',
        ]);

        $counts = [];
        foreach ($words as $w) {
            if (isset($stop[$w])) continue;
            if (strlen($w) < 3) continue;
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }
        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }

    private function buildSummary(string $text): string
    {
        $t = trim(preg_replace('/\\s+/', ' ', $text) ?? $text);
        if ($t === '') return '';
        return mb_substr($t, max(0, mb_strlen($t) - 500));
    }

    /**
     * @return array<string,mixed>
     */
    private function simpleSentiment(string $text): array
    {
        $t = strtolower($text);
        $pos = ['good','great','nice','love','like','excellent','amazing','awesome','happy','thanks','thank'];
        $neg = ['bad','hate','issue','problem','sad','angry','terrible','awful','worse','worst','fail','error'];
        $p = 0; $n = 0;
        foreach ($pos as $w) $p += substr_count($t, $w);
        foreach ($neg as $w) $n += substr_count($t, $w);
        $score = $p - $n;
        $label = $score > 0 ? 'positive' : ($score < 0 ? 'negative' : 'neutral');
        return ['label' => $label, 'score' => $score, 'positive_hits' => $p, 'negative_hits' => $n];
    }

    private function loadEnrolledParticipants(int $meetingId): void
    {
        $participants = MeetingParticipant::query()
            ->where('meeting_id', $meetingId)
            ->whereNotNull('voice_embedding')
            ->get(['id', 'name', 'voice_embedding']);

        foreach ($participants as $p) {
            $ve = is_array($p->voice_embedding) ? $p->voice_embedding : [];
            // Only preload participants enrolled via intro (HTTP path).
            if (in_array($ve['type'] ?? '', ['intro_name_enrollment'], true)) {
                $this->enrolledParticipants[strtolower((string) $p->name)] = (int) $p->id;
            }
        }
    }

    private function loadEnrolledVoiceprints(int $meetingId): void
    {
        $participants = MeetingParticipant::query()
            ->where('meeting_id', $meetingId)
            ->whereNotNull('voice_embedding')
            ->get(['id', 'voice_embedding']);

        $out = [];
        foreach ($participants as $p) {
            $ve = is_array($p->voice_embedding) ? $p->voice_embedding : [];
            $vec = $ve['voiceprint'] ?? null;
            if (!is_array($vec) || count($vec) < 32) {
                continue;
            }
            $floats = [];
            foreach ($vec as $v) {
                if (is_numeric($v)) {
                    $floats[] = (float) $v;
                }
            }
            if (count($floats) < 32) {
                continue;
            }
            $out[] = [
                'participant_id' => (int) $p->id,
                'vector' => $floats,
            ];
        }
        $this->enrolledVoiceprints = $out;
    }

    /**
     * Save a binary MediaRecorder chunk and compute its embedding.
     * Returns the saved chunk metadata (also stored on $this->audioChunks).
     *
     * @param string $bytes
     * @return array{start:float,end:float,path:string,embedding:array<int,float>|null,assigned_label:string|null}
     */
    private function ingestAudioChunk(int $meetingId, string $bytes): array
    {
        $dir = "meeting_live_ws/{$meetingId}";
        $idx = $this->audioChunkIndex++;
        $rel = "{$dir}/chunk_{$idx}.webm";
        Storage::put($rel, $bytes);
        $abs = Storage::path($rel);

        // Keep a single growing WebM that DOES contain a proper header.
        // MediaRecorder timeslices often yield chunks that are not standalone-decodable (missing EBML header),
        // but appending the bytes preserves a valid stream container for ffmpeg trimming by time range.
        if ($this->streamWebmPath === null) {
            $streamRel = "{$dir}/stream.webm";
            $this->streamWebmPath = Storage::path($streamRel);
        }
        try {
            @file_put_contents((string) $this->streamWebmPath, $bytes, \FILE_APPEND);
        } catch (Throwable) {
        }

        // Avoid ffprobe per chunk (slow). Use the known recorder slice duration.
        $dur = (float) (env('MEETING_WS_SLICE_SECONDS', 1.0));
        $start = $this->audioCursorSeconds;
        $end = $start + max(0.0, $dur);
        $this->audioCursorSeconds = $end;

        $chunk = [
            'start' => $start,
            'end' => $end,
            'path' => $abs,
            'embedding' => null,
            'assigned_label' => null,
            'embed_started' => false,
        ];
        $this->audioChunks[] = $chunk;
        $this->pruneAudioBuffers();

        return $chunk;
    }

    /**
     * Ingest a PCM16LE 16kHz mono chunk.
     *
     * @param string $bytes
     * @return array{start:float,end:float,path:string,embedding:array<int,float>|null,assigned_label:string|null}
     */
    private function ingestPcmChunk(int $meetingId, string $bytes): array
    {
        $dir = "meeting_live_ws/{$meetingId}";
        $idx = $this->audioChunkIndex++;
        $rel = "{$dir}/chunk_{$idx}.pcm";
        Storage::put($rel, $bytes);
        $abs = Storage::path($rel);

        $bytesPerSecond = $this->pcmSampleRate * 2; // int16 mono
        $dur = $bytesPerSecond > 0 ? (strlen($bytes) / $bytesPerSecond) : 0.0;
        $dur = max(0.0, (float) $dur);

        $start = $this->audioCursorSeconds;
        $end = $start + $dur;
        $this->audioCursorSeconds = $end;

        $chunk = [
            'start' => $start,
            'end' => $end,
            'path' => $abs,
            'embedding' => null,
            'assigned_label' => null,
            'embed_started' => false,
            // store bytes for accurate slicing (avoid disk reads)
            'bytes' => $bytes,
        ];
        $this->audioChunks[] = $chunk;
        $this->pruneAudioBuffers();

        return $chunk;
    }

    private function pruneAudioBuffers(): void
    {
        // Keep only recent audio in memory. Long meetings otherwise grow unbounded and will eventually crash.
        $keepSeconds = max($this->labelWindowSeconds + 10.0, $this->maxAudioKeepSeconds);
        $cut = max(0.0, $this->audioCursorSeconds - $keepSeconds);
        $this->audioChunks = array_values(array_filter(
            $this->audioChunks,
            fn ($c) => (float) ($c['end'] ?? 0.0) >= $cut
        ));
    }

    private function appendSpeakerText(string $label, string $text): void
    {
        $existing = (string) ($this->cumulativeSpeakerText[$label] ?? '');
        $combined = trim($existing . ' ' . trim($text));
        if (mb_strlen($combined) > $this->maxTranscriptCharsPerLabel) {
            $combined = mb_substr($combined, -1 * $this->maxTranscriptCharsPerLabel);
        }
        $this->cumulativeSpeakerText[$label] = $combined;
    }

    private function writeWavPcm16(string $wavPath, string $pcmBytes, int $sampleRate): void
    {
        $numChannels = 1;
        $bitsPerSample = 16;
        $blockAlign = (int) ($numChannels * ($bitsPerSample / 8));
        $byteRate = (int) ($sampleRate * $blockAlign);
        $dataSize = strlen($pcmBytes);
        $riffSize = 36 + $dataSize;

        $hdr =
            "RIFF" .
            pack('V', $riffSize) .
            "WAVE" .
            "fmt " .
            pack('V', 16) .           // fmt chunk size
            pack('v', 1) .            // PCM
            pack('v', $numChannels) .
            pack('V', $sampleRate) .
            pack('V', $byteRate) .
            pack('v', $blockAlign) .
            pack('v', $bitsPerSample) .
            "data" .
            pack('V', $dataSize);

        file_put_contents($wavPath, $hdr . $pcmBytes);
    }

    private function probeDurationSeconds(string $absolutePath): float
    {
        $timeout = (int) config('meeting_analytics.analyzer.timeout_seconds', 60);
        $p = new Process([
            'ffprobe',
            '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $absolutePath,
        ]);
        $p->setTimeout($timeout);
        $p->run();
        if (!$p->isSuccessful()) {
            return 0.0;
        }
        $out = trim((string) $p->getOutput());
        $v = is_numeric($out) ? (float) $out : 0.0;
        return max(0.0, $v);
    }

    /**
     * @return array<int,float>|null
     */
    private function computeEmbedding(string $absolutePath): ?array
    {
        $python = (string) config('meeting_analytics.analyzer.python', 'python3');
        $script = base_path('scripts/embed_audio.py');
        $timeout = (int) config('meeting_analytics.analyzer.timeout_seconds', 60);

        $p = new Process([$python, $script, '--file', $absolutePath, '--timeout', (string) $timeout]);
        $p->setTimeout($timeout + 10);
        $p->setEnv(array_merge($_SERVER, $_ENV, [
            'PATH' => $this->buildPath(),
        ]));
        $p->run();

        if (!$p->isSuccessful()) {
            return null;
        }
        $decoded = json_decode((string) $p->getOutput(), true);
        if (!is_array($decoded) || !is_array($decoded['embedding'] ?? null)) {
            return null;
        }
        $vec = [];
        foreach ($decoded['embedding'] as $v) {
            if (is_numeric($v)) {
                $vec[] = (float) $v;
            }
        }
        return count($vec) > 0 ? $vec : null;
    }

    private function buildPath(): string
    {
        $existing = $_SERVER['PATH'] ?? $_ENV['PATH'] ?? getenv('PATH') ?? '';
        $candidates = [
            '/opt/homebrew/bin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ];
        $parts = array_filter(array_unique(array_merge(
            $existing !== '' ? explode(':', $existing) : [],
            $candidates,
        )));
        return implode(':', $parts);
    }

    /**
     * Compute a speaker embedding for a specific time range by concatenating
     * the overlapping chunk files and trimming to the requested window.
     *
     * @return array<int,float>|null
     */
    private function computeEmbeddingForRange(int $meetingId, float $rangeStart, float $rangeEnd): ?array
    {
        $rangeStart = max(0.0, $rangeStart);
        $rangeEnd = max($rangeStart, $rangeEnd);
        $dur = $rangeEnd - $rangeStart;
        if ($dur < 0.8) {
            return null;
        }

        $timeout = (int) config('meeting_analytics.analyzer.timeout_seconds', 60);
        $trimBase = tempnam(sys_get_temp_dir(), 'wchirp_trim_') ?: null;
        if (!$trimBase) {
            return null;
        }
        $trimPath = $trimBase . '.wav';

        try {
            if ($this->audioFormat === 'pcm16') {
                $bytesPerSecond = $this->pcmSampleRate * 2;
                if ($bytesPerSecond <= 0) {
                    return null;
                }

                $pcm = '';
                foreach ($this->audioChunks as $c) {
                    $cs = (float) ($c['start'] ?? 0.0);
                    $ce = (float) ($c['end'] ?? 0.0);
                    if ($ce <= $rangeStart || $cs >= $rangeEnd) {
                        continue;
                    }
                    $b = (string) ($c['bytes'] ?? '');
                    if ($b === '') {
                        continue;
                    }
                    $chunkDur = max(1e-6, $ce - $cs);
                    $chunkLen = strlen($b);

                    $segStart = max($rangeStart, $cs);
                    $segEnd = min($rangeEnd, $ce);
                    $oStart = ($segStart - $cs) / $chunkDur;
                    $oEnd = ($segEnd - $cs) / $chunkDur;

                    $byteStart = (int) floor(max(0.0, $oStart) * $chunkLen);
                    $byteEnd = (int) ceil(min(1.0, $oEnd) * $chunkLen);
                    $byteStart = max(0, min($chunkLen, $byteStart));
                    $byteEnd = max($byteStart, min($chunkLen, $byteEnd));

                    // align to int16 samples
                    $byteStart -= ($byteStart % 2);
                    $byteEnd -= ($byteEnd % 2);

                    if ($byteEnd > $byteStart) {
                        $pcm .= substr($b, $byteStart, $byteEnd - $byteStart);
                    }
                }

                if (strlen($pcm) < (int) (0.8 * $bytesPerSecond)) {
                    return null;
                }

                $this->writeWavPcm16($trimPath, $pcm, $this->pcmSampleRate);
            } else {
                $streamPath = $this->streamWebmPath;
                if (!$streamPath || !is_file($streamPath)) {
                    return null;
                }
                $snapBase = tempnam(sys_get_temp_dir(), 'wchirp_ws_stream_') ?: null;
                if (!$snapBase) {
                    return null;
                }
                $snapPath = $snapBase . '.webm';
                try {
                    @copy($streamPath, $snapPath);
                    if (!is_file($snapPath) || filesize($snapPath) < 1024) {
                        return null;
                    }

                    $p2 = new Process([
                        'ffmpeg',
                        '-hide_banner',
                        '-loglevel', 'error',
                        '-fflags', '+genpts',
                        '-i', $snapPath,
                        '-ss', (string) $rangeStart,
                        '-t', (string) $dur,
                        '-ac', '1',
                        '-ar', '16000',
                        '-af', 'loudnorm=I=-16:LRA=11:TP=-1.5',
                        $trimPath,
                    ]);
                    $p2->setTimeout($timeout + 30);
                    $p2->setEnv(array_merge($_SERVER, $_ENV, ['PATH' => $this->buildPath()]));
                    $p2->run();
                    if (!$p2->isSuccessful()) {
                        $this->debugVoice('ffmpeg_trim_failed', [
                            'meeting_id' => $meetingId,
                            'range_start' => $rangeStart,
                            'range_end' => $rangeEnd,
                            'dur' => $dur,
                            'exit' => $p2->getExitCode(),
                            'stderr' => trim((string) $p2->getErrorOutput()),
                        ]);
                        return null;
                    }
                } finally {
                    @unlink($snapPath);
                    @unlink($snapBase);
                }
            }

            $vec = $this->computeEmbedding($trimPath);
            if (!$vec) {
                $this->debugVoice('embed_failed', [
                    'meeting_id' => $meetingId,
                    'range_start' => $rangeStart,
                    'range_end' => $rangeEnd,
                ]);
                return null;
            }
            $this->debugVoice('embed_ok', [
                'meeting_id' => $meetingId,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
                'dim' => count($vec),
            ]);
            return $vec;
        } finally {
            @unlink($trimPath);
            @unlink($trimBase);
        }
    }

    /**
     * Use Deepgram diarization intervals to compute embeddings per speaker label window.
     * This improves multi-speaker handling versus "whole chunk" assignment.
     */
    private function maybeComputeLabelEmbeddings(int $meetingId): void
    {
        if (count($this->recentSpeakerIntervals) === 0 || count($this->audioChunks) === 0) {
            return;
        }

        $windowStart = max(0.0, $this->totalSeconds - $this->labelWindowSeconds);

        $speechByLabel = [];
        $minStartByLabel = [];
        $maxEndByLabel = [];
        foreach ($this->recentSpeakerIntervals as $it) {
            $label = (string) ($it['label'] ?? '');
            if ($label === '') continue;
            $s = (float) ($it['start'] ?? 0.0);
            $e = (float) ($it['end'] ?? 0.0);
            if ($e <= $s) continue;
            if ($e < $windowStart) continue;

            $s2 = max($s, $windowStart);
            $speechByLabel[$label] = ($speechByLabel[$label] ?? 0.0) + ($e - $s2);
            $minStartByLabel[$label] = isset($minStartByLabel[$label]) ? min($minStartByLabel[$label], $s2) : $s2;
            $maxEndByLabel[$label] = isset($maxEndByLabel[$label]) ? max($maxEndByLabel[$label], $e) : $e;
        }

        $now = microtime(true);
        foreach ($speechByLabel as $label => $speechSeconds) {
            if ((float) $speechSeconds < $this->labelMinSpeechSeconds) {
                $this->debugVoice('label_insufficient_speech', [
                    'meeting_id' => $meetingId,
                    'label' => $label,
                    'speech_seconds' => (float) $speechSeconds,
                    'min_speech_seconds' => $this->labelMinSpeechSeconds,
                    'window_seconds' => $this->labelWindowSeconds,
                ]);
                continue;
            }

            $last = (float) ($this->labelLastEmbedAt[$label] ?? 0.0);
            if ($last > 0.0 && ($now - $last) < $this->labelEmbedCooldownSeconds) {
                $this->debugVoice('label_cooldown', [
                    'meeting_id' => $meetingId,
                    'label' => $label,
                    'since_last_seconds' => ($now - $last),
                    'cooldown_seconds' => $this->labelEmbedCooldownSeconds,
                ]);
                continue;
            }
            $this->labelLastEmbedAt[$label] = $now;

            $rangeStart = (float) ($minStartByLabel[$label] ?? $windowStart);
            $rangeEnd = (float) ($maxEndByLabel[$label] ?? ($rangeStart + 1.0));
            $span = max(1e-6, $rangeEnd - $rangeStart);
            $purity = (float) $speechSeconds / $span;
            if ($purity < $this->labelMinPurity) {
                $this->debugVoice('label_low_purity', [
                    'meeting_id' => $meetingId,
                    'label' => $label,
                    'speech_seconds' => (float) $speechSeconds,
                    'span_seconds' => $span,
                    'purity' => $purity,
                    'min_purity' => $this->labelMinPurity,
                ]);
                continue;
            }

            $this->debugVoice('label_embed_scheduled', [
                'meeting_id' => $meetingId,
                'label' => $label,
                'speech_seconds' => (float) $speechSeconds,
                'purity' => $purity,
                'range_start' => $rangeStart,
                'range_end' => $rangeEnd,
            ]);

            EventLoop::queue(function () use ($meetingId, $label, $rangeStart, $rangeEnd) {
                $vec = $this->computeEmbeddingForRange($meetingId, $rangeStart, $rangeEnd);
                if (!is_array($vec) || count($vec) === 0) {
                    $this->debugVoice('label_embed_empty', [
                        'meeting_id' => $meetingId,
                        'label' => $label,
                        'range_start' => $rangeStart,
                        'range_end' => $rangeEnd,
                    ]);
                    return;
                }
                $this->accumulateVoiceprint((string) $label, $vec);
                $this->debugVoice('label_embed_accumulated', [
                    'meeting_id' => $meetingId,
                    'label' => $label,
                    'count' => (int) (($this->speakerVoiceprint[(string) $label]['count'] ?? 0)),
                ]);
            });
        }
    }

    /**
     * Assign speaker label to any unassigned audio chunks by overlap with diarization intervals,
     * then accumulate voiceprints per speaker label.
     */
    private function assignChunksToSpeakers(): void
    {
        if (count($this->recentSpeakerIntervals) === 0 || count($this->audioChunks) === 0) {
            return;
        }

        foreach ($this->audioChunks as $i => $chunk) {
            if ($chunk['assigned_label'] !== null) {
                continue;
            }
            $start = (float) $chunk['start'];
            $end = (float) $chunk['end'];
            if ($end <= $start) {
                $this->audioChunks[$i]['assigned_label'] = 'speaker_unknown';
                continue;
            }

            $byLabel = [];
            foreach ($this->recentSpeakerIntervals as $it) {
                $os = max($start, (float) $it['start']);
                $oe = min($end, (float) $it['end']);
                $ov = max(0.0, $oe - $os);
                if ($ov <= 0.0) {
                    continue;
                }
                $lab = (string) $it['label'];
                $byLabel[$lab] = ($byLabel[$lab] ?? 0.0) + $ov;
            }

            if (count($byLabel) === 0) {
                continue;
            }
            arsort($byLabel);
            $label = (string) array_key_first($byLabel);
            $this->audioChunks[$i]['assigned_label'] = $label;
        }
    }

    /**
     * @param array<int,float> $vec
     */
    private function accumulateVoiceprint(string $label, array $vec): void
    {
        if (!isset($this->speakerVoiceprint[$label])) {
            $this->speakerVoiceprint[$label] = ['sum' => array_fill(0, count($vec), 0.0), 'count' => 0];
        }
        $sum = $this->speakerVoiceprint[$label]['sum'];
        $n = min(count($sum), count($vec));
        for ($i = 0; $i < $n; $i++) {
            $sum[$i] += (float) $vec[$i];
        }
        $this->speakerVoiceprint[$label]['sum'] = $sum;
        $this->speakerVoiceprint[$label]['count']++;
    }

    /**
     * @return array{vec:array<int,float>,count:int}|null
     */
    private function getVoiceprintForLabel(string $label): ?array
    {
        $acc = $this->speakerVoiceprint[$label] ?? null;
        if (!$acc || ($acc['count'] ?? 0) < 1) {
            return null;
        }
        $sum = $acc['sum'];
        $count = max(1, (int) $acc['count']);
        $avg = [];
        foreach ($sum as $v) {
            $avg[] = (float) $v / $count;
        }
        // normalize
        $norm = 0.0;
        foreach ($avg as $v) {
            $norm += $v * $v;
        }
        $norm = sqrt(max(1e-12, $norm));
        for ($i = 0; $i < count($avg); $i++) {
            $avg[$i] = $avg[$i] / $norm;
        }
        return ['vec' => $avg, 'count' => $count];
    }

    /**
     * @param array<int,float> $a
     * @param array<int,float> $b
     */
    private function cosineSimilarity(array $a, array $b): float
    {
        $n = min(count($a), count($b));
        if ($n === 0) {
            return 0.0;
        }
        $dot = 0.0;
        $na = 0.0;
        $nb = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $av = (float) $a[$i];
            $bv = (float) $b[$i];
            $dot += $av * $bv;
            $na += $av * $av;
            $nb += $bv * $bv;
        }
        if ($na <= 0.0 || $nb <= 0.0) {
            return 0.0;
        }
        return $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * @param array<int,float> $vec
     * @return array{0:int,1:float}|null
     */
    private function matchVoiceprint(array $vec, int $evidenceCount = 1): ?array
    {
        if (count($this->enrolledVoiceprints) === 0) {
            return null;
        }
        $threshold = (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75));
        $minMargin = (float) (env('MEETING_VOICEPRINT_MARGIN', 0.05));
        // If we only have 1 chunk of evidence, require a stronger score to avoid early mislabels.
        if ($evidenceCount < 2) {
            $threshold += (float) (env('MEETING_VOICEPRINT_EARLY_BOOST', 0.07));
        }
        $bestId = null;
        $bestScore = -1.0;
        $secondBest = -1.0;
        foreach ($this->enrolledVoiceprints as $e) {
            $score = $this->cosineSimilarity($vec, $e['vector']);
            if ($score > $bestScore) {
                $secondBest = $bestScore;
                $bestScore = $score;
                $bestId = (int) $e['participant_id'];
            } elseif ($score > $secondBest) {
                $secondBest = $score;
            }
        }
        if ($bestId === null || $bestScore < $threshold) {
            return null;
        }
        if (($bestScore - max(-1.0, $secondBest)) < $minMargin) {
            return null;
        }
        return [$bestId, $bestScore];
    }

    private function debugVoice(string $event, array $ctx = []): void
    {
        if (!$this->voiceDebug) {
            return;
        }
        try {
            Log::info('voiceprint_debug.' . $event, $ctx);
        } catch (\Throwable) {
        }
    }

    public function handle(WebsocketClient $frontend, int $meetingId, string $sanctumToken, string $format = 'webm'): void
    {
        if ($meetingId <= 0) {
            $frontend->close();
            return;
        }

        $user = $this->authenticate($sanctumToken);
        if (!$user) {
            $frontend->sendText(json_encode(['error' => 'unauthenticated']));
            $frontend->close();
            return;
        }

        $meeting = Meeting::query()->whereKey($meetingId)->where('host_id', $user->id)->first();
        if (!$meeting) {
            $frontend->sendText(json_encode(['error' => 'meeting_not_found']));
            $frontend->close();
            return;
        }

        // Only allow "my name is X" based enrollment during the intro phase.
        // During the meeting we must rely on voiceprint matching only.
        $this->allowIntroEnrollment = (string) ($meeting->status ?? '') === 'pending';
        $this->audioFormat = in_array($format, ['webm', 'pcm16'], true) ? $format : 'webm';

        $deepgramKey = trim((string) env('DEEPGRAM_API_KEY', ''));
        if ($deepgramKey === '') {
            $frontend->sendText(json_encode(['error' => 'deepgram_key_missing']));
            $frontend->close();
            return;
        }

        // Preload participants enrolled via HTTP intro so we can merge them
        // when the same name is detected in the live WS session.
        $this->loadEnrolledParticipants($meetingId);
        $this->loadEnrolledVoiceprints($meetingId);
        $this->embedEveryN = max(1, (int) (env('MEETING_WS_EMBED_EVERY_N', 5)));
        $this->bootstrapChunks = max(1, (int) (env('MEETING_WS_BOOTSTRAP_CHUNKS', 8)));
        $this->labelWindowSeconds = (float) (env('MEETING_WS_LABEL_WINDOW_SECONDS', 8));
        $this->labelMinSpeechSeconds = (float) (env('MEETING_WS_LABEL_MIN_SPEECH_SECONDS', 2.5));
        $this->labelEmbedCooldownSeconds = (float) (env('MEETING_WS_LABEL_EMBED_COOLDOWN_SECONDS', 4));
        $this->labelMinPurity = (float) (env('MEETING_WS_LABEL_MIN_PURITY', 0.65));
        $this->voiceDebug = (bool) (env('MEETING_VOICEPRINT_DEBUG', false));
        $this->maxAudioKeepSeconds = (float) (env('MEETING_WS_MAX_AUDIO_KEEP_SECONDS', 35));
        $this->maxTranscriptCharsPerLabel = (int) (env('MEETING_WS_MAX_TRANSCRIPT_CHARS', 6000));

        $deepgram = $this->connectDeepgram($deepgramKey);

        // Coalesce frontend updates on timers (prevents 1006 from browser overload).
        $this->statsTimerId = EventLoop::repeat(0.25, function () use ($frontend, $meetingId) {
            if ($frontend->isClosed()) {
                return;
            }
            try {
                $snapshot = $this->buildSnapshot($meetingId);
                if ($snapshot) {
                    $frontend->sendText(json_encode(['event' => 'stats.updated', 'data' => $snapshot]));
                }
            } catch (Throwable $e) {
                Log::warning('relay_frontend_send_failed', [
                    'meeting_id' => $meetingId,
                    'event' => 'stats.updated',
                    'message' => $e->getMessage(),
                ]);
                try { $frontend->close(WebsocketCloseCode::INTERNAL_ERROR, 'frontend_send_failed'); } catch (Throwable) {}
            }
        });

        // Faster transcript updates, but keep payload small.
        $this->transcriptTimerId = EventLoop::repeat(0.25, function () use ($frontend, $meetingId) {
            if ($frontend->isClosed()) {
                return;
            }
            try {
                $lines = $this->buildTranscriptLines($meetingId);
                // Avoid unbounded transcript payloads.
                $lines = array_slice($lines, 0, 8);
                foreach ($lines as &$ln) {
                    $ln['text'] = mb_substr((string) ($ln['text'] ?? ''), -350);
                }
                $frontend->sendText(json_encode([
                    'event' => 'transcript.updated',
                    'data' => [
                        'meeting_id' => $meetingId,
                        'lines' => $lines,
                    ],
                ]));
            } catch (Throwable $e) {
                Log::warning('relay_frontend_send_failed', [
                    'meeting_id' => $meetingId,
                    'event' => 'transcript.updated',
                    'message' => $e->getMessage(),
                ]);
                try { $frontend->close(WebsocketCloseCode::INTERNAL_ERROR, 'frontend_send_failed'); } catch (Throwable) {}
            }
        });

        // IMPORTANT: Do NOT auto-close the frontend WS from the relay.
        // The connection should remain open indefinitely and only close when:
        // - the user ends the meeting in the UI (client closes), or
        // - the client navigates away, or
        // - an internal error occurs.

        // Keep Deepgram connected in a background loop.
        // IMPORTANT: Never close the frontend WS just because Deepgram disconnects.
        EventLoop::queue(function () use (&$deepgram, $deepgramKey, $frontend, $meetingId) {
            $backoffMs = 500;
            while (!$frontend->isClosed()) {
                try {
                    while ($msg = $deepgram->receive()) {
                        $text = $this->readClientMessage($msg);
                        if ($text === null) {
                            continue;
                        }
                        $this->onDeepgramMessage($meetingId, $text);
                        $this->assignChunksToSpeakers();
                    }
                } catch (Throwable $e) {
                    Log::warning('deepgram_receive_loop_failed', [
                        'meeting_id' => $meetingId,
                        'message' => $e->getMessage(),
                    ]);
                    try {
                        if (!$frontend->isClosed()) {
                            $frontend->sendText(json_encode(['event' => 'deepgram.disconnected', 'message' => $e->getMessage()]));
                        }
                    } catch (Throwable) {
                    }
                }

                // Deepgram ended or errored — reconnect with backoff.
                try {
                    $deepgram = $this->connectDeepgram($deepgramKey);
                    $backoffMs = 500;
                    try {
                        if (!$frontend->isClosed()) {
                            $frontend->sendText(json_encode(['event' => 'deepgram.reconnected']));
                        }
                    } catch (Throwable) {
                    }
                } catch (Throwable $e) {
                    Log::warning('deepgram_reconnect_failed', [
                        'meeting_id' => $meetingId,
                        'message' => $e->getMessage(),
                    ]);
                    $backoffMs = min(8000, (int) ($backoffMs * 1.6));
                }

                // Sleep a bit before retry/reconnect to avoid tight loops.
                try {
                    \usleep((int) ($backoffMs * 1000));
                } catch (Throwable) {
                }
            }
        });

        try {
            while ($message = $frontend->receive()) {
                if ($message->isBinary()) {
                    $bytes = $message->buffer();
                    try {
                        $deepgram->sendBinary($bytes);
                    } catch (Throwable $e) {
                        Log::warning('deepgram_send_failed', [
                            'meeting_id' => $meetingId,
                            'message' => $e->getMessage(),
                        ]);
                        // Deepgram will be reconnected by the background loop.
                    }
                    if ($this->audioFormat === 'pcm16') {
                        $this->ingestPcmChunk($meetingId, $bytes);
                    } else {
                        $this->ingestAudioChunk($meetingId, $bytes);
                    }
                }
            }
        } catch (Throwable $e) {
            Log::warning('relay_frontend_ws_receive_failed', [
                'meeting_id' => $meetingId,
                'message' => $e->getMessage(),
            ]);
            try {
                if (!$frontend->isClosed()) {
                    $frontend->close(WebsocketCloseCode::INTERNAL_ERROR, 'frontend_receive_failed');
                }
            } catch (Throwable) {
            }
        } finally {
            // Cancel timers.
            try { if ($this->statsTimerId) EventLoop::cancel($this->statsTimerId); } catch (Throwable) {}
            try { if ($this->transcriptTimerId) EventLoop::cancel($this->transcriptTimerId); } catch (Throwable) {}
            try {
                $deepgram->close();
            } catch (Throwable) {
            }
            try {
                // Ensure final persist on disconnect / end.
                $this->persistToDb($meetingId);
            } catch (Throwable) {
            }
        }
    }

    private function authenticate(string $token): ?object
    {
        $pat = PersonalAccessToken::findToken($token);
        return $pat?->tokenable;
    }

    private function connectDeepgram(string $apiKey): ClientWebsocketConnection
    {
        $model = (string) (config('services.deepgram.live_model') ?: env('DEEPGRAM_LIVE_MODEL') ?: 'nova-3');
        $params = [
            // Nova-3 tends to perform better for noisy / multi-speaker meeting audio.
            'model' => $model,
            'diarize' => 'true',
            'punctuate' => 'true',
            'smart_format' => 'true',
            'interim_results' => 'true',
            'utterances' => 'true',
        ];
        if ($this->audioFormat === 'pcm16') {
            $params['encoding'] = 'linear16';
            $params['sample_rate'] = (string) $this->pcmSampleRate;
            $params['channels'] = '1';
        }
        $query = http_build_query($params);

        $handshake = new WebsocketHandshake("wss://api.deepgram.com/v1/listen?{$query}");
        $handshake = $handshake->withHeader('Authorization', "Token {$apiKey}");

        return connect($handshake);
    }

    private function readClientMessage(WebsocketMessage $message): ?string
    {
        try {
            return $message->buffer();
        } catch (Throwable) {
            return null;
        }
    }

    private function onDeepgramMessage(int $meetingId, string $json): void
    {
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }

        $now = microtime(true);
        // prune dedupe cache (keep small, time-bounded)
        foreach ($this->seenTranscriptSegments as $k => $t) {
            if (($now - (float) $t) > 30.0) {
                unset($this->seenTranscriptSegments[$k]);
            }
        }

        $intervals = [];
        $speakerText = [];

        $utterances = $data['utterances']
            ?? ($data['channel']['alternatives'][0]['utterances'] ?? null)
            ?? ($data['results']['utterances'] ?? null);

        if (is_array($utterances) && count($utterances) > 0) {
            foreach ($utterances as $u) {
                if (!is_array($u)) {
                    continue;
                }
                $speaker = $u['speaker'] ?? null;
                $start = (float) ($u['start'] ?? 0);
                $end = (float) ($u['end'] ?? 0);
                if ($end <= $start) {
                    continue;
                }

                $label = $speaker === null ? 'speaker_unknown' : ('speaker_' . (int) $speaker);
                $dur = max(0.0, $end - $start);
                $this->speakerSeconds[$label] = ($this->speakerSeconds[$label] ?? 0.0) + $dur;
                $intervals[] = [$start, $end];
                $this->totalSeconds = max($this->totalSeconds, $end);
                $this->recentSpeakerIntervals[] = ['label' => $label, 'start' => $start, 'end' => $end];

                $t = (string) ($u['transcript'] ?? '');
                if ($t !== '') {
                    $t2 = trim($t);
                    $segKey = $label . '|' . number_format($start, 2, '.', '') . '|' . number_format($end, 2, '.', '') . '|' . md5($t2);
                    if (!isset($this->seenTranscriptSegments[$segKey])) {
                        $this->seenTranscriptSegments[$segKey] = $now;
                        $speakerText[$label] = ($speakerText[$label] ?? '') . ' ' . $t2;
                    }
                }
            }
        } else {
            $words =
                $data['channel']['alternatives'][0]['words']
                ?? ($data['channel']['alternatives'][0]['word_timestamps'] ?? null)
                ?? ($data['results']['channels'][0]['alternatives'][0]['words'] ?? null);

            if (!is_array($words) || count($words) === 0) {
                return;
            }

            foreach ($words as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $speaker = $w['speaker'] ?? null;
                $start = (float) ($w['start'] ?? 0);
                $end = (float) ($w['end'] ?? 0);
                if ($end <= $start) {
                    continue;
                }

                $label = $speaker === null ? 'speaker_unknown' : ('speaker_' . (int) $speaker);
                $dur = max(0.0, $end - $start);
                $this->speakerSeconds[$label] = ($this->speakerSeconds[$label] ?? 0.0) + $dur;
                $intervals[] = [$start, $end];
                $this->totalSeconds = max($this->totalSeconds, $end);
                $this->recentSpeakerIntervals[] = ['label' => $label, 'start' => $start, 'end' => $end];

                $word = (string) ($w['punctuated_word'] ?? ($w['word'] ?? ''));
                if ($word !== '') {
                    // Word-level streams can repeat heavily; dedupe in small windows.
                    $word2 = trim($word);
                    $segKey = $label . '|' . number_format($start, 2, '.', '') . '|' . number_format($end, 2, '.', '') . '|' . md5($word2);
                    if (!isset($this->seenTranscriptSegments[$segKey])) {
                        $this->seenTranscriptSegments[$segKey] = $now;
                        $speakerText[$label] = ($speakerText[$label] ?? '') . ' ' . $word2;
                    }
                }
            }
        }

        $this->overlapSeconds += $this->computeOverlap($intervals);

        // Trim interval history to recent window (avoid unbounded growth).
        $cut = max(0.0, $this->totalSeconds - 30.0);
        $this->recentSpeakerIntervals = array_values(array_filter(
            $this->recentSpeakerIntervals,
            fn ($it) => (float) $it['end'] >= $cut
        ));

        // Compute per-label embeddings from diarization windows (non-blocking).
        $this->maybeComputeLabelEmbeddings($meetingId);

        // FIX #6: Accumulate text per label across all Deepgram messages in this session.
        foreach ($speakerText as $label => $text) {
            $this->appendSpeakerText((string) $label, (string) $text);
        }

        // Intro-only: Run name detection on the full cumulative text per label.
        // During the meeting we rely on voiceprint matching only.
        if ($this->allowIntroEnrollment) {
            foreach ($this->cumulativeSpeakerText as $label => $fullText) {
                $name = $this->extractNameFromText($fullText);
                if ($name === null) {
                    continue;
                }

                $existing = $this->speakerDetectedName[$label] ?? null;

                if ($existing === null) {
                    // First detection for this label.
                    $this->speakerDetectedName[$label] = ['name' => $name, 'count' => 1];
                } elseif (strtolower($existing['name']) === strtolower($name)) {
                    // Same name detected again — increase confidence.
                    $this->speakerDetectedName[$label]['count']++;
                }
            }
        }

        $now = microtime(true);
        if ($this->lastPersistAt === null || ($now - $this->lastPersistAt) > 1.0) {
            $this->lastPersistAt = $now;
            $this->persistToDb($meetingId);
        }
    }

    /**
     * FIX #3: Improved name extraction.
     * - Only captures ONE word (the actual name) after the phrase.
     * - Takes the LAST match to handle audio bleed from the previous speaker.
     * - Minimum 2 chars to avoid garbage.
     */
    private function extractNameFromText(string $text): ?string
    {
        $t = strtolower(trim($text));
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        // Capture ONLY the first word after the intro phrase (that's the name).
        $matches = [];
        preg_match_all(
            '/\b(?:my name is|i am|this is|im|i\'m)\s+([a-z][a-z]{1,29})\b/i',
            $t,
            $matches
        );

        if (empty($matches[1])) {
            return null;
        }

        // Take the LAST occurrence — most recent speaker in the audio is most relevant.
        $raw = trim((string) end($matches[1]));
        $raw = preg_replace('/[^a-z]/i', '', $raw) ?? $raw;

        if ($raw === '' || strlen($raw) < 2) {
            return null;
        }

        // Reject common false-positive words that aren't names.
        $falsePositives = ['not', 'the', 'here', 'just', 'also', 'very', 'really', 'going', 'done', 'sure'];
        if (in_array(strtolower($raw), $falsePositives, true)) {
            return null;
        }

        return ucfirst(strtolower($raw));
    }

    /** @param array<int,array{0:float,1:float}> $intervals */
    private function computeOverlap(array $intervals): float
    {
        $events = [];
        foreach ($intervals as [$s, $e]) {
            $events[] = [$s, 1];
            $events[] = [$e, -1];
        }
        usort($events, fn ($a, $b) => $a[0] <=> $b[0] ?: $b[1] <=> $a[1]);

        $active = 0;
        $last = null;
        $overlap = 0.0;
        foreach ($events as [$t, $d]) {
            if ($last !== null && $active >= 2) {
                $overlap += max(0.0, $t - $last);
            }
            $active += $d;
            $last = $t;
        }
        return $overlap;
    }

    private function persistToDb(int $meetingId): void
    {
        $total = max(1.0, $this->totalSeconds);
        $crosstalkPct = (float) round(($this->overlapSeconds / $total) * 100, 2);

        DB::transaction(function () use ($meetingId, $total, $crosstalkPct) {
            $labelToParticipantId = [];

            // Reset each persist so frontend reflects current state.
            $this->lastVoiceMatching = [];

            foreach ($this->speakerSeconds as $label => $_sec) {
                $mapping = SpeakerMapping::query()
                    ->where('meeting_id', $meetingId)
                    ->where('speaker_label', $label)
                    ->first();

                if (!$mapping) {
                    $display = $label;
                    if (preg_match('/^speaker_(\\d+)$/', $label, $m)) {
                        $display = 'Speaker ' . $m[1];
                    }
                    $participant = MeetingParticipant::create([
                        'meeting_id' => $meetingId,
                        'user_id' => null,
                        'name' => $display,
                        'voice_embedding' => [
                            'provider' => 'deepgram',
                            'type' => 'speaker_label_identity',
                            'speaker_label' => $label,
                        ],
                    ]);
                    $mapping = SpeakerMapping::create([
                        'meeting_id' => $meetingId,
                        'speaker_label' => $label,
                        'participant_id' => $participant->id,
                        'confidence' => null,
                    ]);
                }

                $labelToParticipantId[$label] = (int) $mapping->participant_id;
            }

            // Voice recognition: if we can match a label to an enrolled participant, redirect the mapping
            // and delete the placeholder participant (so UI stops showing speaker_0/speaker_1).
            foreach (array_keys($this->speakerSeconds) as $label) {
                $label = (string) $label;
                $candidate = $this->getVoiceprintForLabel($label);
                if (!$candidate) {
                    $this->lastVoiceMatching[$label] = [
                        'evidence_count' => 0,
                        'best_participant_id' => null,
                        'best_participant_name' => '',
                        'best_score' => 0.0,
                        'threshold' => (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75)),
                        'matched' => false,
                    ];
                    $this->debugVoice('no_label_voiceprint_yet', [
                        'meeting_id' => $meetingId,
                        'label' => $label,
                        'total_seconds' => $this->totalSeconds,
                        'window_seconds' => $this->labelWindowSeconds,
                        'min_speech_seconds' => $this->labelMinSpeechSeconds,
                    ]);
                    continue;
                }
                $vec = $candidate['vec'];
                $count = (int) $candidate['count'];
                // Log best score even when it doesn't pass threshold.
                $baseThreshold = (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75));
                $threshold = $baseThreshold + ($count < 2 ? (float) (env('MEETING_VOICEPRINT_EARLY_BOOST', 0.07)) : 0.0);
                $bestId = null;
                $bestScore = -1.0;
                foreach ($this->enrolledVoiceprints as $e) {
                    $s = $this->cosineSimilarity($vec, $e['vector']);
                    if ($s > $bestScore) {
                        $bestScore = $s;
                        $bestId = (int) $e['participant_id'];
                    }
                }
                $bestName = $bestId ? (string) (MeetingParticipant::query()->whereKey($bestId)->value('name') ?? '') : '';
                $matched = ($bestId !== null && $bestScore >= $threshold);
                $this->lastVoiceMatching[$label] = [
                    'evidence_count' => $count,
                    'best_participant_id' => $bestId,
                    'best_participant_name' => $bestName,
                    'best_score' => (float) $bestScore,
                    'threshold' => (float) $threshold,
                    'matched' => (bool) $matched,
                ];
                $this->debugVoice('score', [
                    'meeting_id' => $meetingId,
                    'label' => $label,
                    'evidence_count' => $count,
                    'best_participant_id' => $bestId,
                    'best_participant_name' => $bestName,
                    'best_score' => $bestScore,
                    'threshold' => $threshold,
                ]);

                $match = $matched ? [$bestId, $bestScore] : null;
                if (!$match) {
                    continue;
                }
                [$matchedParticipantId, $score] = $match;

                $currentPid = $labelToParticipantId[$label] ?? null;
                if (!$currentPid) {
                    continue;
                }
                $matchedParticipantId = (int) $matchedParticipantId;

                if ($matchedParticipantId === (int) $currentPid) {
                    // Still update confidence so we can debug/tune later.
                    SpeakerMapping::query()
                        ->where('meeting_id', $meetingId)
                        ->where('speaker_label', $label)
                        ->update(['confidence' => (float) $score]);
                    continue;
                }

                // Redirect mapping to the enrolled participant.
                SpeakerMapping::query()
                    ->where('meeting_id', $meetingId)
                    ->where('speaker_label', $label)
                    ->update([
                        'participant_id' => $matchedParticipantId,
                        'confidence' => (float) $score,
                    ]);

                // Delete placeholder participant if it looks generated.
                $placeholder = MeetingParticipant::query()->whereKey((int) $currentPid)->first();
                if ($placeholder) {
                    $isPlaceholder =
                        preg_match('/^Speaker\s+\d+$/i', (string) $placeholder->name) === 1
                        || preg_match('/^speaker_\d+$/i', (string) $placeholder->name) === 1;

                    if ($isPlaceholder) {
                        $placeholder->delete();
                    }
                }

                $labelToParticipantId[$label] = $matchedParticipantId;
            }

            // Apply detected name to each WS speaker label.
            // If the same name was enrolled via HTTP intro, merge by redirecting
            // the SpeakerMapping to the enrolled participant (no duplicate rows).
            foreach ($this->speakerDetectedName as $label => $detection) {
                $pid = $labelToParticipantId[$label] ?? null;
                if (!$pid) {
                    continue;
                }

                $realName    = $detection['name'];
                $participant = MeetingParticipant::query()->whereKey($pid)->first();
                if (!$participant) {
                    continue;
                }

                $isPlaceholder = preg_match('/^Speaker\s+\d+$/i', (string) $participant->name) === 1
                    || preg_match('/^speaker_\d+$/i', (string) $participant->name) === 1;

                if (!$isPlaceholder) {
                    continue;
                }

                // Check if HTTP-enrolled participant with this name already exists.
                $enrolledId = $this->enrolledParticipants[strtolower($realName)] ?? null;

                if ($enrolledId && $enrolledId !== $pid) {
                    // Redirect the SpeakerMapping to the HTTP-enrolled participant
                    // so talk time is attributed to the correct DB row.
                    SpeakerMapping::query()
                        ->where('meeting_id', $meetingId)
                        ->where('speaker_label', $label)
                        ->update(['participant_id' => $enrolledId]);

                    // Remove the orphaned WS placeholder.
                    $participant->delete();

                    $labelToParticipantId[$label] = $enrolledId;
                } else {
                    // No HTTP-enrolled counterpart — rename the WS placeholder in place.
                    $participant->update([
                        'name' => $realName,
                        'voice_embedding' => [
                            'provider' => 'deepgram',
                            'type' => 'intro_name_enrollment',
                            'speaker_label' => $label,
                            'enrolled_name' => $realName,
                            'enrolled_at' => now()->toISOString(),
                            'name_confidence' => $detection['count'],
                        ],
                    ]);

                    // Also register in the enrolled map so subsequent detections
                    // of the same name reuse this participant.
                    $this->enrolledParticipants[strtolower($realName)] = $pid;
                }
            }

            foreach ($this->speakerSeconds as $label => $sec) {
                $pid = $labelToParticipantId[$label] ?? null;
                if (!$pid) {
                    continue;
                }
                $talkTime = (int) round($sec);
                $talkPct = (float) round(($sec / $total) * 100, 2);

                $existing = ParticipantStat::query()
                    ->where('meeting_id', $meetingId)
                    ->where('participant_id', $pid)
                    ->first();

                ParticipantStat::updateOrCreate(
                    ['meeting_id' => $meetingId, 'participant_id' => $pid],
                    [
                        'talk_time' => $talkTime,
                        'talk_percentage' => $talkPct,
                        'interruptions' => (int) ($existing?->interruptions ?? 0),
                        'times_spoken' => (int) (($existing?->times_spoken ?? 0) + 1),
                    ]
                );
            }

            $nlp = $this->buildMeetingNlp();
            MeetingAnalytic::updateOrCreate(
                ['meeting_id' => $meetingId],
                [
                    'crosstalk_percentage' => $crosstalkPct,
                    'total_speakers' => count($this->speakerSeconds),
                    'keywords' => $nlp['keywords'],
                    'summary' => $nlp['summary'],
                    'action_items' => $nlp['action_items'],
                    'sentiment' => $nlp['sentiment'],
                ]
            );

        });
    }

    private function buildSnapshot(int $meetingId): ?array
    {
        $analytic = MeetingAnalytic::query()->where('meeting_id', $meetingId)->first();

        // Live WS mode should feel real-time. DB stats store integer seconds (by design),
        // which makes the UI jump/lag. Prefer the in-memory floating seconds when available.
        $liveSeconds = $this->speakerSeconds;

        // Deepgram diarization timings can arrive in bursts, which makes the UI feel delayed.
        // We know how much audio we've ingested in real time via $this->audioCursorSeconds, so
        // we "catch up" any small gap by attributing it to the most recently active label.
        $sum = 0.0;
        foreach ($liveSeconds as $sec) {
            $sum += max(0.0, (float) $sec);
        }
        $gap = max(0.0, (float) $this->audioCursorSeconds - $sum);
        if ($gap >= 0.15 && $gap <= 2.5 && count($liveSeconds) > 0) {
            $last = $this->recentSpeakerIntervals[count($this->recentSpeakerIntervals) - 1] ?? null;
            $lastLabel = is_array($last) ? (string) ($last['label'] ?? '') : '';
            if ($lastLabel === '' || !array_key_exists($lastLabel, $liveSeconds)) {
                $lastLabel = (string) array_key_first($liveSeconds);
            }
            $liveSeconds[$lastLabel] = ($liveSeconds[$lastLabel] ?? 0.0) + $gap;
        }

        $liveLabels = array_keys($liveSeconds);
        if (count($liveLabels) > 0) {
            $rows = SpeakerMapping::query()
                ->with('participant:id,name')
                ->where('meeting_id', $meetingId)
                ->whereIn('speaker_label', $liveLabels)
                ->get();

            $labelToName = [];
            $labelToPid = [];
            foreach ($rows as $m) {
                $label = (string) ($m->speaker_label ?? '');
                if ($label === '') {
                    continue;
                }
                $labelToPid[$label] = (int) ($m->participant_id ?? 0);
                $labelToName[$label] = (string) ($m->participant?->name ?? 'Unknown');
            }

            $total = 0.0;
            foreach ($liveSeconds as $sec) {
                $total += max(0.0, (float) $sec);
            }
            $total = max(0.001, $total);

            $stats = [];
            foreach ($liveSeconds as $label => $sec) {
                $sec = max(0.0, (float) $sec);
                $stats[] = [
                    'participant_id' => (int) ($labelToPid[$label] ?? 0),
                    'label' => (string) $label,
                    'name' => (string) ($labelToName[$label] ?? (string) $label),
                    // Keep the legacy fields for the UI, but also send high-res seconds.
                    'talk_time' => (int) floor($sec),
                    'talk_time_seconds' => $sec,
                    'talk_percentage' => (float) round(($sec / $total) * 100, 2),
                    'times_spoken' => 0,
                ];
            }
        } else {
            $stats = ParticipantStat::query()
                ->with('participant:id,name')
                ->where('meeting_id', $meetingId)
                ->get()
                ->map(fn (ParticipantStat $s) => [
                    'participant_id' => (int) $s->participant_id,
                    'name' => (string) ($s->participant?->name ?? 'Unknown'),
                    'talk_time' => (int) $s->talk_time,
                    'talk_percentage' => (float) $s->talk_percentage,
                    'times_spoken' => (int) $s->times_spoken,
                ])
                ->values()
                ->all();
        }

        return [
            'meeting_id' => $meetingId,
            'total_participants' => count($stats),
            'participants' => $stats,
            'crosstalk_percentage' => (float) ($analytic?->crosstalk_percentage ?? 0),
            'live_audio_seconds' => (float) $this->audioCursorSeconds,
            'voice_config' => [
                'format' => $this->audioFormat,
                'threshold' => (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75)),
                'early_boost' => (float) (env('MEETING_VOICEPRINT_EARLY_BOOST', 0.07)),
                'label_window_seconds' => (float) $this->labelWindowSeconds,
                'label_min_speech_seconds' => (float) $this->labelMinSpeechSeconds,
            ],
            'voice_matching' => $this->lastVoiceMatching,
            'updated_at' => $analytic?->updated_at?->toISOString(),
        ];
    }
}