<x-app-layout>
    <x-slot name="title">Siparişi Düzenle {{ $sale->number }}</x-slot>

    <div class="max-w-4xl mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
        <p class="font-semibold">{{ $sale->number }} numaralı sipariş düzenleniyor.</p>
        <p class="mt-1">Kaydettiğinizde stok, cari ve kasa hareketleri yeniden oluşturulmaz; eski hareketler ters kayıtla kapatılır ve yeni değerler için güncel hareketler yazılır. Net etki yalnızca aradaki farktır.</p>
    </div>

    @include('sales._form', ['action' => route('sales.update', $sale)])
</x-app-layout>
