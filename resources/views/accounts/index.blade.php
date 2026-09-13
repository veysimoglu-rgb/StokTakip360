<x-app-layout>
    <x-slot name="title">Cari Hesaplar</x-slot>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
        <form method="GET" class="flex flex-wrap gap-2">
            <x-text-input type="text" name="q" value="{{ request('q') }}" placeholder="Kod veya ad ara..." class="w-56" />
            <x-secondary-button type="submit">Ara</x-secondary-button>
        </form>
        @role('Admin')
        <a href="{{ route('accounts.create') }}" class="hidden sm:inline-block">
            <x-primary-button>+ Yeni Cari</x-primary-button>
        </a>
        <a href="{{ route('accounts.create') }}"
           class="sm:hidden fixed top-20 right-4 z-30 w-12 h-12 rounded-full bg-indigo-600 text-white shadow-lg flex items-center justify-center text-2xl leading-none"
           aria-label="Yeni Cari Ekle">
            +
        </a>
        @endrole
    </div>

    <div class="flex flex-wrap gap-2 mb-4 text-sm">
        @php $types = ['' => 'Tümü'] + \App\Models\Account::TYPES; @endphp
        @foreach ($types as $value => $label)
            <a href="{{ route('accounts.index', array_filter(['type' => $value ?: null, 'q' => request('q')])) }}"
               @class([
                    'px-3 py-1.5 rounded-full border',
                    'bg-indigo-600 text-white border-indigo-600' => (string) request('type') === (string) $value,
                    'bg-white text-gray-600 border-gray-300 hover:bg-gray-50' => (string) request('type') !== (string) $value,
               ])>
                {{ $label }}
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-lg shadow overflow-x-auto">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-gray-600 text-left">
                <tr>
                    <th class="px-4 py-3">Kod</th>
                    <th class="px-4 py-3">Ad</th>
                    <th class="hidden sm:table-cell px-4 py-3">Tip</th>
                    <th class="hidden sm:table-cell px-4 py-3">Telefon</th>
                    <th class="px-4 py-3 text-right">Bakiye</th>
                    <th class="hidden sm:table-cell px-4 py-3">Durum</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($accounts as $account)
                    @php $accountBalances = $balancesByAccount[$account->id] ?? collect(); @endphp
                    <tr class="hover:bg-gray-50 cursor-pointer" onclick="window.location='{{ route('accounts.show', $account) }}'">
                        <td class="px-4 py-3 font-mono text-xs">{{ $account->code }}</td>
                        <td class="px-4 py-3">
                            {{ $account->name }}
                            @if ($overdueAccountIds->contains($account->id))
                                <span class="inline-flex align-middle ms-1 text-red-600" title="Vadesi geçmiş bakiye var">
                                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 3.5h.01" />
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M10.6 4.6 2.9 18a2 2 0 0 0 1.7 3h14.8a2 2 0 0 0 1.7-3L13.4 4.6a2 2 0 0 0-2.8 0Z" />
                                    </svg>
                                </span>
                            @endif
                            <p class="sm:hidden text-xs text-gray-400 mt-0.5">{{ $account->typeLabel() }}{{ $account->phone ? ' · '.$account->phone : '' }}</p>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">
                            <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-indigo-100 text-indigo-800">{{ $account->typeLabel() }}</span>
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">{{ $account->phone ?: '-' }}</td>
                        <td class="px-4 py-3 text-right">
                            @forelse ($accountBalances as $row)
                                <div @class([
                                    'font-medium text-xs whitespace-nowrap',
                                    'text-red-600' => $row->balance > 0,
                                    'text-green-600' => $row->balance < 0,
                                    'text-gray-400' => $row->balance == 0,
                                ])>{{ $row->currency }}: {{ \App\Support\Currency::format(abs($row->balance), $row->currency) }}</div>
                            @empty
                                <span class="text-gray-400 text-xs">-</span>
                            @endforelse
                        </td>
                        <td class="hidden sm:table-cell px-4 py-3">
                            @if ($account->active)
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800">Aktif</span>
                            @else
                                <span class="inline-flex px-2 py-0.5 rounded-full text-xs bg-gray-100 text-gray-600">Pasif</span>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-4 py-6 text-center text-gray-500">Kayıt bulunamadı.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">{{ $accounts->links() }}</div>
</x-app-layout>
