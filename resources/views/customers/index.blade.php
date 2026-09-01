<x-app-layout>
    <x-slot name="title">Müşteriler</x-slot>

    <div class="flex items-center justify-between mb-4">
        <form method="GET" class="flex gap-2">
            <x-text-input type="text" name="q" value="{{ request('q') }}" placeholder="Müşteri ara..." class="w-64" />
            <x-secondary-button type="submit">Ara</x-secondary-button>
        </form>
        <a href="{{ route('customers.create') }}">
            <x-primary-button>+ Yeni Müşteri</x-primary-button>
        </a>
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Ad</th>
                    <th class="px-4 py-3">Telefon</th>
                    <th class="px-4 py-3">E-posta</th>
                    <th class="px-4 py-3">Durum</th>
                    <th class="px-4 py-3 text-right">İşlemler</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($customers as $customer)
                    <tr>
                        <td class="px-4 py-3">{{ $customer->name }}</td>
                        <td class="px-4 py-3">{{ $customer->phone ?: '-' }}</td>
                        <td class="px-4 py-3">{{ $customer->email ?: '-' }}</td>
                        <td class="px-4 py-3">
                            @if ($customer->active)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Aktif</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Pasif</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right space-x-2">
                            <a href="{{ route('customers.edit', $customer) }}" class="text-indigo-600 hover:underline">Düzenle</a>
                            <form action="{{ route('customers.destroy', $customer) }}" method="POST" class="inline" onsubmit="return confirm('Silinsin mi?')">
                                @csrf @method('DELETE')
                                <button type="submit" class="text-red-600 hover:underline">Sil</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Kayıt bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $customers->links() }}</div>
</x-app-layout>
