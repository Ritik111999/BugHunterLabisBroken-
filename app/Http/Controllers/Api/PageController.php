<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Page;

class PageController extends Controller
{
    public function show($type)
    {
        $page = Page::where('type', $type)->firstOrFail();

        return response()->json([
            'title' => $page->title,
            'content' => $page->content,
        ]);
    }
}
