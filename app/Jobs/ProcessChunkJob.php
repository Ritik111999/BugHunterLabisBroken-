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

    public function __construct(int $meetingId, ?string $filePath = null, int $chunkIndex = 0, string $mode = 'meeting')
    {
        $this->meetingId = $meetingId;
        $this->filePath = $filePath;
        $this->chunkIndex = $chunkIndex;
        $this->mode = $mode;
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
        $deepgramKey = (string) (env('DEEPGRAM_API_KEY', '') ?: getenv('DEEPGRAM_API_KEY') ?: '');
        $hasDeepgram = trim($deepgramKey) !== '';

        // In intro enrollment mode, name detection requires speech-to-text.
        // Silently falling back to mock analysis makes the UI look "broken"
        // (no participants ever get enrolled), so we fail loudly instead.
        if ($this->mode === 'intro' && !$hasDeepgram) {
            throw new \RuntimeException('Intro enrollment requires DEEPGRAM_API_KEY (speech-to-text) to be set.');
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
        ]);
        $process->setEnv(array_merge($_SERVER, $_ENV, [
            'DEEPGRAM_API_KEY' => $deepgramKey,
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

            if ($hasDeepgram) {
                $reason = $errorFromStdout ?: ($stderr !== '' ? $stderr : ($stdout !== '' ? $stdout : 'unknown_error'));
                throw new \RuntimeException("Analyzer failed (exit={$exit}): {$reason}");
            }
            return $this->mockAnalysis();
        }

        $decoded = json_decode($process->getOutput(), true);
        if (!is_array($decoded)) {
            if ($hasDeepgram) {
                throw new \RuntimeException('Analyzer returned invalid JSON.');
            }
            return $this->mockAnalysis();
        }
        if ($hasDeepgram && isset($decoded['error'])) {
            throw new \RuntimeException('Analyzer error: ' . (string) $decoded['error']);
        }

        $totalSeconds = (int) ($decoded['total_seconds'] ?? 0);
        $speakers = is_array($decoded['speakers'] ?? null) ? $decoded['speakers'] : [];
        $overlapSeconds = (int) ($decoded['overlap_seconds'] ?? 0);
        $speakerText = is_array($decoded['speaker_text'] ?? null) ? $decoded['speaker_text'] : [];

        $normalizedSpeakers = [];
        foreach ($speakers as $name => $seconds) {
            $normalizedSpeakers[(string) $name] = (int) $seconds;
        }

        if ($hasDeepgram && count($normalizedSpeakers) === 1 && array_key_exists('Unknown', $normalizedSpeakers)) {
            throw new \RuntimeException('Analyzer did not diarize (Unknown speaker).');
        }

        if ($this->mode === 'intro' && count($speakerText) === 0) {
            throw new \RuntimeException('Intro enrollment failed: analyzer returned no transcript text (speaker_text is empty).');
        }

        return [
            'total_seconds' => max(0, $totalSeconds),
            'speakers' => $normalizedSpeakers,
            'overlap_seconds' => max(0, $overlapSeconds),
            'speaker_text' => array_map(fn ($v) => (string) $v, $speakerText),
        ];
    }

    private function applyAnalysisToStats(array $stats, array $analysis): array
    {
        $duration = max(0, (int) ($analysis['total_seconds'] ?? 0));

        $stats['total_time'] = ((int) ($stats['total_time'] ?? 0)) + $duration;
        $stats['users'] = is_array($stats['users'] ?? null) ? $stats['users'] : [];

        $analysisSpeakers = is_array($analysis['speakers'] ?? null) ? $analysis['speakers'] : [];
        $analysisSpeakerText = is_array($analysis['speaker_text'] ?? null) ? $analysis['speaker_text'] : [];

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

        return $stats;
    }

    /**
     * FIX #3: Improved name extraction.
     * - Takes the LAST match of "my name is X" to handle bleed-over from previous speaker.
     * - Captures up to 3 words to support full names (e.g. "Chandra Kant Arya").
     * - Avoids common trailing filler words.
     */
    private function extractNameFromText(string $text): ?string
    {
        $t = strtolower(trim($text));
        $t = preg_replace('/\s+/', ' ', $t) ?? $t;

        // Match "my name is X", "i am X", "this is X", "im X", "i'm X"
        // Capture up to 3 words after the phrase (letters only) to support full names.
        // We still take the LAST match to avoid bleed-over from earlier speakers.
        $matches = [];
        preg_match_all(
            '/\b(?:my name is|i am|this is|im|i\'m)\s+([a-z][a-z]{1,29}(?:\s+[a-z][a-z]{1,29}){0,2})\b/i',
            $t,
            $matches
        );

        if (empty($matches[1])) {
            return null;
        }

        // FIX: Take the LAST match — if two speakers bled into one transcript,
        // the last "my name is X" is the most recent/correct one.
        $raw = trim((string) end($matches[1]));
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

    private function buildMeetingNlp(array $speakerText): array
    {
        $full = trim(implode("\n", array_map(
            fn ($label, $t) => '[' . (string) $label . '] ' . trim((string) $t),
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

        DB::transaction(function () use ($meeting, $meetingId, $users, $participants, $crosstalkPercentage, $speakerText) {
            $isIntroMode = $this->mode === 'intro' || (string) ($meeting->status ?? '') === 'pending';

            $labelToParticipantId = [];

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

                // Find or create participant by real name.
                $named = MeetingParticipant::query()
                    ->where('meeting_id', $meetingId)
                    ->whereRaw('LOWER(name) = ?', [strtolower($realName)])
                    ->first();

                if (!$named) {
                    $named = MeetingParticipant::create([
                        'meeting_id' => $meetingId,
                        'user_id' => null,
                        'name' => $realName,
                        'voice_embedding' => $enrolledEmbedding,
                    ]);
                } else {
                    $named->update([
                        'voice_embedding' => array_merge(
                            is_array($named->voice_embedding) ? $named->voice_embedding : [],
                            $enrolledEmbedding,
                        ),
                    ]);
                }

                // Map THIS chunk's scoped label to the named participant.
                // Because label is "chunk1_speaker_0", it won't collide with "chunk0_speaker_0".
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

                $nlp = $this->buildMeetingNlp($speakerText);
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

    private function persistTranscriptToDatabase(int $meetingId, array $analysis): void
    {
        if (!Meeting::query()->whereKey($meetingId)->exists()) {
            return;
        }

        $speakerText = is_array($analysis['speaker_text'] ?? null) ? $analysis['speaker_text'] : [];
        $text = trim(implode("\n", array_map(
            fn ($label, $t) => '[' . (string) $label . '] ' . trim((string) $t),
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