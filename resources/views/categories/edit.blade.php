<x-app-layout>
    <x-slot name="title">Kategori Düzenle</x-slot>

    <div class="bg-white rounded-lg shadow p-6 max-w-lg">
        <form method="POST" action="{{ route('categories.update', $category) }}" class="space-y-4">
            @csrf @method('PUT')
            <div>
                <x-input-label for="name" value="Ad" />
                <x-text-input id="name" name="name" value="{{ old('name', $category->name) }}" class="w-full" required autofocus />
                <x-input-error :messages="$errors->get('name')" class="mt-1" />
            </div>

            <label class="inline-flex items-center">
                <input type="checkbox" name="active" value="1" @checked(old('active', $category->active)) class="rounded border-gray-300 text-indigo-600">
                <span class="ms-2 text-sm text-gray-700">Aktif</span>
            </label>

            <div class="flex gap-3 pt-2">
                <x-primary-button>Güncelle</x-primary-button>
                <a href="{{ route('categories.index') }}" class="text-sm text-gray-600 self-center hover:underline">Vazgeç</a>
            </div>
        </form>
    </div>
</x-app-layout>
