<x-app-layout>
    <x-slot name="title">Yeni Alış</x-slot>

    @include('purchases._form', ['purchase' => null, 'action' => route('purchases.store')])
</x-app-layout>
