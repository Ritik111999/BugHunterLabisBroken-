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
use Laravel\Sanctum\PersonalAccessToken;
use Psr\Log\NullLogger;
use Revolt\EventLoop;
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

                    (new DeepgramLiveRelayConnection)->handle($client, $meetingId, $token);
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

    /**
     * Maps speaker_label -> ['name' => string, 'count' => int]
     * Populated from live "my name is X" detection.
     *
     * @var array<string,array{name:string,count:int}>
     */
    private array $speakerDetectedName = [];

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

    public function handle(WebsocketClient $frontend, int $meetingId, string $sanctumToken): void
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

        $deepgramKey = trim((string) env('DEEPGRAM_API_KEY', ''));
        if ($deepgramKey === '') {
            $frontend->sendText(json_encode(['error' => 'deepgram_key_missing']));
            $frontend->close();
            return;
        }

        // Preload participants enrolled via HTTP intro so we can merge them
        // when the same name is detected in the live WS session.
        $this->loadEnrolledParticipants($meetingId);

        $deepgram = $this->connectDeepgram($deepgramKey);

        EventLoop::queue(function () use ($deepgram, $frontend, $meetingId) {
            try {
                while ($msg = $deepgram->receive()) {
                    $text = $this->readClientMessage($msg);
                    if ($text === null) {
                        continue;
                    }
                    $this->onDeepgramMessage($meetingId, $text);

                    $snapshot = $this->buildSnapshot($meetingId);
                    if ($snapshot) {
                        $frontend->sendText(json_encode(['event' => 'stats.updated', 'data' => $snapshot]));
                    }

                    // Send a lightweight transcript payload for live UI.
                    $frontend->sendText(json_encode([
                        'event' => 'transcript.updated',
                        'data' => [
                            'meeting_id' => $meetingId,
                            'lines' => $this->buildTranscriptLines($meetingId),
                        ],
                    ]));
                }
            } catch (Throwable $e) {
                try {
                    if (!$frontend->isClosed()) {
                        $frontend->sendText(json_encode(['error' => 'deepgram_connection_error', 'message' => $e->getMessage()]));
                    }
                } catch (Throwable) {
                }
            } finally {
                try {
                    if (!$frontend->isClosed()) {
                        $frontend->close();
                    }
                } catch (Throwable) {
                }
            }
        });

        try {
            while ($message = $frontend->receive()) {
                if ($message->isBinary()) {
                    $deepgram->sendBinary($message->buffer());
                }
            }
        } catch (Throwable) {
        } finally {
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
        $query = http_build_query([
            // Nova-3 tends to perform better for noisy / multi-speaker meeting audio.
            'model' => $model,
            'diarize' => 'true',
            'punctuate' => 'true',
            'smart_format' => 'true',
            'interim_results' => 'true',
            'utterances' => 'true',
        ]);

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

                $t = (string) ($u['transcript'] ?? '');
                if ($t !== '') {
                    $speakerText[$label] = ($speakerText[$label] ?? '') . ' ' . $t;
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

                $word = (string) ($w['punctuated_word'] ?? ($w['word'] ?? ''));
                if ($word !== '') {
                    $speakerText[$label] = ($speakerText[$label] ?? '') . ' ' . $word;
                }
            }
        }

        $this->overlapSeconds += $this->computeOverlap($intervals);

        // FIX #6: Accumulate text per label across all Deepgram messages in this session.
        foreach ($speakerText as $label => $text) {
            $this->cumulativeSpeakerText[$label] = trim(
                ($this->cumulativeSpeakerText[$label] ?? '') . ' ' . $text
            );
        }

        // FIX #5: Run name detection on the full cumulative text per label,
        // and use a confidence counter so one noisy match doesn't overwrite a good one.
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
            // If different name detected, keep the one with higher count (don't overwrite).
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

        return [
            'meeting_id' => $meetingId,
            'total_participants' => count($stats),
            'participants' => $stats,
            'crosstalk_percentage' => (float) ($analytic?->crosstalk_percentage ?? 0),
            'updated_at' => $analytic?->updated_at?->toISOString(),
        ];
    }
}