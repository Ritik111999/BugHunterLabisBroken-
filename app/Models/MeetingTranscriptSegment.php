<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingTranscriptSegment extends Model
{
    protected $fillable = [
        'meeting_id',
        'participant_id',
        'speaker_name',
        'speaker_label',
        'text',
        'start_ms',
        'end_ms',
        'is_final',
    ];

    protected function casts(): array
    {
        return [
            'start_ms' => 'integer',
            'end_ms' => 'integer',
            'is_final' => 'boolean',
        ];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class);
    }
}
