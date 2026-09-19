<x-app-layout>
    <x-slot name="title">Kritik Stok Raporu</x-slot>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Kod</th>
                    <th class="px-4 py-3">Ürün Adı</th>
                    <th class="px-4 py-3">Kategori</th>
                    <th class="px-4 py-3 text-right">Güncel Stok</th>
                    <th class="px-4 py-3 text-right">Min. Stok</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($products as $product)
                    <tr>
                        <td class="px-4 py-3 font-mono text-xs">{{ $product->code }}</td>
                        <td class="px-4 py-3">{{ $product->name }}</td>
                        <td class="px-4 py-3">{{ $product->category?->name ?? '-' }}</td>
                        <td class="px-4 py-3 text-right text-red-600 font-medium">{{ \App\Support\Quantity::format($product->current_stock) }} {{ $product->unit }}</td>
                        <td class="px-4 py-3 text-right">{{ \App\Support\Quantity::format($product->min_stock) }} {{ $product->unit }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-6 text-center text-gray-500">Kritik seviyede ürün yok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $products->links() }}</div>
</x-app-layout>
