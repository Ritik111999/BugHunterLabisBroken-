<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MeetingAudio;
use Illuminate\Http\Request;

class AudioController extends Controller
{
    public function upload(Request $request)
    {
        $path = $request->file('audio')->store('audios');

        $audio = MeetingAudio::create([
            'meeting_id' => $request->meeting_id,
            'file_path' => $path
        ]);

        return response()->json($audio);
    }
}
