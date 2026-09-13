<x-app-layout>
    <x-slot name="title">Alışlar</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <form method="GET" class="flex flex-wrap gap-2">
            <x-select-input name="status" class="w-48">
                <option value="">Tüm Durumlar</option>
                @foreach (\App\Models\Purchase::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-select-input>
            <x-secondary-button type="submit">Filtrele</x-secondary-button>
        </form>
        @role('Admin')
        <a href="{{ route('purchases.create') }}" class="hidden sm:inline-block">
            <x-primary-button>+ Yeni Alış</x-primary-button>
        </a>
        <a href="{{ route('purchases.create') }}"
           class="sm:hidden fixed top-20 right-4 z-30 w-12 h-12 rounded-full bg-indigo-600 text-white shadow-lg flex items-center justify-center text-2xl leading-none"
           aria-label="Yeni Alış">
            +
        </a>
        @endrole
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">No</th>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="hidden sm:table-cell px-4 py-3">Tedarikçi</th>
                    <th class="px-4 py-3 text-right">Tutar</th>
                    <th class="px-4 py-3">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($purchases as $purchase)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('purchases.show', $purchase) }}'">
                        <td class="px-4 py-3 font-mono text-xs">{{ $purchase->number }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ $purchase->purchase_date->format('d.m.Y') }}
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $purchase->account?->name ?? 'Genel Tedarikçi' }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $purchase->account?->name ?? 'Genel Tedarikçi' }}</td>
                        <td class="px-4 py-3 text-right font-medium">{{ \App\Support\Currency::format($purchase->total, $purchase->currency) }}</td>
                        <td class="px-4 py-3">
                            @if ($purchase->isCancelled())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">İptal Edildi</span>
                            @else
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs',
                                    'bg-green-100 text-green-800' => $purchase->status === 'paid',
                                    'bg-yellow-100 text-yellow-800' => $purchase->status === 'partial',
                                    'bg-red-100 text-red-800' => $purchase->status === 'unpaid',
                                ])>{{ $purchase->statusLabel() }}</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Henüz alış yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $purchases->links() }}</div>
</x-app-layout>
