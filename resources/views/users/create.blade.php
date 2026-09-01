<x-app-layout>
    <x-slot name="title">Yeni Kullanıcı</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <form method="POST" action="{{ route('users.store') }}" class="space-y-4">
            @csrf
            <div>
                <x-input-label for="name" value="Ad Soyad" />
                <x-text-input id="name" name="name" value="{{ old('name') }}" class="w-full" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="email" value="E-posta" />
                <x-text-input id="email" type="email" name="email" value="{{ old('email') }}" class="w-full" required />
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="password" value="Şifre" />
                <x-text-input id="password" type="password" name="password" class="w-full" required />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="role" value="Rol" />
                <x-select-input id="role" name="role" class="w-full" required>
                    <option value="">Seçiniz</option>
                    @foreach ($roles as $role)
                        <option value="{{ $role->name }}" @selected(old('role') === $role->name)>{{ $role->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('role')" class="mt-1" />
            </div>

            <label class="inline-flex items-center">
                <input type="checkbox" name="active" value="1" checked class="rounded border-gray-300 text-indigo-600">
                <span class="ms-2 text-sm text-gray-700">Aktif</span>
            </label>

            <div class="flex gap-3 pt-2">
                <x-primary-button>Kaydet</x-primary-button>
                <a href="{{ route('users.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
