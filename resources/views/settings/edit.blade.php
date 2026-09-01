<x-app-layout>
    <x-slot name="title">Ayarlar</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <form method="POST" action="{{ route('settings.update') }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <x-input-label for="company_name" value="Firma Adı" />
                <x-text-input id="company_name" name="company_name" value="{{ old('company_name', $settings['company_name']) }}" class="w-full" />
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
