<x-app-layout>
    <x-slot name="title">Ayarlar</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <form method="POST" action="{{ route('settings.update') }}" enctype="multipart/form-data" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <x-input-label for="company_name" value="Firma Adı" />
                <x-text-input id="company_name" name="company_name" value="{{ old('company_name', $settings['company_name']) }}" class="w-full" />
            </div>
            <div>
                <x-input-label for="company_logo" value="Firma Logosu" />
                @if ($logoUrl)
                    <div class="mt-2 mb-3 rounded-md bg-gray-900 p-3 inline-block max-w-full" data-logo-preview>
                        <img src="{{ $logoUrl }}" alt="Mevcut logo" style="display:block;max-width:220px;max-height:56px;width:auto;height:auto;object-fit:contain">
                    </div>
                @endif
                <input id="company_logo" type="file" name="company_logo" accept="image/png,image/jpeg,image/webp" class="mt-1 block w-full text-sm text-gray-600 file:mr-3 file:rounded-md file:border-0 file:bg-gray-100 file:px-3 file:py-2 file:text-sm file:font-medium file:text-gray-700 hover:file:bg-gray-200" />
                <p class="mt-1 text-xs text-gray-500">Sol menünün üstünde gösterilir. PNG, JPG veya WebP, en fazla 2 MB. Şeffaf PNG/WebP önerilir; önerilen oran yaklaşık 600×180 px (3,3:1) ama zorunlu değildir — logo kırpılmadan, oranı korunarak sığdırılır.</p>
                <x-input-error :messages="$errors->get('company_logo')" class="mt-1" />
                @if ($logoUrl)
                    <label class="mt-2 inline-flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" name="remove_company_logo" value="1" class="rounded border-gray-300"> Mevcut logoyu kaldır
                    </label>
                @endif
            </div>
            <div>
                <x-input-label for="company_phone" value="Telefon" />
                <x-text-input id="company_phone" name="company_phone" value="{{ old('company_phone', $settings['company_phone']) }}" class="w-full" />
            </div>
            <div>
                <x-input-label for="company_address" value="Adres" />
                <x-textarea-input id="company_address" name="company_address" rows="3">{{ old('company_address', $settings['company_address']) }}</x-textarea-input>
            </div>
            <div>
                <x-input-label for="default_vat_rate" value="Varsayılan KDV (%)" />
                <x-text-input id="default_vat_rate" type="number" step="0.01" min="0" max="100" name="default_vat_rate" value="{{ old('default_vat_rate', $settings['default_vat_rate'] ?? 20) }}" class="w-full" />
            </div>
            <div>
                <x-input-label for="currency" value="Varsayılan Para Birimi" />
                <x-select-input id="currency" name="currency" class="w-full">
                    @foreach (\App\Support\Currency::LIST as $code)
                        <option value="{{ $code }}" @selected(old('currency', $settings['currency']) === $code)>
                            {{ $code }} ({{ \App\Support\Currency::SYMBOLS[$code] }})
                        </option>
                    @endforeach
                </x-select-input>
            </div>

            <div class="flex gap-3 pt-2">
                <x-primary-button>Kaydet</x-primary-button>
            </div>
        </form>
    </div>
</x-app-layout>
