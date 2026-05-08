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
use Amp\Process\Process;
use Amp\Websocket\WebsocketCloseCode;
use Revolt\EventLoop;
use Throwable;
use function Amp\async;
use function Amp\ByteStream\buffer;
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
                    $transcriptEnabled = true;
                    if (is_array($qs) && array_key_exists('transcript', $qs)) {
                        $parsed = filter_var($qs['transcript'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                        $transcriptEnabled = $parsed === null ? true : (bool) $parsed;
                    }
                    (new DeepgramLiveRelayConnection)->handle($client, $meetingId, $token, $format, $transcriptEnabled);
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
    private float $lastFrontendStatsAt = 0.0;
    private float $lastFrontendTranscriptAt = 0.0;
    private ?string $statsTimerId = null;
    private ?string $transcriptTimerId = null;
    private ?string $maintenanceTimerId = null;
    private ?string $persistTimerId = null;
    private ?string $voiceEmbedTimerId = null;
    private bool $transcriptEnabled = true;

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
     * Latest non-final (interim) transcript text per diarized label.
     * Shown to the client immediately; committed into cumulativeSpeakerText on finals.
     *
     * @var array<string,string>
     */
    private array $livePartialByLabel = [];

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

    /**
     * Rolling speaker embeddings per diarization label (last N embeds).
     * We average these before matching to stabilize identity for short / noisy segments.
     *
     * @var array<string,list<array<int,float>>>
     */
    private array $speakerVoiceprintHistory = [];
    /** @var array<string,float> */
    private array $labelLastEmbedAt = [];

    private float $labelWindowSeconds = 8.0;
    private float $labelMinSpeechSeconds = 2.5;
    private float $labelEmbedCooldownSeconds = 4.0;
    private float $labelMinPurity = 0.65;

    /** @var array<int,array{participant_id:int,vector:array<int,float>}> */
    private array $enrolledVoiceprints = [];

    /**
     * Real enrolled participants (non-placeholder) for this meeting.
     * Used by the no-diarization fallback to emit stable participant rows even when Deepgram collapses labels.
     *
     * @var array<int,string> participant_id => name
     */
    private array $enrolledParticipantNamesById = [];

    /**
     * Fallback attribution when diarization collapses to a single label:
     * accumulate talk seconds directly per participant_id via rolling voiceprint classification.
     *
     * @var array<int,float> participant_id => seconds
     */
    private array $fallbackParticipantSeconds = [];
    private float $fallbackLastAttributionCursor = 0.0;
    private float $fallbackLastEmbedAt = 0.0;
    /** @var array<int,float> participant_id => ema_posterior */
    private array $fallbackPosteriorEma = [];
    private int $fallbackStablePid = 0;
    private int $fallbackStableCount = 0;

    /** @var array<string,array<int,float>> label => [participant_id => ema_posterior] */
    private array $labelPosteriorEma = [];
    /** @var array<string,int> label => stable_participant_id */
    private array $labelStablePid = [];
    /** @var array<string,int> label => stable_count */
    private array $labelStableCount = [];

    /**
     * Latest per-label voice matching diagnostics (for frontend debug).
     *
     * @var array<string,array{evidence_count:int,best_participant_id:int|null,best_participant_name:string,best_score:float,threshold:float,matched:bool}>
     */
    private array $lastVoiceMatching = [];
    /**
     * Last rolling fallback match info (collapsed diarization mode).
     *
     * @var array<string,mixed>
     */
    private array $lastFallbackMatching = [];
    /** @var array<int,array<string,mixed>> */
    private array $lastSttWords = [];
    private float $lastSttWordsUpdatedAt = 0.0;
    private float $lastEnrolledReloadAt = 0.0;
    /** @var array<int,float> participant_id => last_self_enroll_at */
    private array $selfEnrollLastAtByPid = [];

    /**
     * Baselines for attributing "orphan" wall-clock audio (Deepgram lag) without
     * re-adding the full gap on every stats tick (which inflated talk time to 200%+).
     */
    private bool $talkClockBaselineReady = false;
    private float $lastTalkClockSpeechSum = 0.0;
    private float $lastTalkClockAudioCursor = 0.0;
    private float $lastImmediateTranscriptPushAt = 0.0;
    private float $lastImmediateStatsPushAt = 0.0;
    private float $lastSeenSegmentsPruneAt = 0.0;
    private bool $voiceEmbeddingBusy = false;
    private float $lastRealtimeAllocCursor = 0.0;
    private string $activeSpeakerLabel = '';
    private float $lastActiveSpeakerAt = 0.0;
    private string $lastTranscriptFingerprint = '';
    /** @var array<string,bool> */
    private array $autoBoundLabels = [];
    private float $lastSpeakerMapRefreshAt = 0.0;
    /** @var array<string,int> */
    private array $speakerLabelToParticipantIdCache = [];
    /** @var array<string,string> */
    private array $speakerLabelToNameCache = [];

    /**
     * Refresh speaker label -> participant/name caches.
     *
     * @param array<int,string> $labels
     */
    private function refreshSpeakerMappingCache(int $meetingId, array $labels, bool $force = false): void
    {
        if (count($labels) === 0) {
            return;
        }
        $now = microtime(true);
        if (!$force && ($now - $this->lastSpeakerMapRefreshAt) < 1.0) {
            return;
        }
        $rows = SpeakerMapping::query()
            ->with('participant:id,name')
            ->where('meeting_id', $meetingId)
            ->whereIn('speaker_label', $labels)
            ->get();

        foreach ($rows as $m) {
            $label = (string) ($m->speaker_label ?? '');
            if ($label === '') {
                continue;
            }
            $this->speakerLabelToParticipantIdCache[$label] = (int) ($m->participant_id ?? 0);
            $this->speakerLabelToNameCache[$label] = (string) ($m->participant?->name ?? $label);
        }
        $this->lastSpeakerMapRefreshAt = $now;
    }

    private function pushTranscriptUpdate(WebsocketClient $frontend, int $meetingId): void
    {
        if ($frontend->isClosed()) {
            return;
        }
        $lines = $this->buildTranscriptLines($meetingId);
        $lines = array_slice($lines, 0, 8);
        foreach ($lines as &$ln) {
            $ln['text'] = mb_substr((string) ($ln['text'] ?? ''), -350);
        }
        $fingerprintParts = [];
        foreach ($lines as $ln) {
            $fingerprintParts[] = (string) ($ln['label'] ?? '') . '|' . (string) ($ln['text'] ?? '');
        }
        $fingerprint = sha1(implode("\n", $fingerprintParts));
        if ($fingerprint === $this->lastTranscriptFingerprint) {
            return;
        }
        $this->lastTranscriptFingerprint = $fingerprint;
        $frontend->sendText(json_encode([
            'event' => 'transcript.updated',
            'data' => [
                'meeting_id' => $meetingId,
                'lines' => $lines,
            ],
        ]));
    }

    /**
     * Build transcript lines with participant display names.
     *
     * @return array<int,array{label:string,name:string,text:string,participant_id:int|null,confidence:float|null}>
     */
    private function buildTranscriptLines(int $meetingId): array
    {
        $labels = array_values(array_unique(array_merge(
            array_keys($this->cumulativeSpeakerText),
            array_keys($this->livePartialByLabel),
        )));
        if (count($labels) === 0) {
            return [];
        }

        $this->refreshSpeakerMappingCache($meetingId, array_map(fn ($x) => (string) $x, $labels));

        $lines = [];
        foreach ($labels as $label) {
            $label = (string) $label;
            $base = trim((string) ($this->cumulativeSpeakerText[$label] ?? ''));
            $partial = trim((string) ($this->livePartialByLabel[$label] ?? ''));
            $text = trim($base === '' ? $partial : ($partial === '' ? $base : ($base . ' ' . $partial)));
            if ($text === '') {
                continue;
            }

            // Highest priority: intro detected name (only active during intro).
            $resolvedPid = null;
            $confidence = null;
            $name = $this->speakerDetectedName[$label]['name'] ?? null;

            // Next: live voice matching decision (rolling ECAPA).
            $vm = $this->lastVoiceMatching[$label] ?? null;
            if (!$name && is_array($vm) && (bool) ($vm['matched'] ?? false)) {
                $resolvedPid = is_numeric($vm['best_participant_id'] ?? null) ? (int) $vm['best_participant_id'] : null;
                $confidence = is_numeric($vm['best_score'] ?? null) ? (float) $vm['best_score'] : null;
                $name = (string) ($vm['best_participant_name'] ?? '');
            }

            // If we attempted voice matching but it didn't pass threshold, do NOT force assignment.
            // In multi-participant meetings this should surface as unknown instead of a sticky wrong name.
            if (!$name && is_array($vm) && array_key_exists('best_score', $vm) && count($this->enrolledVoiceprints) >= 2) {
                $confidence = is_numeric($vm['best_score'] ?? null) ? (float) $vm['best_score'] : null;
                $resolvedPid = null;
                $name = 'Unknown Speaker';
            }

            // Fallback: SpeakerMapping cache (DB-driven). (Used mostly for single-speaker / placeholder flows.)
            if (!$name) {
                $name = (string) ($this->speakerLabelToNameCache[$label] ?? $label);
                $resolvedPid = isset($this->speakerLabelToParticipantIdCache[$label]) ? (int) $this->speakerLabelToParticipantIdCache[$label] : null;
            }

            if (!$name || trim($name) === '') {
                $name = 'Unknown Speaker';
            }

            $lines[] = [
                'label' => $label,
                'name' => $name,
                'text' => $text,
                'participant_id' => $resolvedPid,
                'confidence' => $confidence,
            ];
        }
        return $lines;
    }

    /**
     * @return array{keywords:array<int,string>,summary:string,action_items:array<int,string>,sentiment:array<string,mixed>}
     */
    private function buildMeetingNlp(int $meetingId): array
    {
        // If the meeting ends before provider finals, cumulativeSpeakerText can be empty
        // even though we saw interim transcripts. Include live partials so "Meeting insights"
        // never shows blank when we actually received speech.
        $labels = array_values(array_unique(array_merge(
            array_keys($this->cumulativeSpeakerText),
            array_keys($this->livePartialByLabel)
        )));
        $this->refreshSpeakerMappingCache($meetingId, array_map(fn ($x) => (string) $x, $labels), true);

        $parts = [];
        foreach ($labels as $label) {
            $label = (string) $label;
            $t = trim((string) ($this->cumulativeSpeakerText[$label] ?? ''));
            if ($t === '') {
                $t = trim((string) ($this->livePartialByLabel[$label] ?? ''));
            }
            if ($t === '') {
                continue;
            }
            $name = $this->speakerDetectedName[$label]['name']
                ?? (string) ($this->speakerLabelToNameCache[$label] ?? $label);
            $parts[] = '[' . $name . '] ' . $t;
        }

        $full = trim(implode("\n", $parts));

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
            $name = trim((string) $p->name);
            if ($name === '') {
                continue;
            }
            // Index ALL real enrolled participants so live "my name is X" can bind labels
            // even if voiceprint matching is weak on some devices.
            if ($this->isPlaceholderName($name)) {
                continue;
            }
            $this->enrolledParticipants[strtolower($name)] = (int) $p->id;
        }
    }

    /**
     * The relay loads enrolled voiceprints once on startup, but intro enrollment can happen
     * while the relay is already running. Periodically reload enrollment so newly-enrolled
     * participants become matchable without restarting the relay.
     */
    private function maybeReloadEnrolledState(int $meetingId): void
    {
        $now = microtime(true);
        $every = (float) env('MEETING_WS_ENROLLED_REFRESH_SECONDS', 2.0);
        $every = max(0.5, min(30.0, $every));

        // Always reload when we have <2 voiceprints (can't do multi-speaker matching),
        // otherwise reload on a slower cadence.
        $needs = count($this->enrolledVoiceprints) < 2;
        if (!$needs && $this->lastEnrolledReloadAt > 0.0 && ($now - $this->lastEnrolledReloadAt) < $every) {
            return;
        }
        if ($needs && $this->lastEnrolledReloadAt > 0.0 && ($now - $this->lastEnrolledReloadAt) < 0.5) {
            return;
        }

        $this->lastEnrolledReloadAt = $now;
        try {
            $this->loadEnrolledParticipants($meetingId);
            $this->loadEnrolledVoiceprints($meetingId);
            $this->loadEnrolledParticipantIndex($meetingId);
        } catch (Throwable) {
        }
    }

    /**
     * No-extra-steps robustness: when the transcript contains "my name is X" (and X is an enrolled participant),
     * capture the last ~2s of audio, compute an embedding, and attach it as an additional template for that participant.
     *
     * This helps when diarization collapses and one participant has a weak / corrupted template.
     */
    private function maybeSelfEnrollFromDetectedNames(int $meetingId): void
    {
        // Keep this behavior gated to debug mode so we can safely iterate.
        if (!$this->voiceDebug) {
            return;
        }
        if (count($this->speakerDetectedName) === 0) {
            return;
        }
        if (count($this->enrolledParticipants) === 0) {
            return;
        }

        $now = microtime(true);
        $cooldown = (float) env('MEETING_WS_SELF_ENROLL_COOLDOWN_SECONDS', 6.0);
        $cooldown = max(1.0, min(60.0, $cooldown));

        foreach ($this->speakerDetectedName as $label => $d) {
            $name = trim((string) ($d['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $pid = (int) ($this->enrolledParticipants[strtolower($name)] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $lastAt = (float) ($this->selfEnrollLastAtByPid[$pid] ?? 0.0);
            if ($lastAt > 0.0 && ($now - $lastAt) < $cooldown) {
                continue;
            }

            // Capture a short window ending "now" (audio cursor).
            $cur = max(0.0, (float) $this->audioCursorSeconds);
            if ($cur <= 0.25) {
                continue;
            }
            $win = (float) env('MEETING_WS_SELF_ENROLL_WINDOW_SECONDS', 2.0);
            $win = max(0.8, min(4.0, $win));
            $rangeEnd = $cur;
            $rangeStart = max(0.0, $rangeEnd - $win);

            // Single-flight with existing embed busy flag.
            if ($this->voiceEmbeddingBusy) {
                continue;
            }

            $this->selfEnrollLastAtByPid[$pid] = $now;
            $this->voiceEmbeddingBusy = true;

            async(function () use ($meetingId, $pid, $name, $label, $rangeStart, $rangeEnd): void {
                try {
                    $vec = $this->computeEmbeddingForRange($meetingId, $rangeStart, $rangeEnd);
                    if (!is_array($vec) || count($vec) < 32) {
                        Log::info('relay_self_enroll_voiceprint_empty', [
                            'meeting_id' => $meetingId,
                            'participant_id' => $pid,
                            'name' => $name,
                            'label' => (string) $label,
                            'range_start' => $rangeStart,
                            'range_end' => $rangeEnd,
                        ]);
                        return;
                    }

                    // Prevent corrupting enrollment: reject if too similar to another participant.
                    $dupThr = (float) env('MEETING_WS_SELF_ENROLL_DUPLICATE_SIM', 0.985);
                    $dupThr = max(0.85, min(0.999, $dupThr));

                    $others = MeetingParticipant::query()
                        ->where('meeting_id', $meetingId)
                        ->whereNotNull('voice_embedding')
                        ->get(['id', 'name', 'voice_embedding']);

                    foreach ($others as $op) {
                        if ((int) $op->id === (int) $pid) {
                            continue;
                        }
                        $ove = is_array($op->voice_embedding) ? $op->voice_embedding : [];
                        $ovp = $ove['voiceprint'] ?? null;
                        if (!is_array($ovp) || count($ovp) < 32) {
                            continue;
                        }
                        $sim = $this->cosineSimilarity($vec, array_map('floatval', $ovp));
                        if ($sim >= $dupThr) {
                            Log::warning('relay_self_enroll_duplicate_suspected', [
                                'meeting_id' => $meetingId,
                                'participant_id' => $pid,
                                'name' => $name,
                                'label' => (string) $label,
                                'similarity' => $sim,
                                'threshold' => $dupThr,
                                'conflicts_with_participant_id' => (int) $op->id,
                                'conflicts_with_name' => (string) ($op->name ?? ''),
                            ]);
                            return;
                        }
                    }

                    DB::transaction(function () use ($pid, $vec): void {
                        $p = MeetingParticipant::query()->whereKey($pid)->lockForUpdate()->first();
                        if (!$p) {
                            return;
                        }
                        $prev = is_array($p->voice_embedding) ? $p->voice_embedding : [];
                        $voiceprints = [];
                        $existingList = $prev['voiceprints'] ?? null;
                        if (is_array($existingList)) {
                            foreach ($existingList as $v) {
                                if (is_array($v) && count($v) >= 32) {
                                    $voiceprints[] = array_map('floatval', $v);
                                }
                            }
                        }
                        $existingVp = $prev['voiceprint'] ?? null;
                        if (is_array($existingVp) && count($existingVp) >= 32) {
                            $voiceprints[] = array_map('floatval', $existingVp);
                        }
                        $voiceprints[] = array_map('floatval', $vec);

                        $max = (int) env('MEETING_INTRO_MAX_VOICEPRINTS_PER_PERSON', 5);
                        $max = max(1, min(10, $max));
                        if (count($voiceprints) > $max) {
                            $voiceprints = array_slice($voiceprints, -1 * $max);
                        }

                        if (!is_array($prev['voiceprint'] ?? null) || count((array) ($prev['voiceprint'] ?? [])) < 32) {
                            $prev['voiceprint'] = $voiceprints[count($voiceprints) - 1];
                        }
                        $prev['voiceprint_dim'] = is_array($prev['voiceprint'] ?? null) ? count((array) $prev['voiceprint']) : 0;
                        $prev['voiceprints'] = $voiceprints;

                        $p->update(['voice_embedding' => $prev]);
                    });

                    Log::info('relay_self_enroll_voiceprint_ok', [
                        'meeting_id' => $meetingId,
                        'participant_id' => $pid,
                        'name' => $name,
                        'label' => (string) $label,
                        'range_start' => $rangeStart,
                        'range_end' => $rangeEnd,
                        'dim' => count($vec),
                    ]);
                } finally {
                    $this->voiceEmbeddingBusy = false;
                }
            });
            // Only self-enroll one participant per tick.
            break;
        }
    }

    private function loadEnrolledVoiceprints(int $meetingId): void
    {
        $participants = MeetingParticipant::query()
            ->where('meeting_id', $meetingId)
            ->whereNotNull('voice_embedding')
            ->get(['id', 'voice_embedding']);

        $expectedDim = (int) env('MEETING_VOICEPRINT_EXPECTED_DIM', 192);
        $expectedDim = max(32, min(2048, $expectedDim));

        // Build ONE averaged template per participant for fair matching.
        // Otherwise a participant with more templates can dominate scoring simply by having more draws.
        $byPid = [];
        foreach ($participants as $p) {
            $ve = is_array($p->voice_embedding) ? $p->voice_embedding : [];
            $engine = strtolower(trim((string) ($ve['voiceprint_engine'] ?? $ve['engine'] ?? '')));
            $candidates = [];
            if (is_array($ve['voiceprints'] ?? null)) {
                foreach ($ve['voiceprints'] as $v) {
                    if (is_array($v) && count($v) >= 32) {
                        $candidates[] = $v;
                    }
                }
            }
            if (is_array($ve['voiceprint'] ?? null) && count($ve['voiceprint']) >= 32) {
                $candidates[] = $ve['voiceprint'];
            }

            foreach ($candidates as $vec) {
                $floats = [];
                foreach ($vec as $v) {
                    if (is_numeric($v)) {
                        $floats[] = (float) $v;
                    }
                }
                $dim = count($floats);
                if ($dim < 32) {
                    continue;
                }
                // Only match against ECAPA voiceprints (prevents mixing old Resemblyzer templates).
                // If engine isn't stored (legacy rows), fall back to expected dimension check.
                if ($engine !== '' && $engine !== 'speechbrain_ecapa') {
                    continue;
                }
                if ($engine === '' && $expectedDim > 0 && $dim !== $expectedDim) {
                    continue;
                }
                $pid = (int) $p->id;
                if ($pid > 0) {
                    $byPid[$pid] = $byPid[$pid] ?? [];
                    $byPid[$pid][] = $floats;
                }
            }
        }

        $out = [];
        foreach ($byPid as $pid => $vectors) {
            if (!is_array($vectors) || count($vectors) === 0) {
                continue;
            }
            $n = count($vectors);
            $dim = count($vectors[0]);
            if ($dim < 32) {
                continue;
            }
            $sum = array_fill(0, $dim, 0.0);
            foreach ($vectors as $vec) {
                $d = min($dim, count($vec));
                for ($i = 0; $i < $d; $i++) {
                    $sum[$i] += (float) $vec[$i];
                }
            }
            $avg = [];
            for ($i = 0; $i < $dim; $i++) {
                $avg[$i] = $sum[$i] / max(1, $n);
            }
            // L2 normalize for cosine stability.
            $norm = 0.0;
            for ($i = 0; $i < $dim; $i++) {
                $norm += $avg[$i] * $avg[$i];
            }
            $norm = sqrt(max(1e-12, $norm));
            for ($i = 0; $i < $dim; $i++) {
                $avg[$i] = $avg[$i] / $norm;
            }
            $out[] = [
                'participant_id' => (int) $pid,
                'vector' => $avg,
                'template_count' => (int) $n,
            ];
        }
        $this->enrolledVoiceprints = $out;

        $counts = [];
        foreach ($out as $e) {
            $counts[(int) $e['participant_id']] = (int) ($e['template_count'] ?? 1);
        }
        $this->debugVoice('enrolled_voiceprints_loaded', [
            'meeting_id' => $meetingId,
            'participant_count' => count($counts),
            'template_counts' => $counts,
        ]);
    }

    private function loadEnrolledParticipantIndex(int $meetingId): void
    {
        $rows = MeetingParticipant::query()
            ->where('meeting_id', $meetingId)
            ->get(['id', 'name']);

        $out = [];
        foreach ($rows as $p) {
            $name = (string) ($p->name ?? '');
            if ($name === '' || $this->isPlaceholderName($name)) {
                continue;
            }
            $out[(int) $p->id] = $name;
        }
        $this->enrolledParticipantNamesById = $out;
    }

    private function isPlaceholderName(string $name): bool
    {
        return (bool) preg_match('/^(Speaker\s+\d+|speaker_\d+|chunk\d+_\S+)$/i', trim($name));
    }

    /**
     * Treat diarization as "collapsed" if we have <=1 concrete speaker label (excluding speaker_unknown).
     */
    private function concreteDiarizedLabelCount(): int
    {
        $n = 0;
        foreach (array_keys($this->speakerSeconds) as $label) {
            $label = (string) $label;
            if ($label === '' || $label === 'speaker_unknown') {
                continue;
            }
            $n++;
        }
        return $n;
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

        // Lightweight VAD so we can start voice attribution immediately, even before STT emits words/utterances.
        // Deepgram diarization can lag or collapse to one label in mono streams; this keeps "recent speech" hot.
        $vadThr = (float) env('MEETING_PCM_VAD_THRESHOLD', 900.0);
        $vadThr = max(50.0, min(8000.0, $vadThr));
        // Mobile PCM amplitudes can be much lower than browser recordings.
        // If we have enrolled voiceprints, cap the threshold so speech is detected promptly.
        if (count($this->enrolledVoiceprints) >= 1) {
            $vadThr = min($vadThr, 300.0);
        }
        $energy = $this->pcmAvgAbs16($bytes);
        if ($energy >= $vadThr) {
            $this->lastActiveSpeakerAt = microtime(true);
            if ($this->activeSpeakerLabel === '') {
                $this->activeSpeakerLabel = 'speaker_0';
            }
        }

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

        // Kick fallback attribution promptly on speech (otherwise we wait for the embed timer tick).
        if (!$this->voiceEmbeddingBusy) {
            try {
                $this->maybeAttributeWithoutDiarization($meetingId);
            } catch (Throwable) {
            }
        }

        return $chunk;
    }

    /**
     * Average absolute amplitude of PCM16LE mono chunk (quick VAD signal).
     */
    private function pcmAvgAbs16(string $bytes): float
    {
        $n = intdiv(strlen($bytes), 2);
        if ($n <= 0) {
            return 0.0;
        }
        $sum = 0.0;
        // Sample at most ~800 values for speed (stride).
        $stride = max(1, (int) floor($n / 800));
        $count = 0;
        for ($i = 0; $i < $n; $i += $stride) {
            $lo = ord($bytes[$i * 2]);
            $hi = ord($bytes[$i * 2 + 1]);
            $v = ($hi << 8) | $lo;
            if ($v >= 0x8000) {
                $v -= 0x10000;
            }
            $sum += abs((int) $v);
            $count++;
        }
        return $count > 0 ? ($sum / $count) : 0.0;
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

    private function deepgramBool(mixed $v, bool $default = false): bool
    {
        if ($v === null) {
            return $default;
        }
        if (is_bool($v)) {
            return $v;
        }
        if (is_int($v) || is_float($v)) {
            return ((float) $v) !== 0.0;
        }
        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return $default;
        }
        if (in_array($s, ['1', 'true', 'yes', 'y', 'on'], true)) {
            return true;
        }
        if (in_array($s, ['0', 'false', 'no', 'n', 'off'], true)) {
            return false;
        }
        return $default;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>|null
     */
    private function firstDeepgramAlternative(array $data): ?array
    {
        $alt =
            ($data['channel']['alternatives'][0] ?? null)
            ?? ($data['results']['channels'][0]['alternatives'][0] ?? null);
        return is_array($alt) ? $alt : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function channelLevelTranscript(array $data): string
    {
        $alt = $this->firstDeepgramAlternative($data);
        if (!$alt) {
            return '';
        }
        return trim((string) ($alt['transcript'] ?? ''));
    }

    /**
     * @param array<int,mixed>|null $words
     */
    private function firstSpeakerFromWords(?array $words): ?int
    {
        if (!is_array($words)) {
            return null;
        }
        foreach ($words as $w) {
            if (!is_array($w)) {
                continue;
            }
            if (array_key_exists('speaker', $w) && $w['speaker'] !== null && $w['speaker'] !== '') {
                return (int) $w['speaker'];
            }
        }
        return null;
    }

    private function guessSpeakerLabelFromRecentIntervals(): string
    {
        if (count($this->recentSpeakerIntervals) === 0) {
            return 'speaker_0';
        }
        $last = $this->recentSpeakerIntervals[count($this->recentSpeakerIntervals) - 1];
        $lab = (string) ($last['label'] ?? '');
        return $lab !== '' ? $lab : 'speaker_0';
    }

    /**
     * Deepgram often sends interim results as channel.alternatives[0].transcript with an empty words array.
     * Those messages were previously dropped entirely, which makes the UI look "stuck then burst".
     *
     * @param array<string,mixed> $data
     * @param array<int,mixed>|null $words
     */
    private function applyChannelTranscriptFallback(
        array $data,
        ?array $words,
        bool $hasUtterances,
        float $now,
        array &$speakerText
    ): void {
        if ($hasUtterances) {
            return;
        }

        $t = $this->channelLevelTranscript($data);
        if ($t === '') {
            return;
        }

        // If we already have token-level words, let that path own the text to avoid double-counting.
        if (is_array($words) && count($words) > 0) {
            return;
        }

        $isFinal = $this->deepgramBool($data['is_final'] ?? null, false)
            || $this->deepgramBool($data['speech_final'] ?? null, false);

        $sp = $this->firstSpeakerFromWords($words);
        $label = $sp === null ? $this->guessSpeakerLabelFromRecentIntervals() : ('speaker_' . $sp);

        if (!$isFinal) {
            $this->livePartialByLabel[$label] = $t;
            return;
        }

        // Final for this endpoint: commit partial + this final chunk, then clear partial.
        $partial = trim((string) ($this->livePartialByLabel[$label] ?? ''));
        unset($this->livePartialByLabel[$label]);

        $chunk = trim($partial === '' ? $t : ($partial . ' ' . $t));
        if ($chunk === '') {
            return;
        }

        // Finals should still be de-duped, but interim refresh should not be keyed by md5(text) alone.
        $segKey = $label . '|channel_final|' . md5($chunk);
        if (isset($this->seenTranscriptSegments[$segKey])) {
            return;
        }
        $this->seenTranscriptSegments[$segKey] = $now;
        $speakerText[$label] = ($speakerText[$label] ?? '') . ' ' . $chunk;
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
        try {
            [$exit, $out] = $this->runSubprocess([
                'ffprobe',
                '-v', 'error',
                '-show_entries', 'format=duration',
                '-of', 'default=noprint_wrappers=1:nokey=1',
                $absolutePath,
            ]);
            if ($exit !== 0) {
                return 0.0;
            }
            $out = trim($out);
            $v = is_numeric($out) ? (float) $out : 0.0;

            return max(0.0, $v);
        } catch (Throwable) {
            return 0.0;
        }
    }

    /**
     * @return array<int,float>|null
     */
    private function computeEmbedding(string $absolutePath): ?array
    {
        $python = (string) config('meeting_analytics.analyzer.python', 'python3');
        $script = base_path('scripts/embed_audio.py');
        $timeout = (int) config('meeting_analytics.analyzer.timeout_seconds', 60);
        $maxSeconds = (float) env('MEETING_WS_EMBED_MAX_SECONDS', 6.0);
        $maxSeconds = max(1.0, min(60.0, $maxSeconds));

        try {
            [$exit, $stdout, $stderr] = $this->runSubprocess([
                $python,
                $script,
                '--file',
                $absolutePath,
                '--timeout',
                (string) $timeout,
                '--max-seconds',
                (string) $maxSeconds,
            ]);
        } catch (Throwable) {
            return null;
        }

        if ($exit !== 0) {
            // Keep diagnostics in logs while ensuring JSON parsing stays strict.
            $decoded = json_decode($stdout, true);
            $this->debugVoice('embed_subprocess_failed', [
                'exit' => $exit,
                'stdout' => trim($stdout),
                'stderr' => trim((string) $stderr),
                'error' => is_array($decoded) ? ($decoded['error'] ?? null) : null,
            ]);
            return null;
        }
        $decoded = json_decode($stdout, true);
        if (!is_array($decoded) || !is_array($decoded['embedding'] ?? null)) {
            $this->debugVoice('embed_invalid_json', [
                'stdout' => trim($stdout),
                'stderr' => trim((string) $stderr),
                'decoded_error' => is_array($decoded) ? ($decoded['error'] ?? null) : null,
            ]);
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
     * Environment for child processes (PATH must include ffmpeg/ffprobe/python).
     *
     * @return array<string, string>
     */
    private function buildProcessEnvironment(): array
    {
        $env = [];
        foreach (array_merge($_SERVER, $_ENV) as $k => $v) {
            if (!is_string($k) || $k === '') {
                continue;
            }
            if (is_string($v) || is_int($v) || is_float($v)) {
                $env[$k] = (string) $v;
            }
        }
        $env['PATH'] = $this->buildPath();

        return $env;
    }

    /**
     * Run a subprocess without blocking the WebSocket event loop (Amp + Revolt).
     *
     * @param list<string> $command
     * @return array{0:int,1:string,2:string} exit code, stdout, stderr
     */
    private function runSubprocess(array $command): array
    {
        $cwd = getcwd();
        $cwd = $cwd !== false ? $cwd : null;

        $proc = Process::start($command, $cwd, $this->buildProcessEnvironment());

        $stdoutFut = async(function () use ($proc): string {
            try {
                return buffer($proc->getStdout());
            } catch (Throwable) {
                return '';
            }
        });
        $stderrFut = async(function () use ($proc): string {
            try {
                return buffer($proc->getStderr());
            } catch (Throwable) {
                return '';
            }
        });

        // IMPORTANT: keep the WS loop responsive.
        // When voice matching is active (2+ participants), we spawn ffmpeg/python often.
        // If a subprocess hangs, it must not stall the event loop.
        $timeoutSeconds = (float) env('MEETING_WS_SUBPROCESS_TIMEOUT_SECONDS', 8.0);
        $timeoutSeconds = max(1.0, min(60.0, $timeoutSeconds));

        // Wait for join() or timeout without blocking the loop.
        $suspension = EventLoop::getSuspension();
        $done = false;
        $resumed = false;
        $exitCode = 124; // timeout default
        $resumeOnce = static function (bool $value) use (&$resumed, $suspension): void {
            if ($resumed) {
                return;
            }
            $resumed = true;
            try {
                $suspension->resume($value);
            } catch (Throwable) {
            }
        };

        $timerId = EventLoop::delay($timeoutSeconds, static function () use (&$done, $resumeOnce): void {
            if ($done) {
                return;
            }
            $resumeOnce(false);
        });

        async(function () use ($proc, &$done, &$exitCode, $resumeOnce, $timerId): void {
            try {
                $join = $proc->join();
                // amphp/process v2: join() returns Future<int>.
                if ($join instanceof \Amp\Future) {
                    $exitCode = (int) $join->await();
                } else {
                    $exitCode = (int) $join;
                }
            } catch (Throwable) {
                $exitCode = 125;
            } finally {
                $done = true;
                try { EventLoop::cancel($timerId); } catch (Throwable) {}
                $resumeOnce(true);
            }
        });

        $ok = (bool) $suspension->suspend();
        if (!$ok) {
            // Timeout hit.
            try { $proc->signal(\SIGKILL); } catch (Throwable) {}
            $exitCode = 124;
        }

        return [$exitCode, $stdoutFut->await(), $stderrFut->await()];
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
        // Lower default improves "instant" recognition on mobile.
        $minEmbedSeconds = (float) env('MEETING_WS_MIN_EMBED_SECONDS', 0.45);
        $minEmbedSeconds = max(0.25, min(2.0, $minEmbedSeconds));
        if ($dur < $minEmbedSeconds) {
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

                    [$ffExit, $_ffOut, $ffErr] = $this->runSubprocess([
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
                    if ($ffExit !== 0) {
                        $this->debugVoice('ffmpeg_trim_failed', [
                            'meeting_id' => $meetingId,
                            'range_start' => $rangeStart,
                            'range_end' => $rangeEnd,
                            'dur' => $dur,
                            'exit' => $ffExit,
                            'stderr' => trim($ffErr),
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
        if ($this->voiceEmbeddingBusy) {
            return;
        }
        if (count($this->recentSpeakerIntervals) === 0 || count($this->audioChunks) === 0) {
            // Fallback for long-interim phases: infer active label from live partial text.
            if (count($this->audioChunks) === 0 || count($this->livePartialByLabel) === 0) {
                return;
            }
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

        // Fallback path: when intervals are sparse, use active live labels + recent audio window.
        if (count($speechByLabel) === 0 && count($this->livePartialByLabel) > 0) {
            $fallbackStart = max(0.0, $this->audioCursorSeconds - min($this->labelWindowSeconds, 4.0));
            $fallbackEnd = max($fallbackStart + 0.8, $this->audioCursorSeconds);
            foreach ($this->livePartialByLabel as $label => $txt) {
                if (trim((string) $txt) === '') {
                    continue;
                }
                $label = (string) $label;
                $speechByLabel[$label] = max($this->labelMinSpeechSeconds, $fallbackEnd - $fallbackStart);
                $minStartByLabel[$label] = $fallbackStart;
                $maxEndByLabel[$label] = $fallbackEnd;
            }
        }

        // Single-flight: we only spawn one embedding subprocess at a time,
        // but we should not starve "new" labels (e.g. speaker_1) behind a dominant label (speaker_0).
        // Prioritize labels that don't have a voiceprint yet, then by most speech.
        $labels = array_keys($speechByLabel);
        usort($labels, function (string $a, string $b) use ($speechByLabel): int {
            $ha = is_array($this->speakerVoiceprintHistory[$a] ?? null) ? count($this->speakerVoiceprintHistory[$a]) : 0;
            $hb = is_array($this->speakerVoiceprintHistory[$b] ?? null) ? count($this->speakerVoiceprintHistory[$b]) : 0;
            $na = $ha <= 0 ? 1 : 0;
            $nb = $hb <= 0 ? 1 : 0;
            if ($na !== $nb) {
                // "new" labels first
                return $nb <=> $na;
            }
            $sa = (float) ($speechByLabel[$a] ?? 0.0);
            $sb = (float) ($speechByLabel[$b] ?? 0.0);
            return $sb <=> $sa;
        });

        $now = microtime(true);
        $bootstrapMinSpeech = (float) env('MEETING_WS_LABEL_BOOTSTRAP_MIN_SPEECH_SECONDS', 0.25);
        $bootstrapMinSpeech = max(0.12, min(1.0, $bootstrapMinSpeech));

        foreach ($labels as $label) {
            $speechSeconds = (float) ($speechByLabel[$label] ?? 0.0);
            $histCount = is_array($this->speakerVoiceprintHistory[(string) $label] ?? null)
                ? count($this->speakerVoiceprintHistory[(string) $label])
                : 0;
            $effectiveMinSpeech = $histCount <= 0
                ? min($this->labelMinSpeechSeconds, $bootstrapMinSpeech)
                : $this->labelMinSpeechSeconds;

            if ($speechSeconds < $effectiveMinSpeech) {
                $this->debugVoice('label_insufficient_speech', [
                    'meeting_id' => $meetingId,
                    'label' => $label,
                    'speech_seconds' => (float) $speechSeconds,
                    'min_speech_seconds' => $effectiveMinSpeech,
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

            $this->voiceEmbeddingBusy = true;
            // Fiber + Amp\Process so ffmpeg/python do not freeze WS I/O (Symfony Process::run blocked the loop).
            async(function () use ($meetingId, $label, $rangeStart, $rangeEnd): void {
                try {
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
                    $histCount = is_array($this->speakerVoiceprintHistory[(string) $label] ?? null)
                        ? count($this->speakerVoiceprintHistory[(string) $label])
                        : 0;
                    $this->debugVoice('label_embed_accumulated', [
                        'meeting_id' => $meetingId,
                        'label' => $label,
                        'count' => (int) $histCount,
                    ]);
                } finally {
                    $this->voiceEmbeddingBusy = false;
                }
            });
            // Single-flight: one embed job per timer tick.
            break;
        }
    }

    /**
     * When live diarization collapses multiple people into a single speaker label (common with mono streams),
     * attribute talk-time directly to enrolled participants using a rolling voiceprint classification window.
     *
     * This scales to 5+ participants: each window embedding is matched against all enrolled voiceprints.
     */
    private function maybeAttributeWithoutDiarization(int $meetingId): void
    {
        $this->maybeReloadEnrolledState($meetingId);
        if (count($this->enrolledVoiceprints) < 2) {
            return;
        }
        if ($this->concreteDiarizedLabelCount() > 1) {
            return;
        }

        $now = microtime(true);
        // Cooldown: avoid spawning embedding processes too frequently.
        // For multi-participant meetings, keep it fast so switching speakers is detected quickly.
        $cooldown = count($this->enrolledVoiceprints) >= 2 ? 0.20 : 0.55;
        if ($this->fallbackLastEmbedAt > 0.0 && ($now - $this->fallbackLastEmbedAt) < $cooldown) {
            return;
        }
        $recentSpeech = $this->lastActiveSpeakerAt > 0.0 && ($now - $this->lastActiveSpeakerAt) <= 0.8;
        $cur = max(0.0, (float) $this->audioCursorSeconds);
        if (!$recentSpeech) {
            $this->fallbackLastAttributionCursor = max($this->fallbackLastAttributionCursor, $cur);
            return;
        }

        if ($this->fallbackLastAttributionCursor <= 0.0) {
            $this->fallbackLastAttributionCursor = $cur;
            return;
        }

        $delta = $cur - $this->fallbackLastAttributionCursor;
        if ($delta <= 0.02) {
            return;
        }
        if ($delta > 1.25) {
            // Don't backfill large gaps (reconnect / pause).
            $this->fallbackLastAttributionCursor = $cur;
            return;
        }

        $rangeEnd = $cur;
        // Use a shorter window for "current speaker" classification.
        // If the window is too long, it contains both speakers and keeps matching the dominant one.
        // Rolling window classification (seconds). 1.0s is a good balance:
        // enough phonetic content to be stable, short enough to switch quickly.
        $fallbackWindow = (float) env('MEETING_WS_FALLBACK_WINDOW_SECONDS', 1.0);
        $fallbackWindow = max(0.35, min(4.0, $fallbackWindow));
        $window = min($this->labelWindowSeconds, $fallbackWindow);
        $rangeStart = max(0.0, $rangeEnd - $window);

        $this->fallbackLastEmbedAt = $now;
        $this->voiceEmbeddingBusy = true;
        async(function () use ($meetingId, $rangeStart, $rangeEnd, $delta): void {
            try {
                $vec = $this->computeEmbeddingForRange($meetingId, $rangeStart, $rangeEnd);
                if (!is_array($vec) || count($vec) === 0) {
                    $this->debugVoice('fallback_embed_empty', [
                        'meeting_id' => $meetingId,
                        'range_start' => $rangeStart,
                        'range_end' => $rangeEnd,
                    ]);
                    return;
                }

                // Stabilize fallback classification using rolling-average embeddings (reduces ties).
                $this->accumulateVoiceprint('__fallback__', $vec);
                $avg = $this->getVoiceprintForLabel('__fallback__');
                $useVec = is_array($avg) && is_array($avg['vec'] ?? null) ? (array) $avg['vec'] : $vec;

                $scored = $this->scoreAllParticipants($useVec);
                $scores = (array) ($scored['scores'] ?? []);
                $posterior = (array) ($scored['posterior'] ?? []);
                $sum = $this->posteriorSummary($posterior);

                // Temporal smoothing: EMA over posteriors to reduce frame-level ties/noise.
                $alpha = (float) env('MEETING_VOICEPRINT_POSTERIOR_EMA_ALPHA', 0.35);
                $alpha = max(0.05, min(0.95, $alpha));
                $ema = $this->fallbackPosteriorEma;
                foreach ($posterior as $pid => $p) {
                    $pid = (int) $pid;
                    $prev = (float) ($ema[$pid] ?? 0.0);
                    $ema[$pid] = ($alpha * (float) $p) + ((1.0 - $alpha) * $prev);
                }
                // Light decay for participants not present in this frame.
                foreach ($ema as $pid => $p) {
                    if (!array_key_exists((int) $pid, $posterior)) {
                        $ema[(int) $pid] = (float) ($p * (1.0 - ($alpha * 0.20)));
                    }
                }
                arsort($ema);
                $emaTopPid = count($ema) ? (int) array_key_first($ema) : 0;
                $emaVals = array_values($ema);
                $emaTopP = (float) ($emaVals[0] ?? 0.0);
                $emaSecondP = (float) ($emaVals[1] ?? 0.0);
                $this->fallbackPosteriorEma = $ema;

                // Stability gate: require sustained evidence before switching attribution.
                $minAssignP = (float) env('MEETING_VOICEPRINT_FALLBACK_MIN_POSTERIOR', 0.52);
                $minAssignP = max(0.25, min(0.95, $minAssignP));
                $switchGap = (float) env('MEETING_VOICEPRINT_FALLBACK_MIN_POSTERIOR_GAP', 0.03);
                $switchGap = max(0.0, min(0.5, $switchGap));
                $stableWindows = (int) env('MEETING_VOICEPRINT_FALLBACK_STABLE_WINDOWS', 2);
                $stableWindows = max(1, min(10, $stableWindows));

                if ($emaTopPid !== $this->fallbackStablePid) {
                    // Only allow switch if the new winner is meaningfully ahead.
                    if ($emaTopPid > 0 && $emaTopP >= $minAssignP && (($emaTopP - $emaSecondP) >= $switchGap)) {
                        $this->fallbackStablePid = $emaTopPid;
                        $this->fallbackStableCount = 1;
                    } else {
                        // Don't switch; keep previous stable pid (if any).
                        $this->fallbackStableCount = 0;
                    }
                } else {
                    $this->fallbackStableCount++;
                }

                $pid = $this->fallbackStablePid > 0 && $this->fallbackStableCount >= $stableWindows
                    ? (int) $this->fallbackStablePid
                    : 0;

                $this->lastFallbackMatching = [
                    'best_participant_id' => $sum['pid'],
                    'best_score' => (float) (array_values($scores)[0] ?? -1.0),
                    'second_best_score' => (float) (array_values($scores)[1] ?? -1.0),
                    'top_scores' => array_slice($scores, 0, 6, true),
                    'top_posteriors' => array_slice($posterior, 0, 6, true),
                    'ema_top_pid' => $emaTopPid,
                    'ema_top_p' => $emaTopP,
                    'ema_second_p' => $emaSecondP,
                    'stable_pid' => $this->fallbackStablePid,
                    'stable_count' => $this->fallbackStableCount,
                    'min_p' => $minAssignP,
                    'min_gap' => $switchGap,
                    'stable_windows' => $stableWindows,
                    'entropy' => (float) $sum['entropy'],
                ];
                $this->debugVoice('fallback_score', $this->lastFallbackMatching);

                if ($pid <= 0) {
                    $this->debugVoice('fallback_no_match', [
                        'meeting_id' => $meetingId,
                        'range_start' => $rangeStart,
                        'range_end' => $rangeEnd,
                        '_fallback_last' => $this->lastFallbackMatching,
                    ]);
                    return;
                }

                $this->fallbackParticipantSeconds[$pid] = ($this->fallbackParticipantSeconds[$pid] ?? 0.0) + (float) $delta;
                $this->debugVoice('fallback_attributed', [
                    'meeting_id' => $meetingId,
                    'participant_id' => $pid,
                    'score' => (float) $emaTopP,
                    'delta' => (float) $delta,
                    'range_start' => $rangeStart,
                    'range_end' => $rangeEnd,
                ]);
            } finally {
                $this->voiceEmbeddingBusy = false;
            }
        });

        $this->fallbackLastAttributionCursor = $cur;
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
        $max = (int) env('MEETING_WS_ROLLING_EMBED_COUNT', 5);
        $max = max(1, min(10, $max));

        if (!isset($this->speakerVoiceprintHistory[$label])) {
            $this->speakerVoiceprintHistory[$label] = [];
        }
        $this->speakerVoiceprintHistory[$label][] = array_map('floatval', $vec);
        if (count($this->speakerVoiceprintHistory[$label]) > $max) {
            $this->speakerVoiceprintHistory[$label] = array_slice($this->speakerVoiceprintHistory[$label], -1 * $max);
        }
    }

    /**
     * @return array{vec:array<int,float>,count:int}|null
     */
    private function getVoiceprintForLabel(string $label): ?array
    {
        $hist = $this->speakerVoiceprintHistory[$label] ?? null;
        if (!is_array($hist) || count($hist) < 1) {
            return null;
        }

        // Average last N embeddings.
        $count = count($hist);
        $dim = count($hist[$count - 1] ?? []);
        if ($dim < 32) {
            return null;
        }
        $sum = array_fill(0, $dim, 0.0);
        foreach ($hist as $vec) {
            $n = min($dim, count($vec));
            for ($i = 0; $i < $n; $i++) {
                $sum[$i] += (float) $vec[$i];
            }
        }
        $avg = [];
        for ($i = 0; $i < $dim; $i++) {
            $avg[] = (float) $sum[$i] / max(1, $count);
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
     * @param array<int,float> $scores participant_id => cosine score
     * @return array<int,float> participant_id => probability (sums to ~1)
     */
    private function softmaxPosteriors(array $scores): array
    {
        if (count($scores) === 0) {
            return [];
        }
        // Temperature: lower = sharper (more confident), higher = flatter (more cautious).
        $temp = (float) env('MEETING_VOICEPRINT_SOFTMAX_TEMP', 0.07);
        $temp = max(0.01, min(1.0, $temp));

        $max = max($scores);
        $exps = [];
        $sum = 0.0;
        foreach ($scores as $pid => $s) {
            $x = ((float) $s - (float) $max) / $temp;
            // Avoid overflow in exp.
            $x = max(-60.0, min(60.0, $x));
            $e = exp($x);
            $exps[(int) $pid] = $e;
            $sum += $e;
        }
        $sum = max(1e-12, $sum);
        $out = [];
        foreach ($exps as $pid => $e) {
            $out[(int) $pid] = (float) ($e / $sum);
        }
        arsort($out);
        return $out;
    }

    /**
     * @param array<int,float> $posterior participant_id => probability
     * @return array{pid:int,p:float,second_p:float,entropy:float}
     */
    private function posteriorSummary(array $posterior): array
    {
        if (count($posterior) === 0) {
            return ['pid' => 0, 'p' => 0.0, 'second_p' => 0.0, 'entropy' => 0.0];
        }
        $pid = (int) array_key_first($posterior);
        $vals = array_values($posterior);
        $p1 = (float) ($vals[0] ?? 0.0);
        $p2 = (float) ($vals[1] ?? 0.0);
        $h = 0.0;
        foreach ($posterior as $p) {
            $pp = max(1e-12, (float) $p);
            $h += -1.0 * $pp * log($pp);
        }
        return ['pid' => $pid, 'p' => $p1, 'second_p' => $p2, 'entropy' => $h];
    }

    /**
     * @param array<int,float> $vec
     * @return array{scores:array<int,float>,posterior:array<int,float>}
     */
    private function scoreAllParticipants(array $vec): array
    {
        $bestByPid = [];
        foreach ($this->enrolledVoiceprints as $e) {
            $pid = (int) ($e['participant_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $vec2 = $e['vector'] ?? null;
            if (!is_array($vec2) || count($vec2) < 32) {
                continue;
            }
            $s = $this->cosineSimilarity($vec, $vec2);
            if (!isset($bestByPid[$pid]) || $s > (float) $bestByPid[$pid]) {
                $bestByPid[$pid] = (float) $s;
            }
        }
        arsort($bestByPid);
        $posterior = $this->softmaxPosteriors($bestByPid);
        return ['scores' => $bestByPid, 'posterior' => $posterior];
    }

    private function enrolledParticipantCount(): int
    {
        if (count($this->enrolledVoiceprints) === 0) {
            return 0;
        }
        $pids = [];
        foreach ($this->enrolledVoiceprints as $e) {
            $pid = (int) ($e['participant_id'] ?? 0);
            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }
        return count($pids);
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
        // In 2-participant meetings, margins are often very small on mobile/mono streams.
        // Reduce the required margin to avoid "never matched" behavior.
        if ($this->enrolledParticipantCount() === 2) {
            $minMargin = min($minMargin, (float) env('MEETING_VOICEPRINT_MARGIN_2P', 0.006));
        }
        // If we only have 1 chunk of evidence, require a stronger score to avoid early mislabels.
        if ($evidenceCount < 2) {
            $threshold += (float) (env('MEETING_VOICEPRINT_EARLY_BOOST', 0.07));
        }
        // Compute best score PER participant_id (participants can have multiple templates).
        $bestByPid = [];
        foreach ($this->enrolledVoiceprints as $e) {
            $pid = (int) $e['participant_id'];
            if ($pid <= 0) {
                continue;
            }
            $score = $this->cosineSimilarity($vec, $e['vector']);
            if (!isset($bestByPid[$pid]) || $score > (float) $bestByPid[$pid]) {
                $bestByPid[$pid] = (float) $score;
            }
        }
        arsort($bestByPid);
        $bestId = count($bestByPid) ? (int) array_key_first($bestByPid) : null;
        $bestScore = $bestId ? (float) ($bestByPid[$bestId] ?? -1.0) : -1.0;
        $vals = array_values($bestByPid);
        $secondBest = count($vals) >= 2 ? (float) $vals[1] : -1.0;
        if ($bestId === null || $bestScore < $threshold) {
            return null;
        }
        if (($bestScore - max(-1.0, $secondBest)) < $minMargin) {
            return null;
        }
        return [$bestId, $bestScore];
    }

    /**
     * Fallback matcher for mono streams where diarization collapses:
     * be more responsive early while still requiring a clear winner.
     */
    private function matchVoiceprintFallback(array $vec): ?array
    {
        if (count($this->enrolledVoiceprints) === 0) {
            return null;
        }

        $base = (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75));
        $threshold = (float) env('MEETING_VOICEPRINT_FALLBACK_THRESHOLD', max(0.40, $base - 0.12));
        // Allow lower fallback thresholds for mono/mobile where ECAPA cosine can be ~0.15–0.30.
        $threshold = max(0.15, min(0.95, $threshold));
        $minMargin = (float) env('MEETING_VOICEPRINT_FALLBACK_MARGIN', 0.015);
        if ($this->enrolledParticipantCount() === 2) {
            $minMargin = min($minMargin, (float) env('MEETING_VOICEPRINT_FALLBACK_MARGIN_2P', 0.006));
        }
        $minMargin = max(0.0, min(0.2, $minMargin));

        // Compute best score PER participant_id (participants can have multiple templates).
        $bestByPid = [];
        foreach ($this->enrolledVoiceprints as $e) {
            $pid = (int) $e['participant_id'];
            if ($pid <= 0) {
                continue;
            }
            $score = $this->cosineSimilarity($vec, $e['vector']);
            if (!isset($bestByPid[$pid]) || $score > (float) $bestByPid[$pid]) {
                $bestByPid[$pid] = (float) $score;
            }
        }
        arsort($bestByPid);
        $bestId = count($bestByPid) ? (int) array_key_first($bestByPid) : null;
        $bestScore = $bestId ? (float) ($bestByPid[$bestId] ?? -1.0) : -1.0;
        $vals = array_values($bestByPid);
        $secondBest = count($vals) >= 2 ? (float) $vals[1] : -1.0;

        // If we only have a weak winner, require a stronger margin to avoid
        // mis-attributing the 2nd speaker to the dominant speaker.
        $effectiveMinMargin = $bestScore < 0.50 ? max($minMargin, 0.03) : $minMargin;
        $this->lastFallbackMatching = [
            'best_participant_id' => $bestId,
            'best_score' => (float) $bestScore,
            'second_best_score' => (float) $secondBest,
            'threshold' => (float) $threshold,
            'min_margin' => (float) $effectiveMinMargin,
            'top_scores' => array_slice($bestByPid, 0, 6, true),
        ];
        $this->debugVoice('fallback_score', $this->lastFallbackMatching);

        if ($bestId === null || $bestScore < $threshold) {
            return null;
        }
        if (($bestScore - max(-1.0, $secondBest)) < $effectiveMinMargin) {
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

    public function handle(
        WebsocketClient $frontend,
        int $meetingId,
        string $sanctumToken,
        string $format = 'webm',
        bool $transcriptEnabled = true
    ): void
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

        // CRITICAL: Ensure this connection is scoped to ONLY this meeting.
        // The service object can live across multiple sessions in the same PHP process,
        // so we must reset all meeting-derived caches/state to avoid reusing voiceprints
        // from a previous meeting.
        $this->enrolledParticipants = [];
        $this->enrolledVoiceprints = [];
        $this->enrolledParticipantNamesById = [];
        $this->speakerVoiceprintHistory = [];
        $this->labelLastEmbedAt = [];
        $this->fallbackParticipantSeconds = [];
        $this->fallbackLastAttributionCursor = 0.0;
        $this->fallbackLastEmbedAt = 0.0;
        $this->lastVoiceMatching = [];
        $this->lastFallbackMatching = [];
        $this->autoBoundLabels = [];
        $this->selfEnrollLastAtByPid = [];
        $this->lastEnrolledReloadAt = 0.0;
        $this->speakerLabelToParticipantIdCache = [];
        $this->speakerLabelToNameCache = [];
        $this->lastSpeakerMapRefreshAt = 0.0;

        // Only allow "my name is X" based enrollment during the intro phase.
        // During the meeting we must rely on voiceprint matching only.
        $this->transcriptEnabled = $transcriptEnabled;
        $this->allowIntroEnrollment = (string) ($meeting->status ?? '') === 'pending';
        $this->audioFormat = in_array($format, ['webm', 'pcm16'], true) ? $format : 'webm';

        $sttProvider = strtolower(trim((string) env('MEETING_STT_PROVIDER', 'deepgram')));
        $pulseKey = trim((string) (config('services.pulse.api_key') ?: env('PULSE_API_KEY', '')));
        $deepgramKey = trim((string) (config('services.deepgram.api_key') ?: env('DEEPGRAM_API_KEY', '')));

        if ($sttProvider === 'pulse') {
            if ($pulseKey === '') {
                $frontend->sendText(json_encode([
                    'error' => 'pulse_key_missing',
                    'hint' => 'Set PULSE_API_KEY in .env (and MEETING_STT_PROVIDER=pulse).',
                ]));
                $frontend->close();
                return;
            }
            // Pulse realtime API expects raw PCM/opus per docs; our browser sends pcm16.
            if ($this->audioFormat !== 'pcm16') {
                $frontend->sendText(json_encode([
                    'error' => 'pulse_requires_pcm16',
                    'hint' => 'Use format=pcm16 on the live WebSocket URL (demo default).',
                ]));
                $frontend->close();
                return;
            }
        } else {
            if ($deepgramKey === '') {
                $frontend->sendText(json_encode(['error' => 'deepgram_key_missing']));
                $frontend->close();
                return;
            }
        }

        // Preload participants enrolled via HTTP intro so we can merge them
        // when the same name is detected in the live WS session.
        $this->loadEnrolledParticipants($meetingId);
        $this->loadEnrolledVoiceprints($meetingId);
        $this->loadEnrolledParticipantIndex($meetingId);
        $this->embedEveryN = max(1, (int) (env('MEETING_WS_EMBED_EVERY_N', 5)));
        $this->bootstrapChunks = max(1, (int) (env('MEETING_WS_BOOTSTRAP_CHUNKS', 8)));
        // Faster, more "instant" identity locking defaults (tunable via .env).
        // Keep these conservative enough to avoid flicker, but responsive for live UI.
        $this->labelWindowSeconds = (float) (env('MEETING_WS_LABEL_WINDOW_SECONDS', 2.0));          // evidence window
        $this->labelMinSpeechSeconds = (float) (env('MEETING_WS_LABEL_MIN_SPEECH_SECONDS', 1.0));   // minimum speech to embed
        $this->labelEmbedCooldownSeconds = (float) (env('MEETING_WS_LABEL_EMBED_COOLDOWN_SECONDS', 1.5));
        $this->labelMinPurity = (float) (env('MEETING_WS_LABEL_MIN_PURITY', 0.65));
        if (count($this->enrolledVoiceprints) >= 2) {
            // Multi-participant meetings: keep matching fast but require enough speech
            // to avoid "everyone scores the same" on tiny/noisy fragments.
            $this->labelWindowSeconds = max($this->labelWindowSeconds, 2.5);
            $this->labelMinSpeechSeconds = max($this->labelMinSpeechSeconds, 0.8);
            $this->labelMinPurity = min($this->labelMinPurity, 0.20);
            $this->labelEmbedCooldownSeconds = min($this->labelEmbedCooldownSeconds, 0.8);

            // Embed more frequently at the start so the first identity lock happens in ~1–2s.
            $this->embedEveryN = min($this->embedEveryN, 2);
            $this->bootstrapChunks = min($this->bootstrapChunks, 3);
        }
        if ($sttProvider === 'pulse') {
            // Pulse can produce long interim stretches before final boundaries.
            // Relax evidence requirements so voice matching still locks quickly.
            $this->labelMinSpeechSeconds = min($this->labelMinSpeechSeconds, 1.0);
            $this->labelMinPurity = min($this->labelMinPurity, 0.35);
            $this->labelEmbedCooldownSeconds = min($this->labelEmbedCooldownSeconds, 2.0);
        }
        $this->voiceDebug = (bool) (env('MEETING_VOICEPRINT_DEBUG', false));
        $this->maxAudioKeepSeconds = (float) (env('MEETING_WS_MAX_AUDIO_KEEP_SECONDS', 35));
        $this->maxTranscriptCharsPerLabel = (int) (env('MEETING_WS_MAX_TRANSCRIPT_CHARS', 6000));

        $upstream = $sttProvider === 'pulse'
            ? $this->connectPulse($pulseKey)
            : $this->connectDeepgram($deepgramKey);

        // Coalesce frontend updates on timers (prevents 1006 from browser overload).
        // User-facing "bar update" cadence is controlled here (default 100ms ≈ realtime feel).
        $statsIntervalSeconds = (float) (env('MEETING_WS_STATS_INTERVAL_SECONDS', 0.1));
        $statsIntervalSeconds = max(0.05, min(2.0, $statsIntervalSeconds)); // safety clamp
        $this->statsTimerId = EventLoop::repeat($statsIntervalSeconds, function () use ($frontend, $meetingId) {
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

        // Transcript updates timer: fallback path for UI smoothness.
        if ($this->transcriptEnabled) {
            $this->transcriptTimerId = EventLoop::repeat(0.03, function () use ($frontend, $meetingId) {
                if ($frontend->isClosed()) {
                    return;
                }
                try {
                    $this->pushTranscriptUpdate($frontend, $meetingId);
                } catch (Throwable $e) {
                    Log::warning('relay_frontend_send_failed', [
                        'meeting_id' => $meetingId,
                        'event' => 'transcript.updated',
                        'message' => $e->getMessage(),
                    ]);
                    try { $frontend->close(WebsocketCloseCode::INTERNAL_ERROR, 'frontend_send_failed'); } catch (Throwable) {}
                }
            });
        }

        // Keep expensive operations off the per-message receive loop.
        $this->maintenanceTimerId = EventLoop::repeat(0.35, function () {
            try {
                $this->assignChunksToSpeakers();
            } catch (Throwable) {
            }
        });

        // Voice embedding is CPU-heavy (ffmpeg + python). Run slower and single-flight
        // so matching works without degrading live transcript latency.
        $voiceEmbedIntervalSeconds = (float) (env('MEETING_WS_VOICE_EMBED_INTERVAL_SECONDS', 1.2));
        // Multi-participant meetings: run fallback classification ~3x/sec.
        if (count($this->enrolledVoiceprints) >= 2) {
            $voiceEmbedIntervalSeconds = min($voiceEmbedIntervalSeconds, 0.33);
        }
        $voiceEmbedIntervalSeconds = max(0.25, min(6.0, $voiceEmbedIntervalSeconds));
        $this->voiceEmbedTimerId = EventLoop::repeat($voiceEmbedIntervalSeconds, function () use ($meetingId) {
            if ($this->voiceEmbeddingBusy) {
                return;
            }
            try {
                $this->maybeAttributeWithoutDiarization($meetingId);
                $this->maybeComputeLabelEmbeddings($meetingId);
            } catch (Throwable) {
            }
        });

        // Persist less frequently and outside the receive loop to avoid backpressure.
        $this->persistTimerId = EventLoop::repeat(2.0, function () use ($meetingId) {
            try {
                $this->persistToDb($meetingId);
            } catch (Throwable) {
            }
        });

        // IMPORTANT: Do NOT auto-close the frontend WS from the relay.
        // The connection should remain open indefinitely and only close when:
        // - the user ends the meeting in the UI (client closes), or
        // - the client navigates away, or
        // - an internal error occurs.

        // Keep upstream STT (Deepgram or Pulse) connected in a background loop.
        // IMPORTANT: Never close the frontend WS when upstream disconnects.
        EventLoop::queue(function () use (&$upstream, $sttProvider, $deepgramKey, $pulseKey, $frontend, $meetingId) {
            $backoffMs = 500;
            while (!$frontend->isClosed()) {
                try {
                    while ($msg = $upstream->receive()) {
                        $text = $this->readClientMessage($msg);
                        if ($text === null) {
                            continue;
                        }
                        if ($sttProvider === 'pulse') {
                            $this->onPulseMessage($meetingId, $text);
                        } else {
                            $this->onDeepgramMessage($meetingId, $text);
                        }
                        $now = microtime(true);
                        // Default off: extra stats pushes compete with audio/STT; timer is enough for smooth bars.
                        $immediateStatsMin = (float) (env('MEETING_WS_STATS_IMMEDIATE_MIN_SECONDS', 0));
                        if ($immediateStatsMin > 0.0) {
                            $immediateStatsMin = max(0.03, min(0.5, $immediateStatsMin));
                        }
                        if ($immediateStatsMin > 0.0 && ($now - $this->lastImmediateStatsPushAt) >= $immediateStatsMin) {
                            try {
                                $snapshot = $this->buildSnapshot($meetingId);
                                if ($snapshot && !$frontend->isClosed()) {
                                    $frontend->sendText(json_encode(['event' => 'stats.updated', 'data' => $snapshot]));
                                }
                                $this->lastImmediateStatsPushAt = $now;
                            } catch (Throwable) {
                            }
                        }
                        if ($this->transcriptEnabled && ($now - $this->lastImmediateTranscriptPushAt) >= 0.015) {
                            try {
                                $this->pushTranscriptUpdate($frontend, $meetingId);
                                $this->lastImmediateTranscriptPushAt = $now;
                            } catch (Throwable) {
                            }
                        }
                    }
                } catch (Throwable $e) {
                    Log::warning('upstream_stt_receive_loop_failed', [
                        'meeting_id' => $meetingId,
                        'provider' => $sttProvider,
                        'message' => $e->getMessage(),
                    ]);
                    try {
                        if (!$frontend->isClosed()) {
                            $frontend->sendText(json_encode([
                                'event' => 'upstream_stt.disconnected',
                                'provider' => $sttProvider,
                                'message' => $e->getMessage(),
                            ]));
                        }
                    } catch (Throwable) {
                    }
                }

                try {
                    $upstream = $sttProvider === 'pulse'
                        ? $this->connectPulse($pulseKey)
                        : $this->connectDeepgram($deepgramKey);
                    $backoffMs = 500;
                    try {
                        if (!$frontend->isClosed()) {
                            $frontend->sendText(json_encode([
                                'event' => 'upstream_stt.reconnected',
                                'provider' => $sttProvider,
                            ]));
                        }
                    } catch (Throwable) {
                    }
                } catch (Throwable $e) {
                    Log::warning('upstream_stt_reconnect_failed', [
                        'meeting_id' => $meetingId,
                        'provider' => $sttProvider,
                        'message' => $e->getMessage(),
                    ]);
                    $backoffMs = min(8000, (int) ($backoffMs * 1.6));
                }

                // Never block the event loop with usleep() (causes cascading WS failures).
                try {
                    $sleep = EventLoop::getSuspension();
                    $timer = EventLoop::delay(max(0.01, (float) $backoffMs / 1000.0), static function () use ($sleep): void {
                        try { $sleep->resume(true); } catch (Throwable) {}
                    });
                    $sleep->suspend();
                    try { EventLoop::cancel($timer); } catch (Throwable) {}
                } catch (Throwable) {
                }
            }
        });

        try {
            while ($message = $frontend->receive()) {
                if ($message->isBinary()) {
                    $bytes = $message->buffer();
                    try {
                        $upstream->sendBinary($bytes);
                    } catch (Throwable $e) {
                        Log::warning('upstream_stt_send_failed', [
                            'meeting_id' => $meetingId,
                            'provider' => $sttProvider,
                            'message' => $e->getMessage(),
                        ]);
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
            try { if ($this->maintenanceTimerId) EventLoop::cancel($this->maintenanceTimerId); } catch (Throwable) {}
            try { if ($this->persistTimerId) EventLoop::cancel($this->persistTimerId); } catch (Throwable) {}
            try { if ($this->voiceEmbedTimerId) EventLoop::cancel($this->voiceEmbedTimerId); } catch (Throwable) {}
            try {
                $upstream->close();
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
        $endpointingMs = (int) (env('DEEPGRAM_LIVE_ENDPOINTING_MS', 120));
        $endpointingMs = max(50, min(2000, $endpointingMs));

        $params = [
            // Nova-3 tends to perform better for noisy / multi-speaker meeting audio.
            'model' => $model,
            'diarize' => 'true',
            'punctuate' => 'true',
            'smart_format' => 'true',
            'interim_results' => 'true',
            'utterances' => 'true',
            // Lower endpointing => more frequent partial/final cadence (less "stuck then dump").
            'endpointing' => (string) $endpointingMs,
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

    /**
     * Smallest AI Waves — Pulse realtime STT (PCM16 mono @ 16kHz).
     *
     * @see https://waves-docs.smallest.ai/v4.0.0/content/api-references/pulse-stt-ws
     */
    private function connectPulse(string $apiKey): ClientWebsocketConnection
    {
        $lang = (string) (config('services.pulse.language') ?: env('PULSE_LANGUAGE', 'en'));
        $params = [
            'language' => $lang,
            'encoding' => 'linear16',
            'sample_rate' => (string) $this->pcmSampleRate,
            'word_timestamps' => 'true',
            // Keep payload lean for lower-latency interim updates.
            'sentence_timestamps' => 'false',
            'diarize' => 'true',
            'full_transcript' => 'false',
            'numerals' => 'auto',
        ];
        $query = http_build_query($params);
        $handshake = new WebsocketHandshake("wss://api.smallest.ai/waves/v1/pulse/get_text?{$query}");
        $handshake = $handshake->withHeader('Authorization', 'Bearer ' . $apiKey);

        return connect($handshake);
    }

    /**
     * @param array<string,mixed> $p
     */
    private function pulseResponseToUnifiedSttJson(array $p): string
    {
        $isFinal = filter_var($p['is_final'] ?? false, FILTER_VALIDATE_BOOL);
        $unified = [
            'is_final' => $isFinal,
            'speech_final' => $isFinal,
        ];

        $transcript = trim((string) ($p['transcript'] ?? ''));
        if ($transcript !== '') {
            $unified['channel']['alternatives'][0]['transcript'] = $transcript;
        }

        $utterances = $p['utterances'] ?? null;
        if (is_array($utterances) && count($utterances) > 0) {
            $uOut = [];
            foreach ($utterances as $u) {
                if (!is_array($u)) {
                    continue;
                }
                $t = trim((string) ($u['text'] ?? $u['transcript'] ?? ''));
                $uOut[] = [
                    'speaker' => $u['speaker'] ?? null,
                    'start' => (float) ($u['start'] ?? 0),
                    'end' => (float) ($u['end'] ?? 0),
                    'transcript' => $t,
                ];
            }
            if (count($uOut) > 0) {
                $unified['utterances'] = $uOut;
            }
        }

        $words = $p['words'] ?? null;
        if (is_array($words) && count($words) > 0) {
            $wOut = [];
            foreach ($words as $w) {
                if (!is_array($w)) {
                    continue;
                }
                $txt = trim((string) ($w['word'] ?? ''));
                $wOut[] = [
                    'speaker' => $w['speaker'] ?? null,
                    'start' => (float) ($w['start'] ?? 0),
                    'end' => (float) ($w['end'] ?? 0),
                    'word' => $txt,
                    'punctuated_word' => $txt,
                ];
            }
            if (count($wOut) > 0) {
                $unified['channel']['alternatives'][0]['words'] = $wOut;
            }
        }

        return json_encode($unified);
    }

    private function onPulseMessage(int $meetingId, string $json): void
    {
        $p = json_decode($json, true);
        if (!is_array($p)) {
            return;
        }
        if (isset($p['error'])) {
            $err = $p['error'];
            if (is_string($err) && $err !== '') {
                Log::warning('pulse_stt_error', ['meeting_id' => $meetingId, 'error' => $err]);
            }

            return;
        }

        $hasWords = is_array($p['words'] ?? null) && count($p['words']) > 0;
        $hasUtterances = is_array($p['utterances'] ?? null) && count($p['utterances']) > 0;
        $hasTranscript = trim((string) ($p['transcript'] ?? '')) !== '';
        if (!$hasWords && !$hasUtterances && !$hasTranscript) {
            return;
        }

        $this->onDeepgramMessage($meetingId, $this->pulseResponseToUnifiedSttJson($p));
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
        $isFinalChunk = $this->deepgramBool($data['is_final'] ?? null, false)
            || $this->deepgramBool($data['speech_final'] ?? null, false);
        // prune dedupe cache (keep small, time-bounded)
        if (($now - $this->lastSeenSegmentsPruneAt) >= 2.0) {
            foreach ($this->seenTranscriptSegments as $k => $t) {
                if (($now - (float) $t) > 30.0) {
                    unset($this->seenTranscriptSegments[$k]);
                }
            }
            $this->lastSeenSegmentsPruneAt = $now;
        }

        $intervals = [];
        $speakerText = [];

        $utterances = $data['utterances']
            ?? ($data['channel']['alternatives'][0]['utterances'] ?? null)
            ?? ($data['results']['utterances'] ?? null);
        $hasUtterances = is_array($utterances) && count($utterances) > 0;

        if ($hasUtterances) {
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
                $t = (string) ($u['transcript'] ?? '');
                // Only treat as active speech for realtime PCM fill / reconcile when there is
                // actual text; empty diarization segments would otherwise pin "recent speech"
                // for the whole meeting and attribute silence as talk time.
                if ($t !== '') {
                    $t2 = trim($t);
                    $this->activeSpeakerLabel = (string) $label;
                    $this->lastActiveSpeakerAt = (float) $now;
                    if (!$isFinalChunk) {
                        // Keep interim text live, but skip heavy numeric aggregation until final.
                        $this->livePartialByLabel[$label] = $t2;
                    } else {
                        $dur = max(0.0, $end - $start);
                        $this->speakerSeconds[$label] = ($this->speakerSeconds[$label] ?? 0.0) + $dur;
                        $intervals[] = [$start, $end];
                        $this->totalSeconds = max($this->totalSeconds, $end);
                        $this->recentSpeakerIntervals[] = ['label' => $label, 'start' => $start, 'end' => $end];

                        $segKey = $label . '|' . number_format($start, 2, '.', '') . '|' . number_format($end, 2, '.', '') . '|' . md5($t2);
                        if (!isset($this->seenTranscriptSegments[$segKey])) {
                            $this->seenTranscriptSegments[$segKey] = $now;
                            $speakerText[$label] = ($speakerText[$label] ?? '') . ' ' . $t2;
                        }
                    }

                    $this->pushSttDebugWord([
                        'type' => 'utterance',
                        'label' => (string) $label,
                        'speaker' => $speaker,
                        'start' => $start,
                        'end' => $end,
                        'final' => (bool) $isFinalChunk,
                        'text' => $t2,
                    ]);
                }
            }
        } else {
            $words =
                $data['channel']['alternatives'][0]['words']
                ?? ($data['channel']['alternatives'][0]['word_timestamps'] ?? null)
                ?? ($data['results']['channels'][0]['alternatives'][0]['words'] ?? null);

            $channelTranscript = $this->channelLevelTranscript($data);
            $preferChannelText = $channelTranscript !== '';

            if (!is_array($words)) {
                $words = null;
            }

            // Channel-level transcript can arrive with words=[] during interim streaming.
            $this->applyChannelTranscriptFallback($data, $words, $hasUtterances, $now, $speakerText);

            if (!is_array($words) || count($words) === 0) {
                // Nothing else to attribute at word granularity.
                if (count($intervals) === 0 && count($speakerText) === 0) {
                    // Still might have refreshed livePartialByLabel for interim UI.
                    $hasAnyPartial = false;
                    foreach ($this->livePartialByLabel as $p) {
                        if (trim((string) $p) !== '') {
                            $hasAnyPartial = true;
                            break;
                        }
                    }
                    if (!$hasAnyPartial) {
                        return;
                    }
                }
            } else {
                // Word-level path owns the transcript; drop any channel interim buffer.
                foreach ($words as $w) {
                    if (!is_array($w)) {
                        continue;
                    }
                    if (!array_key_exists('speaker', $w) || $w['speaker'] === null || $w['speaker'] === '') {
                        continue;
                    }
                    $lab = 'speaker_' . (int) $w['speaker'];
                    unset($this->livePartialByLabel[$lab]);
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
                    $wordTxt = trim((string) ($w['punctuated_word'] ?? ($w['word'] ?? '')));
                    if ($wordTxt !== '') {
                        $this->pushSttDebugWord([
                            'type' => 'word',
                            'label' => (string) $label,
                            'speaker' => $speaker,
                            'start' => $start,
                            'end' => $end,
                            'final' => (bool) $isFinalChunk,
                            'word' => $wordTxt,
                        ]);
                    }
                    if ($isFinalChunk) {
                        $dur = max(0.0, $end - $start);
                        $this->speakerSeconds[$label] = ($this->speakerSeconds[$label] ?? 0.0) + $dur;
                        $intervals[] = [$start, $end];
                        $this->totalSeconds = max($this->totalSeconds, $end);
                        $this->recentSpeakerIntervals[] = ['label' => $label, 'start' => $start, 'end' => $end];
                    }

                    // Word-level interim: only refresh "recent speech" when a real token arrived,
                    // otherwise silence frames keep lastActiveSpeakerAt hot and realtime fill runs away.
                    if (!$isFinalChunk) {
                        $this->activeSpeakerLabel = (string) $label;
                        $wordForActivity = $wordTxt;
                        if ($wordForActivity !== '') {
                            $this->lastActiveSpeakerAt = (float) $now;
                        }
                    }

                    if ($isFinalChunk && !$preferChannelText) {
                        $word = (string) $wordTxt;
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

                // If channel transcript exists, treat it as the text source and avoid
                // appending both transcript + words (which causes repeats).
                if ($preferChannelText) {
                    $labelForTranscript = null;
                    foreach ($words as $w) {
                        if (!is_array($w)) {
                            continue;
                        }
                        if (!array_key_exists('speaker', $w) || $w['speaker'] === null || $w['speaker'] === '') {
                            continue;
                        }
                        $labelForTranscript = 'speaker_' . (int) $w['speaker'];
                        break;
                    }
                    if ($labelForTranscript === null) {
                        $labelForTranscript = $this->guessSpeakerLabelFromRecentIntervals();
                    }

                    if (!$isFinalChunk) {
                        $this->livePartialByLabel[$labelForTranscript] = $channelTranscript;
                    } else {
                        $partial = trim((string) ($this->livePartialByLabel[$labelForTranscript] ?? ''));
                        unset($this->livePartialByLabel[$labelForTranscript]);
                        $chunk = trim($partial === '' ? $channelTranscript : ($partial . ' ' . $channelTranscript));
                        if ($chunk !== '') {
                            $segKey = $labelForTranscript . '|channel_words_final|' . md5($chunk);
                            if (!isset($this->seenTranscriptSegments[$segKey])) {
                                $this->seenTranscriptSegments[$segKey] = $now;
                                $speakerText[$labelForTranscript] = ($speakerText[$labelForTranscript] ?? '') . ' ' . $chunk;
                            }
                        }
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

        // FIX #6: Accumulate text per label across all Deepgram messages in this session.
        foreach ($speakerText as $label => $text) {
            $this->appendSpeakerText((string) $label, (string) $text);
        }

        // Name binding: allow "my name is X" to bind a live label to an already-enrolled participant.
        // This is critical when diarization collapses to speaker_0 and voiceprint scores are weak.
        // First detected name per label wins (no overwrites).
        if ($this->allowIntroEnrollment || (bool) env('MEETING_WS_ALLOW_NAME_BINDING', false)) {
            foreach ($this->cumulativeSpeakerText as $label => $fullText) {
                $name = $this->extractNameFromText($fullText);
                if ($name === null) {
                    continue;
                }
                // Only accept names that match an enrolled participant.
                $enrolledId = $this->enrolledParticipants[strtolower($name)] ?? null;
                if (!$enrolledId) {
                    continue;
                }

                $existing = $this->speakerDetectedName[$label] ?? null;

                if ($existing === null) {
                    // First detection for this label.
                    $this->speakerDetectedName[$label] = ['name' => $name, 'count' => 1];
                } elseif (strtolower($existing['name']) === strtolower($name)) {
                    // Same name detected again — increase confidence.
                    $this->speakerDetectedName[$label]['count']++;
                } else {
                    // If Deepgram collapses multiple people into the same label (common in mono live streams),
                    // don't let the later "my name is X" overwrite the earlier mapping.
                    // Keeping the first stable prevents wrong attribution (Greg becoming David).
                }
            }
        }

        // Even when name binding is disabled for mapping, we can still use "my name is X" as a signal
        // to self-enroll an additional voiceprint template for X (debug mode only).
        $this->maybeSelfEnrollFromDetectedNames($meetingId);

        // Persist is handled by a dedicated timer to avoid blocking receive loop.
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
        $this->maybeReloadEnrolledState($meetingId);
        // Keep persisted stats in sync with the live UI behavior.
        // Without this, meetings that end before STT finals land can show 0s talk-time.
        $this->applyRealtimeActiveSpeakerEstimate();
        $this->reconcileSpeakerSecondsWithAudioClock();
        $this->clampSpeakerSecondsToPlausibleTimeline();

        $timeline = max(1.0, $this->totalSeconds, $this->audioCursorSeconds);
        $crosstalkPct = (float) round(($this->overlapSeconds / $timeline) * 100, 2);

        $speechSum = 0.0;
        foreach ($this->speakerSeconds as $s) {
            $speechSum += max(0.0, (float) $s);
        }
        $speechSum = max(1.0, $speechSum);

        DB::transaction(function () use ($meetingId, $speechSum, $crosstalkPct) {
            $labelToParticipantId = [];

            // Keep lastVoiceMatching across persists so UI/debug stays stable.

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

            $diarizationCollapsed = count($this->enrolledVoiceprints) >= 2 && $this->concreteDiarizedLabelCount() <= 1;

            // IMPORTANT:
            // When diarization collapses (everything is speaker_0), redirecting SpeakerMapping is harmful:
            // it becomes "sticky" and all subsequent speech is attributed to the first matched participant.
            // In that mode we must rely on rolling fallback attribution (fallbackParticipantSeconds) only.
            if (!$diarizationCollapsed) {
                // Voice recognition: if we can match a label to an enrolled participant, redirect the mapping
                // and delete the placeholder participant (so UI stops showing speaker_0/speaker_1).
                foreach (array_keys($this->speakerSeconds) as $label) {
                    $label = (string) $label;
                    // Fast-path for the common single-user meeting:
                    // bind label in ~2-3s instead of waiting for many embeddings.
                    if (!isset($this->autoBoundLabels[$label]) && count($this->enrolledVoiceprints) === 1) {
                        $sec = max(0.0, (float) ($this->speakerSeconds[$label] ?? 0.0));
                        if ($sec >= 2.0) {
                            $onlyPid = (int) ($this->enrolledVoiceprints[0]['participant_id'] ?? 0);
                            if ($onlyPid > 0) {
                                SpeakerMapping::query()
                                    ->where('meeting_id', $meetingId)
                                    ->where('speaker_label', $label)
                                    ->update([
                                        'participant_id' => $onlyPid,
                                        'confidence' => 0.99,
                                    ]);
                                $labelToParticipantId[$label] = $onlyPid;
                                $this->autoBoundLabels[$label] = true;
                                $bestName = (string) (MeetingParticipant::query()->whereKey($onlyPid)->value('name') ?? '');
                                $this->lastVoiceMatching[$label] = [
                                    'evidence_count' => 1,
                                    'best_participant_id' => $onlyPid,
                                    'best_participant_name' => $bestName,
                                    'best_score' => 0.99,
                                    'threshold' => 0.0,
                                    'matched' => true,
                                ];
                                continue;
                            }
                        }
                    }
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
                    $scored = $this->scoreAllParticipants($vec);
                    $bestByPid = (array) ($scored['scores'] ?? []);
                    $posterior = (array) ($scored['posterior'] ?? []);
                    $sum = $this->posteriorSummary($posterior);
                    $bestId = $sum['pid'] > 0 ? (int) $sum['pid'] : null;
                    $vals = array_values($bestByPid);
                    $bestScore = (float) ($vals[0] ?? -1.0);
                    $secondBest = (float) ($vals[1] ?? -1.0);
                    $bestName = $bestId ? (string) (MeetingParticipant::query()->whereKey((int) $bestId)->value('name') ?? '') : '';
                    $speechSeconds = max(0.0, (float) ($this->speakerSeconds[$label] ?? 0.0));

                    // EMA over posteriors per label so multi-class (5+) converges.
                    $alpha = (float) env('MEETING_VOICEPRINT_POSTERIOR_EMA_ALPHA', 0.35);
                    $alpha = max(0.05, min(0.95, $alpha));
                    $ema = $this->labelPosteriorEma[$label] ?? [];
                    foreach ($posterior as $pid => $p) {
                        $pid = (int) $pid;
                        $prev = (float) ($ema[$pid] ?? 0.0);
                        $ema[$pid] = ($alpha * (float) $p) + ((1.0 - $alpha) * $prev);
                    }
                    foreach ($ema as $pid => $p) {
                        if (!array_key_exists((int) $pid, $posterior)) {
                            $ema[(int) $pid] = (float) ($p * (1.0 - ($alpha * 0.20)));
                        }
                    }
                    arsort($ema);
                    $emaTopPid = count($ema) ? (int) array_key_first($ema) : 0;
                    $emaVals = array_values($ema);
                    $emaTopP = (float) ($emaVals[0] ?? 0.0);
                    $emaSecondP = (float) ($emaVals[1] ?? 0.0);
                    $this->labelPosteriorEma[$label] = $ema;

                    // Stability gating (hysteresis) for accurate binding.
                    $minPosterior = (float) env('MEETING_VOICEPRINT_LABEL_MIN_POSTERIOR', 0.60);
                    $minPosterior = max(0.25, min(0.95, $minPosterior));
                    $minGap = (float) env('MEETING_VOICEPRINT_LABEL_MIN_POSTERIOR_GAP', 0.03);
                    $minGap = max(0.0, min(0.5, $minGap));
                    $stableWindows = (int) env('MEETING_VOICEPRINT_LABEL_STABLE_WINDOWS', 2);
                    $stableWindows = max(1, min(10, $stableWindows));
                    $afterSeconds = (float) env('MEETING_VOICEPRINT_LABEL_MIN_SPEECH_BEFORE_BIND', 1.0);
                    $afterSeconds = max(0.0, min(10.0, $afterSeconds));

                    $prevStablePid = (int) ($this->labelStablePid[$label] ?? 0);
                    $prevStableCount = (int) ($this->labelStableCount[$label] ?? 0);
                    if ($emaTopPid !== $prevStablePid) {
                        if ($emaTopPid > 0 && $emaTopP >= $minPosterior && (($emaTopP - $emaSecondP) >= $minGap)) {
                            $this->labelStablePid[$label] = $emaTopPid;
                            $this->labelStableCount[$label] = 1;
                        } else {
                            $this->labelStableCount[$label] = 0;
                        }
                    } else {
                        $this->labelStableCount[$label] = $prevStableCount + 1;
                    }
                    $stablePid = (int) ($this->labelStablePid[$label] ?? 0);
                    $stableCount = (int) ($this->labelStableCount[$label] ?? 0);

                    $matched = $stablePid > 0
                        && $speechSeconds >= $afterSeconds
                        && $stableCount >= $stableWindows;
                    $usedThreshold = $minPosterior;

                    $this->lastVoiceMatching[$label] = [
                        'evidence_count' => $count,
                        'best_participant_id' => $bestId,
                        'best_participant_name' => $bestName,
                        'best_score' => (float) $bestScore,
                        'threshold' => (float) $usedThreshold,
                        'matched' => (bool) $matched,
                        'posterior_top' => (float) $sum['p'],
                        'posterior_second' => (float) $sum['second_p'],
                        'ema_top_pid' => (int) $emaTopPid,
                        'ema_top_p' => (float) $emaTopP,
                        'ema_second_p' => (float) $emaSecondP,
                        'stable_pid' => (int) $stablePid,
                        'stable_count' => (int) $stableCount,
                    ];
                    $this->debugVoice('score', [
                        'meeting_id' => $meetingId,
                        'label' => $label,
                        'evidence_count' => $count,
                        'best_participant_id' => $bestId,
                        'best_participant_name' => $bestName,
                        'best_score' => $bestScore,
                        'threshold' => $usedThreshold,
                        'second_best_score' => $secondBest,
                        'top_scores' => array_slice($bestByPid, 0, 6, true),
                        'top_posteriors' => array_slice($posterior, 0, 6, true),
                        'ema_top_pid' => $emaTopPid,
                        'ema_top_p' => $emaTopP,
                        'ema_second_p' => $emaSecondP,
                        'stable_pid' => $stablePid,
                        'stable_count' => $stableCount,
                        'speech_seconds' => $speechSeconds,
                    ]);

                    if (!$matched) {
                        continue;
                    }
                    $matchedParticipantId = (int) $stablePid;
                    $score = (float) $emaTopP;

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
            }

            // Fallback persist: if diarization collapsed to one label but we have direct per-participant attribution,
            // update ParticipantStat rows so analytics reflect the session correctly.
            if (count($this->fallbackParticipantSeconds) > 0 && count($this->enrolledVoiceprints) >= 2 && $this->concreteDiarizedLabelCount() <= 1) {
                $sum = 0.0;
                foreach ($this->fallbackParticipantSeconds as $sec) {
                    $sum += max(0.0, (float) $sec);
                }
                $sum = max(1.0, $sum);

                foreach ($this->fallbackParticipantSeconds as $pid => $sec) {
                    $pid = (int) $pid;
                    if ($pid <= 0) {
                        continue;
                    }

                    $talkTime = (int) round(max(0.0, (float) $sec));
                    $talkPct = (float) round(($talkTime / $sum) * 100, 2);
                    $talkPct = min(100.0, max(0.0, $talkPct));

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
                            'times_spoken' => (int) ($existing?->times_spoken ?? 0),
                        ]
                    );
                }
            }

            // Apply detected name to each WS speaker label (intro only; avoid sticky relabeling in collapsed mode).
            // If the same name was enrolled via HTTP intro, merge by redirecting
            // the SpeakerMapping to the enrolled participant (no duplicate rows).
            if (!$diarizationCollapsed) {
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
            }

            foreach ($this->speakerSeconds as $label => $sec) {
                $pid = $labelToParticipantId[$label] ?? null;
                if (!$pid) {
                    continue;
                }
                $talkTime = (int) round($sec);
                // Share of total diarized talk (always sums to ~100% across speakers).
                $talkPct = (float) round((max(0.0, (float) $sec) / $speechSum) * 100, 2);
                $talkPct = min(100.0, max(0.0, $talkPct));

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
                        'times_spoken' => (int) ($existing?->times_spoken ?? 0),
                    ]
                );
            }

            $nlp = $this->buildMeetingNlp($meetingId);
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

    /**
     * Spread small slices of wall-clock audio not yet reflected in Deepgram word timings
     * onto the most recently active speaker. Uses deltas so we never add the same gap
     * every stats tick (that caused runaway talk times like 290%).
     */
    private function reconcileSpeakerSecondsWithAudioClock(): void
    {
        $now = microtime(true);
        $sum = 0.0;
        foreach ($this->speakerSeconds as $s) {
            $sum += max(0.0, (float) $s);
        }
        $cur = max(0.0, (float) $this->audioCursorSeconds);

        if (!$this->talkClockBaselineReady) {
            $this->lastTalkClockSpeechSum = $sum;
            $this->lastTalkClockAudioCursor = $cur;
            $this->talkClockBaselineReady = true;

            return;
        }

        $dCur = $cur - $this->lastTalkClockAudioCursor;
        $dSum = $sum - $this->lastTalkClockSpeechSum;

        if ($dCur < 0 || $dSum < -0.01) {
            $this->lastTalkClockSpeechSum = $sum;
            $this->lastTalkClockAudioCursor = $cur;

            return;
        }

        $orphan = $dCur - $dSum;
        $recentSpeech = $this->lastActiveSpeakerAt > 0.0 && ($now - $this->lastActiveSpeakerAt) <= 0.8;
        if ($orphan > 0.04 && $orphan <= 3.0 && count($this->speakerSeconds) > 0 && $recentSpeech) {
            $last = $this->recentSpeakerIntervals[count($this->recentSpeakerIntervals) - 1] ?? null;
            $lastLabel = is_array($last) ? (string) ($last['label'] ?? '') : '';
            if ($lastLabel === '' || !array_key_exists($lastLabel, $this->speakerSeconds)) {
                $lastLabel = (string) array_key_first($this->speakerSeconds);
            }
            $this->speakerSeconds[$lastLabel] = ($this->speakerSeconds[$lastLabel] ?? 0.0) + $orphan;
            $sum += $orphan;
        }

        $this->lastTalkClockSpeechSum = $sum;
        $this->lastTalkClockAudioCursor = $cur;
    }

    /**
     * Cap attributed talk so it cannot exceed received audio by an implausible margin.
     * Realtime fill attributes PCM wall-clock to the active label while STT finals also add
     * diarized durations for the same speech → totals can exceed {@see $audioCursorSeconds}.
     * Allow extra headroom when overlap (simultaneous speech) is high.
     */
    private function clampSpeakerSecondsToPlausibleTimeline(): void
    {
        $cursor = max(0.0, (float) $this->audioCursorSeconds);
        if ($cursor <= 0.0 || count($this->speakerSeconds) === 0) {
            return;
        }

        $sum = 0.0;
        foreach ($this->speakerSeconds as $s) {
            $sum += max(0.0, (float) $s);
        }
        if ($sum <= $cursor) {
            return;
        }

        $timeline = max($cursor, max(1e-6, (float) $this->totalSeconds));
        $overlapRatio = min(1.0, $this->overlapSeconds / $timeline);
        $maxSum = $cursor * (1.0 + $overlapRatio);

        if ($sum <= $maxSum) {
            return;
        }

        $scale = $maxSum / max(1e-9, $sum);
        foreach ($this->speakerSeconds as $label => $s) {
            $this->speakerSeconds[$label] = max(0.0, (float) $s) * $scale;
        }
    }

    /**
     * Keep talk-time bars responsive while waiting for provider finals.
     */
    private function applyRealtimeActiveSpeakerEstimate(): void
    {
        $now = microtime(true);
        $cursor = max(0.0, (float) $this->audioCursorSeconds);
        $delta = $cursor - $this->lastRealtimeAllocCursor;
        if ($delta <= 0.0) {
            return;
        }
        $this->lastRealtimeAllocCursor = $cursor;
        if ($delta > 0.9) {
            return;
        }

        // Only allocate talk-time when we recently saw diarization activity.
        if ($this->lastActiveSpeakerAt <= 0.0 || ($now - $this->lastActiveSpeakerAt) > 0.8) {
            return;
        }

        $activeLabel = '';
        foreach ($this->livePartialByLabel as $label => $txt) {
            if (trim((string) $txt) !== '') {
                $activeLabel = (string) $label;
                break;
            }
        }
        if ($activeLabel === '' && $this->activeSpeakerLabel !== '') {
            $activeLabel = (string) $this->activeSpeakerLabel;
        }
        if ($activeLabel === '' && count($this->recentSpeakerIntervals) > 0) {
            $last = $this->recentSpeakerIntervals[count($this->recentSpeakerIntervals) - 1] ?? null;
            $activeLabel = is_array($last) ? (string) ($last['label'] ?? '') : '';
        }
        if ($activeLabel === '') {
            return;
        }

        $this->speakerSeconds[$activeLabel] = ($this->speakerSeconds[$activeLabel] ?? 0.0) + $delta;
        $this->totalSeconds = max($this->totalSeconds, $cursor);
    }

    /**
     * Crosstalk % from in-memory overlap (same formula as persistToDb) so the UI
     * does not lag behind the 2s DB persist timer.
     */
    private function computeLiveCrosstalkPercentage(): float
    {
        $timeline = max(1.0, $this->totalSeconds, $this->audioCursorSeconds);

        return (float) round(($this->overlapSeconds / $timeline) * 100, 2);
    }

    private function buildSnapshot(int $meetingId): ?array
    {
        $this->applyRealtimeActiveSpeakerEstimate();
        $this->reconcileSpeakerSecondsWithAudioClock();
        // Diarized seconds + realtime "fill" can exceed received PCM (double-count); keep UI sane vs live_audio_seconds.
        $this->clampSpeakerSecondsToPlausibleTimeline();

        // If diarization collapsed (<=1 label) but we have per-participant attribution, prefer it for the live UI.
        if (count($this->enrolledVoiceprints) >= 2
            && $this->concreteDiarizedLabelCount() <= 1
            && count($this->enrolledParticipantNamesById) > 0
            && count($this->fallbackParticipantSeconds) > 0) {
            $stats = [];
            $sum = 0.0;
            foreach ($this->fallbackParticipantSeconds as $sec) {
                $sum += max(0.0, (float) $sec);
            }
            $sum = max(0.001, $sum);

            foreach ($this->enrolledParticipantNamesById as $pid => $name) {
                $sec = max(0.0, (float) ($this->fallbackParticipantSeconds[(int) $pid] ?? 0.0));
                $pct = (float) round(($sec / $sum) * 100, 2);
                $pct = min(100.0, max(0.0, $pct));
                $stats[] = [
                    'participant_id' => (int) $pid,
                    'label' => '',
                    'name' => (string) $name,
                    'talk_time' => (int) floor($sec),
                    'talk_time_seconds' => $sec,
                    'talk_percentage' => $pct,
                    'times_spoken' => 0,
                ];
            }

            $payload = [
                'meeting_id' => $meetingId,
                'total_participants' => count($stats),
                'participants' => $stats,
                'crosstalk_percentage' => (float) $this->computeLiveCrosstalkPercentage(),
                'live_audio_seconds' => (float) $this->audioCursorSeconds,
                'voice_config' => [
                    'format' => $this->audioFormat,
                    'threshold' => (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75)),
                    'early_boost' => (float) (env('MEETING_VOICEPRINT_EARLY_BOOST', 0.07)),
                    'label_window_seconds' => (float) $this->labelWindowSeconds,
                    'label_min_speech_seconds' => (float) $this->labelMinSpeechSeconds,
                ],
                'voice_matching' => array_merge($this->lastVoiceMatching, [
                    '_fallback_last' => $this->lastFallbackMatching,
                ]),
                'updated_at' => null,
            ];
            if ($this->voiceDebug) {
                $payload['debug'] = [
                    'diarization_collapsed' => true,
                    'concrete_diarized_label_count' => $this->concreteDiarizedLabelCount(),
                    'label_to_participant_id' => $this->speakerLabelToParticipantIdCache,
                    'label_to_name' => $this->speakerLabelToNameCache,
                    'last_stt_words' => array_slice($this->lastSttWords, -30),
                    'last_stt_words_updated_at' => $this->lastSttWordsUpdatedAt,
                ];
            }
            return $payload;
        }

        // Live WS mode should feel real-time. DB stats store integer seconds (by design),
        // which makes the UI jump/lag. Prefer the in-memory floating seconds when available.
        $liveSeconds = $this->speakerSeconds;

        $liveLabels = array_keys($liveSeconds);
        $analytic = count($liveLabels) === 0
            ? MeetingAnalytic::query()->where('meeting_id', $meetingId)->first()
            : null;

        if (count($liveLabels) > 0) {
            $this->refreshSpeakerMappingCache($meetingId, array_map(fn ($x) => (string) $x, $liveLabels));

            $total = 0.0;
            foreach ($liveSeconds as $sec) {
                $total += max(0.0, (float) $sec);
            }
            $total = max(0.001, $total);

            $stats = [];
            foreach ($liveSeconds as $label => $sec) {
                $sec = max(0.0, (float) $sec);
                $pct = (float) round(($sec / $total) * 100, 2);
                $pct = min(100.0, max(0.0, $pct));
                $stats[] = [
                    'participant_id' => (int) ($this->speakerLabelToParticipantIdCache[$label] ?? 0),
                    'label' => (string) $label,
                    'name' => (string) ($this->speakerLabelToNameCache[$label] ?? (string) $label),
                    // Keep the legacy fields for the UI, but also send high-res seconds.
                    'talk_time' => (int) floor($sec),
                    'talk_time_seconds' => $sec,
                    'talk_percentage' => $pct,
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

        $crosstalk = count($liveLabels) > 0
            ? $this->computeLiveCrosstalkPercentage()
            : (float) ($analytic?->crosstalk_percentage ?? 0);

        $payload = [
            'meeting_id' => $meetingId,
            'total_participants' => count($stats),
            'participants' => $stats,
            'crosstalk_percentage' => $crosstalk,
            'live_audio_seconds' => (float) $this->audioCursorSeconds,
            'voice_config' => [
                'format' => $this->audioFormat,
                'threshold' => (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75)),
                'early_boost' => (float) (env('MEETING_VOICEPRINT_EARLY_BOOST', 0.07)),
                'label_window_seconds' => (float) $this->labelWindowSeconds,
                'label_min_speech_seconds' => (float) $this->labelMinSpeechSeconds,
            ],
            'voice_matching' => array_merge($this->lastVoiceMatching, [
                '_fallback_last' => $this->lastFallbackMatching,
            ]),
            'updated_at' => $analytic?->updated_at?->toISOString(),
        ];
        if ($this->voiceDebug) {
            $payload['debug'] = [
                'diarization_collapsed' => (count($this->enrolledVoiceprints) >= 2 && $this->concreteDiarizedLabelCount() <= 1),
                'concrete_diarized_label_count' => $this->concreteDiarizedLabelCount(),
                'label_to_participant_id' => $this->speakerLabelToParticipantIdCache,
                'label_to_name' => $this->speakerLabelToNameCache,
                'last_stt_words' => array_slice($this->lastSttWords, -30),
                'last_stt_words_updated_at' => $this->lastSttWordsUpdatedAt,
            ];
        }
        return $payload;
    }

    private function pushSttDebugWord(array $item): void
    {
        if (!$this->voiceDebug) {
            return;
        }
        $this->lastSttWords[] = $item;
        if (count($this->lastSttWords) > 120) {
            $this->lastSttWords = array_slice($this->lastSttWords, -120);
        }
        $this->lastSttWordsUpdatedAt = microtime(true);
    }
}