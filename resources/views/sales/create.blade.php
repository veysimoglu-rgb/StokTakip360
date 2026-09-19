<x-app-layout>
    <x-slot name="title">Yeni Sipariş</x-slot>

    @include('sales._form', ['sale' => null, 'action' => route('sales.store')])
</x-app-layout>
