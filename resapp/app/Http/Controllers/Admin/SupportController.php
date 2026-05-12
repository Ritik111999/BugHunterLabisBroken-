<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ContactMessage;
use App\Models\Faq;
use Illuminate\Http\Request;

class SupportController extends Controller
{
    public function index(Request $request)
    {
        $status = $request->query('status');

        $tickets = ContactMessage::query()
            ->when(in_array($status, ['open', 'resolved'], true), fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('admin.manage.support.index', [
            'title' => 'Support Tickets',
            'tickets' => $tickets,
            'statusFilter' => $status,
        ]);
    }

    public function show(ContactMessage $ticket)
    {
        return view('admin.manage.support.show', [
            'title' => 'Ticket',
            'ticket' => $ticket,
        ]);
    }

    public function update(Request $request, ContactMessage $ticket)
    {
        $data = $request->validate([
            'status' => 'required|in:open,resolved',
            'admin_reply' => 'nullable|string',
        ]);

        $ticket->status = $data['status'];
        $ticket->admin_reply = $data['admin_reply'] ?? null;
        $ticket->replied_at = ($ticket->admin_reply !== null && $ticket->admin_reply !== '') ? now() : null;
        $ticket->save();

        return redirect()->route('admin.support.show', $ticket)->with('status', 'Ticket updated.');
    }

    public function faqs()
    {
        return view('admin.manage.support.faqs.index', [
            'title' => 'Support FAQs',
            'faqs' => Faq::query()->orderBy('order')->orderBy('id')->get(),
        ]);
    }

    public function editFaq(Faq $faq)
    {
        return view('admin.manage.support.faqs.edit', [
            'title' => 'Edit FAQ',
            'faq' => $faq,
        ]);
    }

    public function updateFaq(Request $request, Faq $faq)
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

        return redirect()->route('admin.support.faqs.index')->with('status', 'FAQ updated.');
    }
}

