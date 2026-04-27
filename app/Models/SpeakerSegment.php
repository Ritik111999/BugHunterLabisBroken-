<?php

namespace App\Models;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SpeakerSegment extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'speaker_label',
        'start_time',
        'end_time',
        'is_overlap',
    ];

    protected $casts = [
        'is_overlap' => 'boolean',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}