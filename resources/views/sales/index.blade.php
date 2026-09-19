<x-app-layout>
    <x-slot name="title">Siparişler</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <form method="GET" class="flex flex-wrap gap-2">
            <x-select-input name="status" class="w-48">
                <option value="">Tüm Durumlar</option>
                @foreach (\App\Models\Sale::STATUSES as $value => $label)
                    <option value="{{ $value }}" @selected(request('status') === $value)>{{ $label }}</option>
                @endforeach
            </x-select-input>
            <x-secondary-button type="submit">Filtrele</x-secondary-button>
        </form>
        @role('Admin')
        <a href="{{ route('sales.create') }}" class="hidden sm:inline-block">
            <x-primary-button>+ Yeni Sipariş</x-primary-button>
        </a>
        <a href="{{ route('sales.create') }}"
           class="sm:hidden fixed top-20 right-4 z-30 w-12 h-12 rounded-full bg-indigo-600 text-white shadow-lg flex items-center justify-center text-2xl leading-none"
           aria-label="Yeni Sipariş">
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
                    <th class="hidden sm:table-cell px-4 py-3">Müşteri</th>
                    <th class="px-4 py-3 text-right">Tutar</th>
                    <th class="px-4 py-3">Durum</th>
                    @role('Admin')
                        <th class="px-4 py-3"></th>
                    @endrole
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($sales as $sale)
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('sales.show', $sale) }}'">
                        <td class="px-4 py-3 font-mono text-xs">{{ $sale->number }}</td>
                        <td class="px-4 py-3 whitespace-nowrap">
                            {{ $sale->sale_date->format('d.m.Y') }}
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $sale->account?->name ?? 'Genel Müşteri' }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $sale->account?->name ?? 'Genel Müşteri' }}</td>
                        <td class="px-4 py-3 text-right font-medium">{{ \App\Support\Currency::format($sale->total, $sale->currency) }}</td>
                        <td class="px-4 py-3">
                            @if ($sale->isCancelled())
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">İptal Edildi</span>
                            @else
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs',
                                    'bg-green-100 text-green-800' => $sale->status === 'paid',
                                    'bg-yellow-100 text-yellow-800' => $sale->status === 'partial',
                                    'bg-red-100 text-red-800' => $sale->status === 'unpaid',
                                ])>{{ $sale->statusLabel() }}</span>
                            @endif
                        </td>
                        @role('Admin')
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                @if (! $sale->isCancelled())
                                    <a href="{{ route('sales.edit', $sale) }}" onclick="event.stopPropagation()" class="text-indigo-600 hover:underline text-xs">Düzenle</a>
                                @endif
                            </td>
                        @endrole
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Henüz sipariş yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $sales->links() }}</div>
</x-app-layout>
