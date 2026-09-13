<x-guest-layout>
    <div class="text-center">
        <svg class="mx-auto h-12 w-12 text-red-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        </svg>

        <h1 class="mt-4 text-lg font-semibold text-gray-900">Lisans Doğrulanamadı</h1>

        <p class="mt-2 text-sm text-gray-600">
            {{ $decision['message'] ?? 'Bu kurulumun lisansı doğrulanamadı. Lütfen sistem yöneticinizle iletişime geçin.' }}
        </p>

        <p class="mt-4 text-xs text-gray-400">
            Durum kodu: {{ $decision['reason'] ?? 'unknown' }}
        </p>
    </div>
</x-guest-layout>
