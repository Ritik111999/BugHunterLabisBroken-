<?php

namespace Tests\Unit;

use App\Support\MeetingAudioStorage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MeetingAudioStorageTest extends TestCase
{
    public function test_stores_and_reads_chunk_on_meeting_audio_disk(): void
    {
        Storage::fake('meeting_audio');
        Config::set('wechirp.storage.audio_disk', 'meeting_audio');

        $file = UploadedFile::fake()->create('chunk_0.webm', 128, 'audio/webm');
        $path = MeetingAudioStorage::putUploadedChunk($file, 42, 'chunk_0.webm');

        $this->assertStringStartsWith('42/', $path);
        Storage::disk('meeting_audio')->assertExists($path);

        $seen = MeetingAudioStorage::withLocalPath($path, function (string $abs): string {
            $this->assertFileExists($abs);

            return 'ok';
        });

        $this->assertSame('ok', $seen);
    }

    public function test_cloud_disk_downloads_to_temp_for_local_path_callback(): void
    {
        Storage::fake('s3');
        Config::set('wechirp.storage.audio_disk', 's3');
        Config::set('filesystems.disks.s3.driver', 's3');

        Storage::disk('s3')->put('99/intro_0.webm', 'fake-audio-bytes');

        $tempPaths = [];
        MeetingAudioStorage::withLocalPath('99/intro_0.webm', function (string $abs) use (&$tempPaths): void {
            $tempPaths[] = $abs;
            $this->assertFileExists($abs);
            $this->assertStringContainsString('fake-audio-bytes', (string) file_get_contents($abs));
        });

        $this->assertCount(1, $tempPaths);
        $this->assertFileDoesNotExist($tempPaths[0]);
    }
}
