<?php

namespace App\Models;

use App\Models\Meeting;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transcript extends Model
{
    use HasFactory;

    protected $fillable = [
        'meeting_id',
        'text',
        'start_time',
        'end_time',
    ];

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}