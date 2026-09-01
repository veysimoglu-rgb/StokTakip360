<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\Currency;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SettingController extends Controller
{
    private const KEYS = ['company_name', 'company_phone', 'company_address', 'default_vat_rate', 'currency'];

    public function edit()
    {
        $settings = [];
        foreach (self::KEYS as $key) {
            $settings[$key] = Setting::get($key);
        }
        $settings['currency'] = $settings['currency'] ?? 'TL';

        return view('settings.edit', compact('settings'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string'],
            'default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['required', Rule::in(Currency::LIST)],
        ]);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        return back()->with('success', 'Ayarlar kaydedildi.');
    }
}
