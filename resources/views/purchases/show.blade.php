<x-app-layout>
    <x-slot name="title">Alış {{ $purchase->number }}</x-slot>

    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
            <div class="flex items-center gap-2 flex-wrap">
                <h2 class="text-lg font-semibold text-gray-800">{{ $purchase->number }}</h2>
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
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-800">{{ $purchase->paymentTypeLabel() }}</span>
                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-700">{{ $purchase->currency }}</span>
                @if ($purchase->isOverdue())
                    <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-800">Vadesi Geçti</span>
                @endif
            </div>
            <p class="text-sm text-gray-500 mt-1">
                {{ $purchase->purchase_date->format('d.m.Y H:i') }} · {{ $purchase->user?->name ?? '-' }}
                @if ($purchase->due_date)
                    · Vade: {{ $purchase->due_date->format('d.m.Y') }}
                @endif
            </p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('purchases.index') }}" class="text-sm text-gray-600 self-center hover:underline">← Alışlar</a>
            @role('Admin')
            @if (! $purchase->isCancelled())
                <form action="{{ route('purchases.cancel', $purchase) }}" method="POST" onsubmit="return confirm('Bu alış iptal edilsin mi? Stok, cari ve kasa hareketleri terslenecek.')">
                    @csrf
                    <button type="submit" class="text-sm text-red-600 border border-red-200 rounded-md px-3 py-1.5 hover:bg-red-50">Alışı İptal Et</button>
                </form>
            @endif
            @endrole
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <div class="bg-white rounded-lg shadow p-5 lg:col-span-1 space-y-2 text-sm h-fit">
            <h3 class="font-semibold text-gray-800 mb-2">Tedarikçi</h3>
            @if ($purchase->account)
                <p><a href="{{ route('accounts.show', $purchase->account) }}" class="text-indigo-600 hover:underline">{{ $purchase->account->name }}</a></p>
                <p class="text-gray-500">{{ $purchase->account->typeLabel() }}</p>
            @else
                <p class="text-gray-500">Genel Tedarikçi (cari yok)</p>
            @endif
            @if ($purchase->note)
                <p class="pt-2 border-t text-gray-600">{{ $purchase->note }}</p>
            @endif
        </div>

        <div class="lg:col-span-2 space-y-4">
            <div class="bg-white rounded-lg shadow overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-gray-600 text-left">
                        <tr>
                            <th class="px-4 py-3">Ürün</th>
                            <th class="px-4 py-3 text-right">Miktar</th>
                            <th class="px-4 py-3 text-right">Birim Fiyat</th>
                            <th class="px-4 py-3 text-right">Toplam</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        @foreach ($purchase->items as $item)
                            <tr>
                                <td class="px-4 py-3">{{ $item->product->name }}</td>
                                <td class="px-4 py-3 text-right">{{ \App\Support\Quantity::format($item->quantity) }}</td>
                                <td class="px-4 py-3 text-right">{{ \App\Support\Currency::format($item->unit_price, $purchase->currency) }}</td>
                                <td class="px-4 py-3 text-right">{{ \App\Support\Currency::format($item->line_total, $purchase->currency) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="bg-white rounded-lg shadow p-5 text-sm space-y-1 max-w-sm ms-auto">
                <div class="flex justify-between"><span class="text-gray-500">Ara Toplam</span><span>{{ \App\Support\Currency::format($purchase->subtotal, $purchase->currency) }}</span></div>
                <div class="flex justify-between"><span class="text-gray-500">İskonto</span><span>{{ \App\Support\Currency::format($purchase->discount_total, $purchase->currency) }}</span></div>
                <div class="flex justify-between text-base font-semibold pt-1 border-t"><span>Genel Toplam</span><span>{{ \App\Support\Currency::format($purchase->total, $purchase->currency) }}</span></div>
                <div class="flex justify-between text-green-600"><span>Ödenen</span><span>{{ \App\Support\Currency::format($purchase->paid_amount, $purchase->currency) }}</span></div>
                @if ($purchase->remaining() > 0)
                    <div class="flex justify-between text-red-600"><span>Kalan</span><span>{{ \App\Support\Currency::format($purchase->remaining(), $purchase->currency) }}</span></div>
                @endif
            </div>
        </div>
    </div>
</x-app-layout>
