<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    public function redirect()
    {
        return redirect()->route('admin.settings.app');
    }

    public function app()
    {
        $settings = Setting::query()
            ->where(function ($q) {
                $q->where('key', 'like', 'app.%')
                    ->orWhere('key', 'like', 'ai.%');
            })
            ->orderBy('key')
            ->get()
            ->keyBy('key');

        $knownKeys = [
            'app.name',
            'app.base_url',
            'ai.provider',
            'ai.openai_api_key',
            'ai.openai_model',
            'ai.groq_api_key',
            'ai.groq_model',
            'ai.anthropic_api_key',
            'ai.anthropic_model',
        ];

        return view('admin.manage.settings.app', [
            'title' => 'App Settings',
            'settings' => $settings,
            'knownKeys' => $knownKeys,
        ]);
    }

    public function storeApp(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|max:255',
            'value' => 'nullable|string',
        ]);

        Setting::query()->updateOrCreate(['key' => $data['key']], ['value' => $data['value'] ?? null]);

        return redirect()->route('admin.settings.app')->with('status', 'Setting saved.');
    }

    public function featureFlags()
    {
        $flags = Setting::query()
            ->where('key', 'like', 'feature.%')
            ->orderBy('key')
            ->get();

        return view('admin.manage.settings.flags', [
            'title' => 'Feature Flags',
            'flags' => $flags,
        ]);
    }

    public function storeFlag(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|max:255',
        ]);

        $key = $data['key'];
        if (!str_starts_with($key, 'feature.')) {
            $key = 'feature.'.$key;
        }

        Setting::query()->firstOrCreate(['key' => $key], ['value' => '0']);

        return redirect()->route('admin.settings.flags')->with('status', 'Feature flag added.');
    }

    public function destroy(Setting $setting)
    {
        $setting->delete();
        return back()->with('status', 'Setting deleted.');
    }

    public function toggleFlag(Request $request)
    {
        $data = $request->validate([
            'key' => 'required|string|max:255',
        ]);

        $key = $data['key'];
        if (!str_starts_with($key, 'feature.')) {
            abort(422);
        }

        $s = Setting::query()->firstOrCreate(['key' => $key], ['value' => '0']);
        $s->value = ($s->value === '1') ? '0' : '1';
        $s->save();

        return redirect()->route('admin.settings.flags')->with('status', 'Feature flag updated.');
    }
}

