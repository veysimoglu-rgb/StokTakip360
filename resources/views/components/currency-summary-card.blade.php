@props(['currency'])

<div class="bg-gray-50 rounded-lg p-3">
    <h3 class="font-semibold text-gray-700 text-sm mb-2">{{ \App\Support\Currency::SYMBOLS[$currency] ?? '' }} {{ $currency }}</h3>
    <div class="grid grid-cols-2 gap-x-3 gap-y-2">
        {{ $slot }}
    </div>
</div>
