<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;

class PageController extends Controller
{
    private function normalizeType(string $type): string
    {
        $type = trim($type);

        $map = [
            'terms_of_service' => 'terms',
            'terms-of-service' => 'terms',
            'tos' => 'terms',
            'privacy_policy' => 'privacy',
            'privacy-policy' => 'privacy',
            'about_us' => 'about',
            'about-us' => 'about',
        ];

        $type = $map[$type] ?? $type;

        if (!in_array($type, ['about', 'privacy', 'terms'], true)) {
            abort(404);
        }

        return $type;
    }

    public function index()
    {
        $pages = Page::query()->orderBy('type')->get();

        return view('admin.manage.pages.index', [
            'title' => 'Pages',
            'pages' => $pages,
        ]);
    }

    public function edit(string $type)
    {
        $type = $this->normalizeType($type);

        $page = Page::query()->firstOrCreate(
            ['type' => $type],
            ['title' => ucfirst(str_replace('_', ' ', $type)), 'content' => '']
        );

        return view('admin.manage.pages.edit', [
            'title' => 'Edit Page',
            'page' => $page,
        ]);
    }

    public function update(Request $request, string $type)
    {
        $type = $this->normalizeType($type);

        $data = $request->validate([
            'title' => 'required|string|max:255',
            'content' => 'nullable|string',
        ]);

        $page = Page::query()->firstOrCreate(['type' => $type]);
        $page->fill($data)->save();

        return redirect()->route('admin.pages.edit', $type)->with('status', 'Page saved.');
    }
}

