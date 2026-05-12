<?php

namespace App\Models;

use App\Models\MeetingAnalytic;
use App\Models\MeetingAudio;
use App\Models\MeetingParticipant;
use App\Models\ParticipantStat;
use App\Models\SpeakerMapping;
use App\Models\SpeakerSegment;
use App\Models\Transcript;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    use HasFactory;

    protected $fillable = [
        'host_id',
        'title',
        'started_at',
        'ended_at',
        'duration',
        'status',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    public function participants()
    {
        return $this->hasMany(MeetingParticipant::class);
    }

    public function audios()
    {
        return $this->hasMany(MeetingAudio::class);
    }

    public function transcripts()
    {
        return $this->hasMany(Transcript::class);
    }

    public function speakerSegments()
    {
        return $this->hasMany(SpeakerSegment::class);
    }

    public function speakerMappings()
    {
        return $this->hasMany(SpeakerMapping::class);
    }

    public function participantStats()
    {
        return $this->hasMany(ParticipantStat::class);
    }

    public function analytic()
    {
        return $this->hasOne(MeetingAnalytic::class);
    }
}