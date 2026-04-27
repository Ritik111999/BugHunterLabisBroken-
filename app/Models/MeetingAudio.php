<?php

namespace App\Models;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MeetingAudio extends Model

{
    protected $table = 'meeting_audios'; 
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'file_path',
        'duration',
        'is_processed',
    ];

    protected $casts = [
        'is_processed' => 'boolean',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}