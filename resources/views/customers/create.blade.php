<x-app-layout>
    <x-slot name="title">Yeni Müşteri</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <form method="POST" action="{{ route('customers.store') }}" class="space-y-4">
            @csrf
            <div>
                <x-input-label for="name" value="Ad Soyad / Ünvan" />
                <x-text-input id="name" name="name" value="{{ old('name') }}" class="w-full" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="phone" value="Telefon" />
                <x-text-input id="phone" name="phone" value="{{ old('phone') }}" class="w-full" />
            </div>
            <div>
                <x-input-label for="email" value="E-posta" />
                <x-text-input id="email" type="email" name="email" value="{{ old('email') }}" class="w-full" />
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="tax_no" value="Vergi No" />
                <x-text-input id="tax_no" name="tax_no" value="{{ old('tax_no') }}" class="w-full" />
            </div>
            <div>
                <x-input-label for="address" value="Adres" />
                <x-textarea-input id="address" name="address" rows="3">{{ old('address') }}</x-textarea-input>
            </div>

            <label class="inline-flex items-center">
                <input type="checkbox" name="active" value="1" checked class="rounded border-gray-300 text-indigo-600">
                <span class="ms-2 text-sm text-gray-700">Aktif</span>
            </label>

            <div class="flex gap-3 pt-2">
                <x-primary-button>Kaydet</x-primary-button>
                <a href="{{ route('customers.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
