<?php

namespace App\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Meeting intro + HTTP chunk files. Use WECHIRP_AUDIO_DISK=s3 in production.
 * Live relay temp buffers stay on the local disk (see DeepgramLiveRelay).
 */
class MeetingAudioStorage
{
    public static function diskName(): string
    {
        $disk = strtolower(trim((string) config('wechirp.storage.audio_disk', 'meeting_audio')));

        return $disk !== '' ? $disk : 'meeting_audio';
    }

    public static function disk(): Filesystem
    {
        return Storage::disk(self::diskName());
    }

    public static function isCloudDisk(): bool
    {
        $driver = (string) config('filesystems.disks.'.self::diskName().'.driver', 'local');

        return $driver !== 'local';
    }

    public static function chunkDirectory(int $meetingId): string
    {
        // S3 disk uses filesystems.disks.s3.root = WECHIRP_AUDIO_PREFIX; path is {meetingId}/file
        return (string) $meetingId;
    }

    public static function putUploadedChunk(UploadedFile $file, int $meetingId, string $filename): string
    {
        $directory = self::chunkDirectory($meetingId);

        return (string) self::disk()->putFileAs($directory, $file, $filename);
    }

    /**
     * Run a callback with a local filesystem path (downloads cloud objects to a temp file).
     *
     * @template T
     *
     * @param  callable(string): T  $callback
     * @return T
     */
    public static function withLocalPath(string $relativePath, callable $callback): mixed
    {
        $relativePath = ltrim($relativePath, '/');
        $disk = self::disk();

        if (! self::isCloudDisk()) {
            return $callback($disk->path($relativePath));
        }

        if (! $disk->exists($relativePath)) {
            throw new \RuntimeException("Meeting audio not found: {$relativePath}");
        }

        $ext = pathinfo($relativePath, PATHINFO_EXTENSION);
        $tmpBase = tempnam(sys_get_temp_dir(), 'wc_audio_');
        $tmpPath = $ext !== '' && $ext !== false ? "{$tmpBase}.{$ext}" : $tmpBase;
        if ($tmpPath !== $tmpBase && is_file($tmpBase)) {
            @unlink($tmpBase);
        }

        try {
            $stream = $disk->readStream($relativePath);
            if ($stream === false) {
                throw new \RuntimeException("Could not read meeting audio: {$relativePath}");
            }
            $out = fopen($tmpPath, 'wb');
            if ($out === false) {
                if (is_resource($stream)) {
                    fclose($stream);
                }
                throw new \RuntimeException('Could not create temp audio file');
            }
            stream_copy_to_stream($stream, $out);
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }

            return $callback($tmpPath);
        } finally {
            if (is_file($tmpPath)) {
                @unlink($tmpPath);
            }
        }
    }
}
