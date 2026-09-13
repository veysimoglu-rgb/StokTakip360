<x-app-layout>
    <x-slot name="title">Cari Hesap Düzenle</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <form method="POST" action="{{ route('accounts.update', $account) }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <x-input-label value="Cari Kodu" />
                <p class="mt-1 font-mono text-sm text-gray-500">{{ $account->code }}</p>
            </div>
            <div>
                <x-input-label for="type" value="Cari Tipi" />
                <x-select-input id="type" name="type" class="w-full">
                    @foreach (\App\Models\Account::TYPES as $value => $label)
                        <option value="{{ $value }}" @selected(old('type', $account->type) === $value)>{{ $label }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('type')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="name" value="Firma / Kişi Adı" />
                <x-text-input id="name" name="name" value="{{ old('name', $account->name) }}" class="w-full" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="contact_person" value="Yetkili Kişi" />
                <x-text-input id="contact_person" name="contact_person" value="{{ old('contact_person', $account->contact_person) }}" class="w-full" />
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="phone" value="Telefon" />
                    <x-text-input id="phone" name="phone" value="{{ old('phone', $account->phone) }}" class="w-full" />
                </div>
                <div>
                    <x-input-label for="email" value="E-posta" />
                    <x-text-input id="email" type="email" name="email" value="{{ old('email', $account->email) }}" class="w-full" />
                    <x-input-error :messages="$errors->get('email')" class="mt-1" />
                </div>
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <x-input-label for="tax_office" value="Vergi Dairesi" />
                    <x-text-input id="tax_office" name="tax_office" value="{{ old('tax_office', $account->tax_office) }}" class="w-full" />
                </div>
                <div>
                    <x-input-label for="tax_no" value="Vergi Numarası" />
                    <x-text-input id="tax_no" name="tax_no" value="{{ old('tax_no', $account->tax_no) }}" class="w-full" />
                </div>
            </div>
            <div>
                <x-input-label for="address" value="Adres" />
                <x-textarea-input id="address" name="address" rows="2">{{ old('address', $account->address) }}</x-textarea-input>
            </div>
            <div>
                <x-input-label for="note" value="Not" />
                <x-textarea-input id="note" name="note" rows="2">{{ old('note', $account->note) }}</x-textarea-input>
            </div>

            <label class="inline-flex items-center">
                <input type="checkbox" name="active" value="1" @checked(old('active', $account->active)) class="rounded border-gray-300 text-indigo-600">
                <span class="ms-2 text-sm text-gray-700">Aktif</span>
            </label>

            <div class="flex gap-3 pt-2">
                <x-primary-button>Güncelle</x-primary-button>
                <a href="{{ route('accounts.show', $account) }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>

        <form method="POST" action="{{ route('accounts.destroy', $account) }}" class="mt-6 pt-6 border-t" onsubmit="return confirm('Bu cari hesap silinsin mi?')">
            @csrf @method('DELETE')
            <button type="submit" class="text-sm text-red-600 hover:underline">Cari Hesabı Sil</button>
        </form>
    </div>
</x-app-layout>
