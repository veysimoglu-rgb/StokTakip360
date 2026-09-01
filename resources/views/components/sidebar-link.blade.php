@props(['href', 'active' => false])

<a href="{{ $href }}"
   @class([
        'flex items-center px-3 py-2 rounded-md transition',
        'bg-indigo-600 text-white' => $active,
        'text-gray-300 hover:bg-gray-800 hover:text-white' => ! $active,
   ])
>
    {{ $slot }}
</a>
