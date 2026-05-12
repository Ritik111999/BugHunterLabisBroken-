<?php

namespace App\Jobs;

use App\Events\MeetingStatsUpdated;
use App\Models\Meeting;
use App\Models\MeetingAnalytic;
use App\Models\MeetingParticipant;
use App\Models\ParticipantStat;
use App\Models\SpeakerMapping;
use App\Models\Transcript;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

class ProcessChunkJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $meetingId;
    public ?string $filePath;
    public int $chunkIndex;
    public string $mode;
    public ?float $durationSeconds = null;
    private ?string $lastVoiceprintEngine = null;

    public function __construct(
        int $meetingId,
        ?string $filePath = null,
        int $chunkIndex = 0,
        string $mode = 'meeting',
        ?float $durationSeconds = null
    )
    {
        $this->meetingId = $meetingId;
        $this->filePath = $filePath;
        $this->chunkIndex = $chunkIndex;
        $this->mode = $mode;
        $this->durationSeconds = $durationSeconds;
    }

    public function handle(): void
    {
        $statsKey = $this->statsCacheKey($this->meetingId);
        $lockKey = $statsKey . '_lock';

        $lock = Cache::lock($lockKey, 10);

        try {
            $lock->block(5, function () use ($statsKey) {
                $analysis = $this->analyzeChunk();

                $stats = Cache::get($statsKey, [
                    'total_time' => 0,
                    'users' => [],
                    'crosstalk' => 0,
                    'speaker_text' => [],
                    'speaker_embeddings' => [],
                    'global_embedding' => null,
                ]);

                $stats = $this->applyAnalysisToStats($stats, $analysis);

                Cache::put($statsKey, $stats, now()->addHours(6));

                [$participants, $crosstalkPercentage] = $this->calculatePercentages($stats);

                $this->persistStatsToDatabase(
                    meetingId: $this->meetingId,
                    stats: $stats,
                    participants: $participants,
                    crosstalkPercentage: $crosstalkPercentage,
                );

                $this->persistTranscriptToDatabase(
                    meetingId: $this->meetingId,
                    analysis: $analysis,
                );

                event(new MeetingStatsUpdated(
                    meetingId: $this->meetingId,
                    participants: $participants,
                    crosstalkPercentage: $crosstalkPercentage,
                ));
            });
        } catch (Throwable $e) {
            throw $e;
        } finally {
            optional($lock)->release();
        }
    }

    private function statsCacheKey(int $meetingId): string
    {
        return "meeting_{$meetingId}_stats";
    }

    private function analyzeChunk(): array
    {
        if (!$this->filePath) {
            return $this->mockAnalysis();
        }

        $absolutePath = Storage::path($this->filePath);

        return $this->runPythonAnalyzer($absolutePath);
    }

    private function mockAnalysis(): array
    {
        $defaultSpeakers = ['Rajat', 'Amit'];

        $current = Cache::get($this->statsCacheKey($this->meetingId), []);
        $knownSpeakers = array_keys(Arr::get($current, 'users', []));
        $speakerPool = count($knownSpeakers) > 0 ? $knownSpeakers : $defaultSpeakers;
        $speaker = $speakerPool[array_rand($speakerPool)];

        $duration = 3;
        $isOverlap = random_int(0, 9) < 2;

        return [
            'total_seconds' => $duration,
            'speakers' => [
                $speaker => $duration,
            ],
            'overlap_seconds' => $isOverlap ? $duration : 0,
        ];
    }

    private function runPythonAnalyzer(string $absolutePath): array
    {
        $python = (string) config('meeting_analytics.analyzer.python', 'python3');
        $script = (string) config('meeting_analytics.analyzer.script');
        $timeout = (int) config('meeting_analytics.analyzer.timeout_seconds', 60);
        $sttProvider = strtolower(trim((string) env('MEETING_STT_PROVIDER', 'deepgram')));
        $deepgramKey = (string) (env('DEEPGRAM_API_KEY', '') ?: getenv('DEEPGRAM_API_KEY') ?: '');
        $pulseKey = trim((string) (config('services.pulse.api_key') ?: env('PULSE_API_KEY', '')));
        $hasStt = $sttProvider === 'pulse'
            ? ($pulseKey !== '')
            : (trim($deepgramKey) !== '');

        // In intro enrollment mode, name detection requires speech-to-text.
        // Silently falling back to mock analysis makes the UI look "broken"
        // (no participants ever get enrolled), so we fail loudly instead.
        if ($this->mode === 'intro' && !$hasStt) {
            $hint = $sttProvider === 'pulse'
                ? 'Set PULSE_API_KEY (and MEETING_STT_PROVIDER=pulse).'
                : 'Set DEEPGRAM_API_KEY (or switch MEETING_STT_PROVIDER=pulse with PULSE_API_KEY).';
            throw new \RuntimeException('Intro enrollment requires speech-to-text: ' . $hint);
        }

        // FIX #1:
        // - In "intro" enrollment mode we must NOT trim, otherwise short recordings
        //   can get fully trimmed and name detection will never happen.
        // - In "meeting" mode we only trim the very first meeting chunk (index 0).
        $introSeconds = $this->mode === 'intro'
            ? 0
            : ($this->chunkIndex === 0 ? (int) config('meeting_analytics.intro_seconds', 0) : 0);

        $process = new Process([
            $python,
            $script,
            '--file',
            $absolutePath,
            '--intro-seconds',
            (string) $introSeconds,
            '--timeout',
            (string) $timeout,
            '--deepgram-key',
            $deepgramKey,
            '--mode',
            $this->mode,
            '--max-seconds',
            (string) ($this->durationSeconds ?? 0),
        ]);
        $process->setEnv(array_merge($_SERVER, $_ENV, [
            'DEEPGRAM_API_KEY' => $deepgramKey,
            'MEETING_STT_PROVIDER' => $sttProvider,
            'PULSE_API_KEY' => $pulseKey,
            'PATH' => $this->buildPath(),
        ]));
        $process->setTimeout($timeout + 5);
        $process->run();

        if (!$process->isSuccessful()) {
            $stderr = trim((string) $process->getErrorOutput());
            $stdout = trim((string) $process->getOutput());
            $exit = (int) $process->getExitCode();

            $errorFromStdout = null;
            $maybeJson = json_decode($stdout, true);
            if (is_array($maybeJson) && isset($maybeJson['error']) && is_string($maybeJson['error'])) {
                $errorFromStdout = trim($maybeJson['error']);
            }

            if ($hasStt) {
                $reason = $errorFromStdout ?: ($stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'unknown_error'));
                throw new \RuntimeException("Analyzer failed (exit={$exit}): {$reason}");
            }
            return $this->mockAnalysis();
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded)) {
            if ($hasStt) {
                throw new \RuntimeException('Analyzer returned invalid JSON.');
            }
            return $this->mockAnalysis();
        }
        if ($hasStt && isset($decoded['error'])) {
            throw new \RuntimeException('Analyzer error: ' . (string) $decoded['error']);
        }

        $totalSeconds = (int) ($decoded['total_seconds'] ?? 0);
        $speakers = is_array($decoded['speakers'] ?? null) ? $decoded['speakers'] : [];
        $overlapSeconds = (int) ($decoded['overlap_seconds'] ?? 0);
        $speakerText = is_array($decoded['speaker_text'] ?? null) ? $decoded['speaker_text'] : [];
        $globalEmbedding = is_array($decoded['global_embedding'] ?? null) ? $decoded['global_embedding'] : null;

        $normalizedSpeakers = [];
        foreach ($speakers as $name => $seconds) {
            $normalizedSpeakers[(string) $name] = (int) $seconds;
        }

        if ($hasStt && count($normalizedSpeakers) === 1 && array_key_exists('Unknown', $normalizedSpeakers)) {
            throw new \RuntimeException('Analyzer did not diarize (Unknown speaker).');
        }

        return [
            'total_seconds' => max(0, $totalSeconds),
            'speakers' => $normalizedSpeakers,
            'overlap_seconds' => max(0, $overlapSeconds),
            'speaker_text' => array_map(fn ($v) => (string) $v, $speakerText),
            'global_embedding' => $globalEmbedding,
        ];
    }

    private function applyAnalysisToStats(array $stats, array $analysis): array
    {
        $duration = max(0, (int) ($analysis['total_seconds'] ?? 0));

        $stats['total_time'] = ((int) ($stats['total_time'] ?? 0)) + $duration;
        $stats['users'] = is_array($stats['users'] ?? null) ? $stats['users'] : [];

        $analysisSpeakers = is_array($analysis['speakers'] ?? null) ? $analysis['speakers'] : [];
        $analysisSpeakerText = is_array($analysis['speaker_text'] ?? null) ? $analysis['speaker_text'] : [];
        $analysisSpeakerEmbeddings = is_array($analysis['speaker_embeddings'] ?? null) ? $analysis['speaker_embeddings'] : [];

        foreach ($analysisSpeakers as $speaker => $seconds) {
            // FIX #2: Namespace speaker labels by chunk index so speaker_0 in chunk 1
            // never collides with speaker_0 in chunk 2.
            $scopedLabel = 'chunk' . $this->chunkIndex . '_' . (string) $speaker;
            $stats['users'][$scopedLabel] = ((int) ($stats['users'][$scopedLabel] ?? 0)) + (int) $seconds;
        }

        $stats['crosstalk'] = ((int) ($stats['crosstalk'] ?? 0)) + (int) ($analysis['overlap_seconds'] ?? 0);

        $stats['speaker_text'] = is_array($stats['speaker_text'] ?? null) ? $stats['speaker_text'] : [];
        foreach ($analysisSpeakerText as $label => $text) {
            // Also namespace incoming speaker text keys by chunk index.
            $scopedLabel = 'chunk' . $this->chunkIndex . '_' . (string) $label;
            $text = trim((string) $text);
            if ($text === '') {
                continue;
            }
            $stats['speaker_text'][$scopedLabel] = trim(((string) ($stats['speaker_text'][$scopedLabel] ?? '')) . ' ' . $text);
        }

        // Store latest speaker embeddings per scoped label (for voice recognition).
        $stats['speaker_embeddings'] = is_array($stats['speaker_embeddings'] ?? null) ? $stats['speaker_embeddings'] : [];
        foreach ($analysisSpeakerEmbeddings as $label => $embedding) {
            $scopedLabel = 'chunk' . $this->chunkIndex . '_' . (string) $label;
            if (!is_array($embedding) || count($embedding) === 0) {
                continue;
            }
            $vec = [];
            foreach ($embedding as $v) {
                if (is_numeric($v)) {
                    $vec[] = (float) $v;
                }
            }
            if (count($vec) === 0) {
                continue;
            }
            $stats['speaker_embeddings'][$scopedLabel] = $vec;
        }

        return $stats;
    }

    /**
     * FIX #3: Improved name extraction.
     * - Intro mode: takes the FIRST match (each intro clip is for one person; avoid bleed from other voices).
     * - Meeting mode: takes the LAST match to handle bleed-over from previous speaker.
     * - Captures up to 3 words to support full names (e.g. "Chandra Kant Arya").
     * - Avoids common trailing filler words.
     */
    private function extractNameFromText(string $text): ?string
    {
        $t = strtolower(trim($text));
        // Normalize “smart” quotes so phrases like I'm / my name's match reliably.
        $t = str_replace(
            ["\u{2019}", "\u{2018}", "\u{201C}", "\u{201D}"],
            ["'", "'", '"', '"'],
            $t
        );
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        // Match common enrollment phrases. Capture up to 3 words (letters) for full names.
        // We still take the LAST match in meeting mode to avoid bleed-over from earlier speakers.
        $matches = [];
        preg_match_all(
            '/\b(?:my name is|my name\'s|i am|this is|it\'s|its|im|i\'m|call me|you can call me)\s+([a-z][a-z]{1,29}(?:\s+[a-z][a-z]{1,29}){0,2})\b/iu',
            $t,
            $matches
        );

        if (empty($matches[1])) {
            return null;
        }

        // Intro clips are intended to contain a single participant name at the start.
        // If another name bleeds into the end of the clip, taking the LAST match
        // can incorrectly save David's voiceprint under Getty (or vice versa).
        $useFirst = $this->mode === 'intro';
        $raw = $useFirst
            ? trim((string) ($matches[1][0] ?? ''))
            : trim((string) end($matches[1]));
        $raw = preg_replace('/[^a-z\s]/i', '', $raw) ?? $raw;
        $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? $raw);

        // Drop common trailing filler words if present.
        // Example: "Amit sir" -> "Amit"
        $parts = $raw === '' ? [] : explode(' ', $raw);
        $filler = ['sir', 'mam', 'maam', 'ji', 'hello', 'hi', 'hey'];
        while (!empty($parts) && in_array(strtolower((string) end($parts)), $filler, true)) {
            array_pop($parts);
        }
        $raw = trim(implode(' ', $parts));

        if ($raw === '' || strlen(str_replace(' ', '', $raw)) < 2) {
            return null;
        }

        $words = array_values(array_filter(explode(' ', $raw), fn ($w) => $w !== ''));
        if (count($words) === 0) {
            return null;
        }

        $words = array_map(
            fn ($w) => ucfirst(strtolower((string) $w)),
            $words
        );

        return implode(' ', $words);
    }

    /**
     * Build a PATH string that includes the directory where ffprobe lives,
     * so the queue worker finds it regardless of environment (Mac, Linux, cPanel).
     */
    private function buildPath(): string
    {
        // Start with the system PATH if available.
        $existing = $_SERVER['PATH'] ?? $_ENV['PATH'] ?? getenv('PATH') ?? '';

        // Common locations to search for ffprobe.
        $candidates = [
            '/opt/homebrew/bin',   // macOS Apple Silicon
            '/usr/local/bin',      // macOS Intel / Linux custom installs
            '/usr/bin',            // Linux system
            '/bin',                // Linux fallback
            '/usr/local/ffmpeg/bin', // some cPanel installs
            '/opt/cpanel/ffmpeg/bin', // cPanel EasyApache ffmpeg
        ];

        // Auto-detect: find whichever directory actually contains ffprobe.
        $detected = null;
        foreach ($candidates as $dir) {
            if (is_executable($dir . '/ffprobe')) {
                $detected = $dir;
                break;
            }
        }

        // Merge: detected dir first, then existing PATH, then all candidates as fallback.
        $parts = array_filter(array_unique(array_merge(
            $detected ? [$detected] : [],
            $existing !== '' ? explode(':', $existing) : [],
            $candidates,
        )));

        return implode(':', $parts);
    }

    private function buildMeetingNlp(int $meetingId, array $speakerText): array
    {
        $labels = array_map(fn ($k) => (string) $k, array_keys($speakerText));
        $rows = SpeakerMapping::query()
            ->with('participant:id,name')
            ->where('meeting_id', $meetingId)
            ->whereIn('speaker_label', $labels)
            ->get();

        $labelToName = [];
        foreach ($rows as $r) {
            $lbl = (string) ($r->speaker_label ?? '');
            if ($lbl === '') continue;
            $labelToName[$lbl] = (string) ($r->participant?->name ?? $lbl);
        }

        $full = trim(implode("\n", array_map(
            fn ($label, $t) => '[' . (string) ($labelToName[(string) $label] ?? (string) $label) . '] ' . trim((string) $t),
            array_keys($speakerText),
            array_values($speakerText),
        )));

        return [
            'keywords' => $this->extractKeywords($full),
            'summary' => $this->buildSummary($full),
            'action_items' => [],
            'sentiment' => $this->simpleSentiment($full),
        ];
    }

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
            'can','could','will','would','should','may','might','am','im','i\'m','my','name','says','say',
        ]);

        $counts = [];
        foreach ($words as $w) {
            if (isset($stop[$w]) || strlen($w) < 3) {
                continue;
            }
            $counts[$w] = ($counts[$w] ?? 0) + 1;
        }

        arsort($counts);
        return array_slice(array_keys($counts), 0, 8);
    }

    private function buildSummary(string $text): string
    {
        $t = trim(preg_replace('/\\s+/', ' ', $text) ?? $text);
        if ($t === '') {
            return '';
        }
        return mb_substr($t, max(0, mb_strlen($t) - 500));
    }

    private function simpleSentiment(string $text): array
    {
        $t = strtolower($text);
        $pos = ['good','great','nice','love','like','excellent','amazing','awesome','happy','thanks','thank'];
        $neg = ['bad','hate','issue','problem','sad','angry','terrible','awful','worse','worst','fail','error'];
        $p = 0;
        $n = 0;
        foreach ($pos as $w) {
            $p += substr_count($t, $w);
        }
        foreach ($neg as $w) {
            $n += substr_count($t, $w);
        }
        $score = $p - $n;
        return [
            'label' => $score > 0 ? 'positive' : ($score < 0 ? 'negative' : 'neutral'),
            'score' => $score,
            'positive_hits' => $p,
            'negative_hits' => $n,
        ];
    }

    private function calculatePercentages(array $stats): array
    {
        $total = max(1, (int) ($stats['total_time'] ?? 0));

        $participants = [];
        foreach (($stats['users'] ?? []) as $name => $seconds) {
            $participants[] = [
                'name' => (string) $name,
                'percentage' => (int) round(((int) $seconds / $total) * 100),
            ];
        }

        $crosstalk = (int) ($stats['crosstalk'] ?? 0);
        $crosstalkPercentage = (int) round(($crosstalk / $total) * 100);

        return [$participants, $crosstalkPercentage];
    }

    private function persistStatsToDatabase(int $meetingId, array $stats, array $participants, int $crosstalkPercentage): void
    {
        $meeting = Meeting::query()->whereKey($meetingId)->first();
        if (!$meeting) {
            return;
        }

        $users = is_array($stats['users'] ?? null) ? $stats['users'] : [];
        $speakerText = is_array($stats['speaker_text'] ?? null) ? $stats['speaker_text'] : [];
        $speakerEmbeddings = is_array($stats['speaker_embeddings'] ?? null) ? $stats['speaker_embeddings'] : [];
        $globalEmbedding = is_array($stats['global_embedding'] ?? null) ? $stats['global_embedding'] : null;

        DB::transaction(function () use ($meeting, $meetingId, $users, $participants, $crosstalkPercentage, $speakerText, $speakerEmbeddings, $globalEmbedding) {
            $isIntroMode = $this->mode === 'intro' || (string) ($meeting->status ?? '') === 'pending';

            $labelToParticipantId = [];

            // Preload voiceprints enrolled via intro so we can recognize speakers.
            $enrolledVoiceprints = $this->loadEnrolledVoiceprints($meetingId);

            if (!$isIntroMode) {
                foreach ($users as $label => $seconds) {
                    $speakerLabel = (string) $label;

                    $mapping = SpeakerMapping::query()
                        ->where('meeting_id', $meetingId)
                        ->where('speaker_label', $speakerLabel)
                        ->first();

                    if (!$mapping) {
                        // Strip chunk prefix for display: "chunk2_speaker_0" → "Speaker 0"
                        $displayName = $speakerLabel;
                        if (preg_match('/^chunk\d+_speaker_(\d+)$/', $speakerLabel, $m)) {
                            $displayName = 'Speaker ' . $m[1];
                        }

                        $participant = MeetingParticipant::create([
                            'meeting_id' => $meetingId,
                            'user_id' => null,
                            'name' => $displayName,
                            'voice_embedding' => null,
                        ]);

                        $mapping = SpeakerMapping::create([
                            'meeting_id' => $meetingId,
                            'speaker_label' => $speakerLabel,
                            'participant_id' => $participant->id,
                            'confidence' => null,
                        ]);
                    }

                    $labelToParticipantId[$speakerLabel] = (int) $mapping->participant_id;
                }
            }

            // Intro-only: enroll participants (name + voiceprint) and persist the mapping.
            // In meeting mode we must NOT enroll/rename based on "my name is ...".
            if ($isIntroMode) {
                // FIX #4: Enrollment (name detection) is scoped per chunk label.
                // Each chunk gets its own speaker_0, speaker_1 etc. so names never collide.
                foreach ($speakerText as $label => $text) {
                    $label = (string) $label;
                    $realName = $this->extractNameFromText((string) $text);
                    if (!$realName) {
                        continue;
                    }

                    $enrolledEmbedding = [
                        'provider' => 'deepgram',
                        'type' => 'intro_name_enrollment',
                        'speaker_label' => $label,
                        'enrolled_name' => $realName,
                        'enrolled_at' => now()->toISOString(),
                    ];

                    // Attach voiceprint for enrollment.
                    //
                    // IMPORTANT (latency):
                    // Running `embed_audio.py` here adds a second Python process per enrollment chunk
                    // and often pushes enrollment to 10–15s+ on dev machines.
                    //
                    // For fast enrollment (target ~4–5s), we first try to use embeddings already
                    // produced by `analyze_audio.py`. If you need the heavier normalized embedding,
                    // re-enable it by setting MEETING_INTRO_EMBED_VOICEPRINT=1.
                    $didEmbed = false;
                    $embedEnabled = filter_var(env('MEETING_INTRO_EMBED_VOICEPRINT', false), FILTER_VALIDATE_BOOL);
                    if ($embedEnabled && $this->filePath) {
                        $computed = $this->computeVoiceprint(Storage::path($this->filePath));
                        if (is_array($computed) && count($computed) >= 32) {
                            $enrolledEmbedding['voiceprint'] = array_map('floatval', $computed);
                            $enrolledEmbedding['voiceprint_dim'] = count($enrolledEmbedding['voiceprint']);
                            if (is_string($this->lastVoiceprintEngine) && $this->lastVoiceprintEngine !== '') {
                                $enrolledEmbedding['voiceprint_engine'] = $this->lastVoiceprintEngine;
                            }
                            $didEmbed = true;
                        }
                    }

                    if (!$didEmbed) {
                        $vec = $speakerEmbeddings[$label] ?? null;
                        if (is_array($vec) && count($vec) >= 32) {
                            $enrolledEmbedding['voiceprint'] = array_map('floatval', $vec);
                            $enrolledEmbedding['voiceprint_dim'] = count($enrolledEmbedding['voiceprint']);
                        } elseif (is_array($globalEmbedding) && count($globalEmbedding) >= 32) {
                            $enrolledEmbedding['voiceprint'] = array_map('floatval', $globalEmbedding);
                            $enrolledEmbedding['voiceprint_dim'] = count($enrolledEmbedding['voiceprint']);
                        }
                    }

                    // Find or create participant by real name.
                    $named = MeetingParticipant::query()
                        ->where('meeting_id', $meetingId)
                        ->whereRaw('LOWER(name) = ?', [strtolower($realName)])
                        ->first();

                    // Guard against intro "bleed": each intro chunk has its own scoped diarization labels
                    // (`chunk0_speaker_0`, `chunk1_speaker_0`, ...). If name extraction mistakenly returns an
                    // existing participant's name from another chunk, do NOT append/overwrite templates, as it
                    // corrupts enrollment for multi-speaker meetings.
                    if ($named) {
                        $prevEmbed = is_array($named->voice_embedding) ? $named->voice_embedding : [];
                        $prevLabel = (string) ($prevEmbed['speaker_label'] ?? '');
                        $curLabel = (string) $label;
                        $prevChunk = null;
                        $curChunk = null;
                        if (preg_match('/^chunk(\d+)_/i', $prevLabel, $m)) {
                            $prevChunk = (int) $m[1];
                        }
                        if (preg_match('/^chunk(\d+)_/i', $curLabel, $m2)) {
                            $curChunk = (int) $m2[1];
                        }
                        if ($prevChunk !== null && $curChunk !== null && $prevChunk !== $curChunk) {
                            Log::warning('intro_name_collision_skipped', [
                                'meeting_id' => $meetingId,
                                'enrolled_name' => $realName,
                                'speaker_label' => $curLabel,
                                'conflicts_with_participant_id' => (int) $named->id,
                                'conflicts_with_speaker_label' => $prevLabel,
                            ]);
                            continue;
                        }
                    }

                    // Safety: if this intro clip accidentally contains another person's voice (bleed / wrong clip),
                    // the computed voiceprint can become nearly identical to an existing participant's voiceprint.
                    // In that case, refuse to save it so we don't corrupt enrollment.
                    $newVp = $enrolledEmbedding['voiceprint'] ?? null;
                    if (is_array($newVp) && count($newVp) >= 32) {
                        $dupThr = (float) env('MEETING_INTRO_DUPLICATE_VOICEPRINT_SIM', 0.985);
                        $dupThr = max(0.85, min(0.999, $dupThr));
                        $others = MeetingParticipant::query()
                            ->where('meeting_id', $meetingId)
                            ->whereNotNull('voice_embedding')
                            ->get(['id', 'name', 'voice_embedding']);
                        foreach ($others as $op) {
                            if ($named && (int) $op->id === (int) $named->id) {
                                continue;
                            }
                            $ove = is_array($op->voice_embedding) ? $op->voice_embedding : [];
                            $ovp = $ove['voiceprint'] ?? null;
                            if (!is_array($ovp) || count($ovp) < 32) {
                                continue;
                            }
                            $sim = $this->cosineSimilarity($newVp, array_map('floatval', $ovp));
                            if ($sim >= $dupThr) {
                                Log::warning('intro_voiceprint_duplicate_suspected', [
                                    'meeting_id' => $meetingId,
                                    'enrolled_name' => $realName,
                                    'speaker_label' => $label,
                                    'similarity' => $sim,
                                    'threshold' => $dupThr,
                                    'conflicts_with_participant_id' => (int) $op->id,
                                    'conflicts_with_name' => (string) ($op->name ?? ''),
                                ]);
                                unset($enrolledEmbedding['voiceprint'], $enrolledEmbedding['voiceprint_dim']);
                                break;
                            }
                        }
                    }

                    if (!$named) {
                        $named = MeetingParticipant::create([
                            'meeting_id' => $meetingId,
                            'user_id' => null,
                            'name' => $realName,
                            'voice_embedding' => $enrolledEmbedding,
                        ]);
                    } else {
                        $prev = is_array($named->voice_embedding) ? $named->voice_embedding : [];

                        // If the same person enrolls multiple times, keep multiple voiceprints.
                        // This makes matching robust (different mic distance/noise) and avoids
                        // "second person gets absorbed into first" when only one template exists.
                        $hasNewVoiceprint = is_array($enrolledEmbedding['voiceprint'] ?? null) && count((array) $enrolledEmbedding['voiceprint']) >= 32;
                        if ($hasNewVoiceprint) {
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
                            $voiceprints[] = array_map('floatval', (array) $enrolledEmbedding['voiceprint']);

                            // Keep most recent N templates.
                            $max = (int) env('MEETING_INTRO_MAX_VOICEPRINTS_PER_PERSON', 5);
                            $max = max(1, min(10, $max));
                            if (count($voiceprints) > $max) {
                                $voiceprints = array_slice($voiceprints, -1 * $max);
                            }

                            // Prefer keeping the existing primary voiceprint stable; only set it if missing.
                            if (!is_array($prev['voiceprint'] ?? null) || count((array) ($prev['voiceprint'] ?? [])) < 32) {
                                $prev['voiceprint'] = $voiceprints[count($voiceprints) - 1];
                            }
                            $prev['voiceprint_dim'] = is_array($prev['voiceprint'] ?? null) ? count((array) $prev['voiceprint']) : 0;
                            $prev['voiceprints'] = $voiceprints;
                        }

                        // CRITICAL: Do NOT overwrite an existing primary voiceprint during intro enrollment.
                        // If the same name is detected again due to bleed/duplicate phrases, we only append
                        // templates to `voiceprints[]` and keep the first stable `voiceprint`.
                        if ($hasNewVoiceprint && is_array($prev['voiceprint'] ?? null) && count((array) ($prev['voiceprint'] ?? [])) >= 32) {
                            unset($enrolledEmbedding['voiceprint'], $enrolledEmbedding['voiceprint_dim']);
                        }

                        $named->update([
                            'voice_embedding' => array_merge($prev, $enrolledEmbedding),
                        ]);
                    }

                    // Map THIS chunk's scoped label to the named participant.
                    $mapping = SpeakerMapping::updateOrCreate(
                        [
                            'meeting_id' => $meetingId,
                            'speaker_label' => $label,
                        ],
                        [
                            'participant_id' => $named->id,
                            'confidence' => null,
                        ]
                    );

                    $labelToParticipantId[$label] = (int) $mapping->participant_id;

                    // Async voiceprint compute (fast enrollment):
                    // If we didn't run the heavy embed step inline, compute SpeechBrain ECAPA in background.
                    if (!$embedEnabled && $this->filePath) {
                        $ve = is_array($named->voice_embedding) ? $named->voice_embedding : [];
                        $engine = strtolower(trim((string) ($ve['voiceprint_engine'] ?? $ve['engine'] ?? '')));
                        $hasVp = is_array($ve['voiceprint'] ?? null) && count((array) ($ve['voiceprint'] ?? [])) >= 32;

                        if (!$hasVp || $engine !== 'speechbrain_ecapa') {
                            ComputeIntroVoiceprintJob::dispatch(
                                meetingId: $meetingId,
                                participantId: (int) $named->id,
                                filePath: (string) $this->filePath,
                                chunkIndex: (int) $this->chunkIndex,
                                maxSeconds: $this->durationSeconds,
                            )->onQueue('audio');
                        }
                    }
                }
            }

            // Voice recognition in meeting mode: match each label embedding to enrolled voiceprints,
            // and redirect SpeakerMappings away from placeholders when confident.
            if (!$isIntroMode && count($enrolledVoiceprints) > 0 && count($speakerEmbeddings) > 0) {
                foreach ($speakerEmbeddings as $label => $vec) {
                    if (!is_string($label) || !is_array($vec) || count($vec) === 0) {
                        continue;
                    }
                    $pid = $labelToParticipantId[$label] ?? null;
                    if (!$pid) {
                        continue;
                    }

                    $match = $this->matchVoiceprint($vec, $enrolledVoiceprints);
                    if (!$match) {
                        continue;
                    }

                    [$matchedParticipantId, $score] = $match;

                    // Redirect mapping to the matched participant.
                    if ((int) $matchedParticipantId !== (int) $pid) {
                        SpeakerMapping::query()
                            ->where('meeting_id', $meetingId)
                            ->where('speaker_label', $label)
                            ->update([
                                'participant_id' => (int) $matchedParticipantId,
                                'confidence' => $score,
                            ]);

                        // Delete placeholder participant if it's only a generated speaker label.
                        $participant = MeetingParticipant::query()->whereKey($pid)->first();
                        if ($participant) {
                            $isPlaceholder = preg_match('/^Speaker\s+\d+$/i', (string) $participant->name) === 1
                                || preg_match('/^chunk\d+_speaker_\d+$/i', (string) $participant->name) === 1
                                || preg_match('/^chunk\d+_speaker_unknown$/i', (string) $participant->name) === 1;
                            if ($isPlaceholder) {
                                $participant->delete();
                            }
                        }

                        $labelToParticipantId[$label] = (int) $matchedParticipantId;
                    }
                }
            }

            if (!$isIntroMode) {
                foreach ($participants as $p) {
                    $label = (string) ($p['name'] ?? '');
                    if ($label === '' || !isset($labelToParticipantId[$label])) {
                        continue;
                    }

                    $participantId = $labelToParticipantId[$label];
                    $talkTime = (int) ($users[$label] ?? 0);
                    $talkPercentage = (float) ((int) ($p['percentage'] ?? 0));

                    $existing = ParticipantStat::query()
                        ->where('meeting_id', $meetingId)
                        ->where('participant_id', $participantId)
                        ->first();

                    ParticipantStat::updateOrCreate(
                        [
                            'meeting_id' => $meetingId,
                            'participant_id' => $participantId,
                        ],
                        [
                            'talk_time' => $talkTime,
                            'talk_percentage' => $talkPercentage,
                            'interruptions' => (int) ($existing?->interruptions ?? 0),
                            'times_spoken' => (int) (($existing?->times_spoken ?? 0) + 1),
                        ]
                    );
                }

                $nlp = $this->buildMeetingNlp($meetingId, $speakerText);
                MeetingAnalytic::updateOrCreate(
                    ['meeting_id' => $meetingId],
                    [
                        'crosstalk_percentage' => (float) $crosstalkPercentage,
                        'total_speakers' => count($participants),
                        'keywords' => $nlp['keywords'],
                        'summary' => $nlp['summary'],
                        'action_items' => $nlp['action_items'],
                        'sentiment' => $nlp['sentiment'],
                    ]
                );
            }
        });
    }

    /**
     * Compute a normalized voiceprint embedding for an audio file.
     *
     * @return array<int,float>|null
     */
    private function computeVoiceprint(string $absolutePath): ?array
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
            (string) ($this->durationSeconds ?? 0),
        ]);
        $process->setTimeout($timeout + 20);
        $process->setEnv(array_merge($_SERVER, $_ENV, [
            'PATH' => $this->buildPath(),
        ]));
        $process->run();

        if (!$process->isSuccessful()) {
            Log::warning('intro_voiceprint_compute_failed', [
                'meeting_id' => $this->meetingId,
                'chunk_index' => $this->chunkIndex,
                'mode' => $this->mode,
                'python' => $python,
                'script' => $script,
                'file' => $absolutePath,
                'exit_code' => $process->getExitCode(),
                'error_output' => trim((string) $process->getErrorOutput()),
                'output' => trim((string) $process->getOutput()),
            ]);
            return null;
        }

        $decoded = json_decode((string) $process->getOutput(), true);
        if (!is_array($decoded) || !is_array($decoded['embedding'] ?? null)) {
            Log::warning('intro_voiceprint_compute_invalid_json', [
                'meeting_id' => $this->meetingId,
                'chunk_index' => $this->chunkIndex,
                'mode' => $this->mode,
                'python' => $python,
                'script' => $script,
                'file' => $absolutePath,
                'output' => trim((string) $process->getOutput()),
            ]);
            return null;
        }

        $this->lastVoiceprintEngine = is_string($decoded['engine'] ?? null) ? (string) $decoded['engine'] : null;
        $vec = [];
        foreach ($decoded['embedding'] as $v) {
            if (is_numeric($v)) {
                $vec[] = (float) $v;
            }
        }
        if (count($vec) >= 32) {
            Log::info('intro_voiceprint_compute_ok', [
                'meeting_id' => $this->meetingId,
                'chunk_index' => $this->chunkIndex,
                'mode' => $this->mode,
                'dim' => count($vec),
                'engine' => is_string($decoded['engine'] ?? null) ? (string) $decoded['engine'] : null,
            ]);
        }
        return count($vec) > 0 ? $vec : null;
    }

    /**
     * @return array<int,array{participant_id:int,vector:array<int,float>}>
     */
    private function loadEnrolledVoiceprints(int $meetingId): array
    {
        $rows = MeetingParticipant::query()
            ->where('meeting_id', $meetingId)
            ->whereNotNull('voice_embedding')
            ->get(['id', 'voice_embedding']);

        $expectedDim = (int) env('MEETING_VOICEPRINT_EXPECTED_DIM', 192);
        $expectedDim = max(32, min(2048, $expectedDim));

        $out = [];
        foreach ($rows as $p) {
            $ve = is_array($p->voice_embedding) ? $p->voice_embedding : [];
            $engine = strtolower(trim((string) ($ve['voiceprint_engine'] ?? $ve['engine'] ?? '')));
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
            $out[] = [
                'participant_id' => (int) $p->id,
                'vector' => $floats,
            ];
        }
        return $out;
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
     * @param array<int,array{participant_id:int,vector:array<int,float>}> $enrolled
     * @return array{0:int,1:float}|null
     */
    private function matchVoiceprint(array $vec, array $enrolled): ?array
    {
        $threshold = (float) (env('MEETING_VOICEPRINT_THRESHOLD', 0.75));
        $minMargin = (float) (env('MEETING_VOICEPRINT_MARGIN', 0.05));

        // Participants can have multiple templates. Compare by "best score per participant"
        // so second-best is truly another participant (not the same participant's 2nd template).
        $bestByPid = [];
        foreach ($enrolled as $e) {
            $pid = (int) ($e['participant_id'] ?? 0);
            if ($pid <= 0) {
                continue;
            }
            $vec2 = $e['vector'] ?? null;
            if (!is_array($vec2) || count($vec2) < 32) {
                continue;
            }
            $score = $this->cosineSimilarity($vec, $vec2);
            if (!isset($bestByPid[$pid]) || $score > (float) $bestByPid[$pid]) {
                $bestByPid[$pid] = (float) $score;
            }
        }

        if (count($bestByPid) === 0) {
            return null;
        }

        arsort($bestByPid);
        $bestId = (int) array_key_first($bestByPid);
        $vals = array_values($bestByPid);
        $bestScore = (float) ($vals[0] ?? -1.0);
        $secondBest = (float) ($vals[1] ?? -1.0);

        if ($bestId <= 0 || $bestScore < $threshold) {
            return null;
        }
        if (($bestScore - max(-1.0, $secondBest)) < $minMargin) {
            return null;
        }

        return [$bestId, $bestScore];
    }

    private function persistTranscriptToDatabase(int $meetingId, array $analysis): void
    {
        if (!Meeting::query()->whereKey($meetingId)->exists()) {
            return;
        }

        $speakerText = is_array($analysis['speaker_text'] ?? null) ? $analysis['speaker_text'] : [];

        // Map diarization labels like "speaker_0" to enrolled participant names
        // so transcript excerpts and NLP insights don't show raw speaker_0 tags.
        $labels = array_map(fn ($k) => (string) $k, array_keys($speakerText));
        $rows = SpeakerMapping::query()
            ->with('participant:id,name')
            ->where('meeting_id', $meetingId)
            ->whereIn('speaker_label', $labels)
            ->get();

        $labelToName = [];
        foreach ($rows as $r) {
            $lbl = (string) ($r->speaker_label ?? '');
            if ($lbl === '') continue;
            $labelToName[$lbl] = (string) ($r->participant?->name ?? $lbl);
        }

        $text = trim(implode("\n", array_map(
            fn ($label, $t) => '[' . (string) ($labelToName[(string) $label] ?? $label) . '] ' . trim((string) $t),
            array_keys($speakerText),
            array_values($speakerText),
        )));

        if ($text === '') {
            return;
        }

        $chunkSeconds = (float) ((int) ($analysis['total_seconds'] ?? 0));
        Transcript::create([
            'meeting_id' => $meetingId,
            'text' => $text,
            'start_time' => 0.0,
            'end_time' => max(0.0, $chunkSeconds),
        ]);
    }
}