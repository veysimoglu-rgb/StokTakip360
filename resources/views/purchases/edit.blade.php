<x-app-layout>
    <x-slot name="title">Alışı Düzenle {{ $purchase->number }}</x-slot>

    <div class="max-w-4xl mb-4 rounded-lg border border-indigo-200 bg-indigo-50 p-4 text-sm text-indigo-900">
        <p class="font-semibold">{{ $purchase->number }} numaralı alış düzenleniyor.</p>
        <p class="mt-1">Kaydettiğinizde stok, cari ve kasa hareketleri yeniden oluşturulmaz; eski hareketler ters kayıtla kapanır ve yeni değerler için güncel hareketler yazılır. Net etki yalnızca aradaki farktır. Alış numarası ve tarihi değişmez; sonradan yapılan ödemeler korunur.</p>
    </div>

    @include('purchases._form', ['action' => route('purchases.update', $purchase)])
</x-app-layout>
