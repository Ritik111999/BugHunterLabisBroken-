<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Faq;
use Illuminate\Http\Request;

class FaqController extends Controller
{
    public function index()
    {
        return view('admin.manage.faqs.index', [
            'title' => 'FAQs',
            'faqs' => Faq::query()->orderBy('order')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = (bool) ($request->input('is_active', false));
        $data['order'] = $data['order'] ?? 0;

        Faq::query()->create($data);

        return redirect()->route('admin.faqs.index')->with('status', 'FAQ created.');
    }

    public function update(Request $request, Faq $faq)
    {
        $data = $request->validate([
            'question' => 'required|string|max:500',
            'answer' => 'required|string',
            'order' => 'nullable|integer',
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = (bool) ($request->input('is_active', false));
        $data['order'] = $data['order'] ?? 0;

        $faq->fill($data)->save();

        return redirect()->route('admin.faqs.index')->with('status', 'FAQ updated.');
    }

    public function destroy(Faq $faq)
    {
        $faq->delete();
        return redirect()->route('admin.faqs.index')->with('status', 'FAQ deleted.');
    }
}

