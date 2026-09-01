<x-app-layout>
    <x-slot name="title">Kullanıcı Düzenle</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <form method="POST" action="{{ route('users.update', $user) }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <x-input-label for="name" value="Ad Soyad" />
                <x-text-input id="name" name="name" value="{{ old('name', $user->name) }}" class="w-full" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="email" value="E-posta" />
                <x-text-input id="email" type="email" name="email" value="{{ old('email', $user->email) }}" class="w-full" required />
                <x-input-error :messages="$errors->get('email')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="password" value="Yeni Şifre (opsiyonel)" />
                <x-text-input id="password" type="password" name="password" class="w-full" />
                <x-input-error :messages="$errors->get('password')" class="mt-1" />
            </div>
            <div>
                <x-input-label for="role" value="Rol" />
                <x-select-input id="role" name="role" class="w-full" required>
                    @foreach ($roles as $role)
                        <option value="{{ $role->name }}" @selected(old('role', $user->roles->first()?->name) === $role->name)>{{ $role->name }}</option>
                    @endforeach
                </x-select-input>
                <x-input-error :messages="$errors->get('role')" class="mt-1" />
            </div>

            <label class="inline-flex items-center">
                <input type="checkbox" name="active" value="1" @checked(old('active', $user->active)) class="rounded border-gray-300 text-indigo-600">
                <span class="ms-2 text-sm text-gray-700">Aktif</span>
            </label>

            <div class="flex gap-3 pt-2">
                <x-primary-button>Güncelle</x-primary-button>
                <a href="{{ route('users.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
