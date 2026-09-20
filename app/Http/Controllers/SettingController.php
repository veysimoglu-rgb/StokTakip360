<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Support\CompanyLogo;
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
        $logoUrl = CompanyLogo::url();

        return view('settings.edit', compact('settings', 'logoUrl'));
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'company_name' => ['nullable', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:50'],
            'company_address' => ['nullable', 'string'],
            'default_vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'currency' => ['required', Rule::in(Currency::LIST)],
            // Any aspect ratio is fine (600x180 is only a suggestion); SVG is not accepted.
            'company_logo' => [
                'nullable', 'file',
                'mimes:'.implode(',', array_merge(array_keys(CompanyLogo::TYPES), ['jpeg'])),
                'max:'.CompanyLogo::MAX_KB,
                'dimensions:max_width='.CompanyLogo::MAX_SIDE_PX.',max_height='.CompanyLogo::MAX_SIDE_PX,
            ],
            'remove_company_logo' => ['nullable', 'boolean'],
        ], [
            'company_logo.mimes' => 'Logo PNG, JPG veya WebP olmalıdır.',
            'company_logo.max' => 'Logo en fazla 2 MB olabilir.',
            'company_logo.dimensions' => 'Logo en fazla '.CompanyLogo::MAX_SIDE_PX.'x'.CompanyLogo::MAX_SIDE_PX.' piksel olabilir.',
            'company_logo.file' => 'Logo yüklenemedi, lütfen tekrar deneyin.',
            'company_logo.uploaded' => 'Logo yüklenemedi (dosya çok büyük olabilir).',
        ]);

        unset($data['company_logo'], $data['remove_company_logo']);

        foreach ($data as $key => $value) {
            Setting::set($key, $value);
        }

        if ($request->hasFile('company_logo')) {
            CompanyLogo::store($request->file('company_logo'));
        } elseif ($request->boolean('remove_company_logo')) {
            CompanyLogo::remove();
        }

        return back()->with('success', 'Ayarlar kaydedildi.');
    }
}
