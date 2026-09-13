@props(['label', 'value', 'color' => 'text-gray-800'])

<div>
    <p class="text-xs text-gray-500">{{ $label }}</p>
    <p @class(['font-semibold text-sm sm:text-base mt-0.5', $color])>{{ $value }}</p>
</div>
