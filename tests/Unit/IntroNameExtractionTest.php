<?php

namespace Tests\Unit;

use App\Jobs\ProcessChunkJob;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class IntroNameExtractionTest extends TestCase
{
    private function extractIntroName(string $text): ?string
    {
        $job = new ProcessChunkJob(meetingId: 1, mode: 'intro');
        $method = new ReflectionMethod(ProcessChunkJob::class, 'extractNameFromText');
        $method->setAccessible(true);

        return $method->invoke($job, $text);
    }

    public function test_plain_single_word_name_is_accepted(): void
    {
        $this->assertSame('Test', $this->extractIntroName('Test'));
        $this->assertSame('Ritik', $this->extractIntroName('Ritik'));
    }

    public function test_my_name_is_phrase_still_works(): void
    {
        $this->assertSame('Ritik', $this->extractIntroName('My name is Ritik'));
        $this->assertSame('Test', $this->extractIntroName('my name is test'));
    }

    public function test_greeting_only_is_rejected(): void
    {
        $this->assertNull($this->extractIntroName('hi'));
        $this->assertNull($this->extractIntroName('hello'));
    }

    public function test_hi_name_strips_greeting(): void
    {
        $this->assertSame('Ritik', $this->extractIntroName('Hi Ritik'));
    }
}
