<x-app-layout>
    <x-slot name="title">Stok Hareketleri</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <form method="GET" class="flex flex-wrap gap-2">
            <x-select-input name="type" class="w-40">
                <option value="">Tüm Hareketler</option>
                <option value="in" @selected(request('type') === 'in')>Giriş</option>
                <option value="out" @selected(request('type') === 'out')>Çıkış</option>
            </x-select-input>
            <x-select-input name="product_id" class="w-56">
                <option value="">Tüm Ürünler</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}" @selected(request('product_id') == $product->id)>{{ $product->name }}</option>
                @endforeach
            </x-select-input>
            <x-text-input type="date" name="date_from" value="{{ request('date_from') }}" />
            <x-text-input type="date" name="date_to" value="{{ request('date_to') }}" />
            <x-secondary-button type="submit">Filtrele</x-secondary-button>
        </form>
        <div class="flex gap-2">
            <a href="{{ route('stock-movements.in') }}"><x-primary-button>+ Stok Girişi</x-primary-button></a>
            <a href="{{ route('stock-movements.out') }}"><x-primary-button>+ Stok Çıkışı</x-primary-button></a>
        </div>
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Tarih</th>
                    <th class="px-4 py-3">Ürün</th>
                    <th class="px-4 py-3">Tip</th>
                    <th class="px-4 py-3 text-right">Miktar</th>
                    <th class="px-4 py-3">Cari</th>
                    <th class="px-4 py-3">Kullanıcı</th>
                    <th class="px-4 py-3">Not</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($movements as $movement)
                    <tr>
                        <td class="px-4 py-3 whitespace-nowrap">{{ $movement->movement_date->format('d.m.Y H:i') }}</td>
                        <td class="px-4 py-3">{{ $movement->product->name }}</td>
                        <td class="px-4 py-3">
                            @if ($movement->type === 'in')
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Giriş</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-800">Çıkış</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right">{{ \App\Support\Quantity::format($movement->quantity) }}</td>
                        <td class="px-4 py-3">{{ $movement->account?->name ?? '-' }}</td>
                        <td class="px-4 py-3">{{ $movement->user?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-gray-500">{{ $movement->note ?? '-' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-4 py-6 text-center text-gray-500">Kayıt bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $movements->links() }}</div>
</x-app-layout>
