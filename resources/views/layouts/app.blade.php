<!DOCTYPE html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Ana Sayfa' }} - {{ config('app.name') }}</title>

    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#4f46e5">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js'));
        }
    </script>
</head>
<body class="font-sans antialiased bg-gray-50">
    <div x-data="{ sidebarOpen: false }" class="min-h-screen flex">

        <!-- Mobile overlay -->
        <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
             class="fixed inset-0 bg-black/40 z-30 lg:hidden"></div>

        <!-- Sidebar -->
        <aside
            class="fixed inset-y-0 left-0 z-40 w-64 bg-gray-900 text-gray-100 flex flex-col transform transition-transform -translate-x-full lg:static lg:translate-x-0"
            :class="sidebarOpen && '!translate-x-0'"
        >
            <div class="min-h-16 flex flex-col justify-center px-5 py-2 border-b border-gray-800">
                <a href="{{ route('dashboard') }}" class="text-lg font-bold text-white leading-tight">StokTakip360</a>
                @if ($companyName = \App\Models\Setting::get('company_name'))
                    <span class="text-xs text-gray-400 leading-tight truncate">{{ $companyName }}</span>
                @endif
            </div>

            <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-6 text-sm">
                <div class="space-y-1">
                    <x-sidebar-link :href="route('dashboard')" :active="request()->routeIs('dashboard')">
                        Dashboard
                    </x-sidebar-link>
                    <x-sidebar-link :href="route('products.index')" :active="request()->routeIs('products.*')">
                        Ürünler
                    </x-sidebar-link>
                </div>

                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Stok İşlemleri</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('stock-movements.in')" :active="request()->routeIs('stock-movements.in')">
                            Stok Girişi
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('stock-movements.out')" :active="request()->routeIs('stock-movements.out')">
                            Stok Çıkışı
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('stock-movements.index')" :active="request()->routeIs('stock-movements.index')">
                            Stok Hareketleri
                        </x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Sipariş</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('sales.index')" :active="request()->routeIs('sales.*')">
                            Siparişler
                        </x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Alış</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('purchases.index')" :active="request()->routeIs('purchases.*')">
                            Alışlar
                        </x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Cari</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('accounts.index')" :active="request()->routeIs('accounts.*')">
                            Cari Hesaplar
                        </x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Kasa</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('cash.index')" :active="request()->routeIs('cash.*')">
                            Kasa
                        </x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Raporlar</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('reports.index')" :active="request()->routeIs('reports.*')">
                            Raporlar
                        </x-sidebar-link>
                    </div>
                </div>

                @role('Admin')
                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Tanımlar</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('categories.index')" :active="request()->routeIs('categories.*')">
                            Kategoriler
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('brands.index')" :active="request()->routeIs('brands.*')">
                            Markalar
                        </x-sidebar-link>
                    </div>
                </div>

                <div>
                    <p class="px-3 mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Yönetim</p>
                    <div class="space-y-1">
                        <x-sidebar-link :href="route('users.index')" :active="request()->routeIs('users.*')">
                            Kullanıcılar
                        </x-sidebar-link>
                        <x-sidebar-link :href="route('settings.edit')" :active="request()->routeIs('settings.*')">
                            Ayarlar
                        </x-sidebar-link>
                    </div>
                </div>
                @endrole
            </nav>

            <div class="border-t border-gray-800 p-3">
                <div class="flex items-center justify-between px-2 py-2">
                    <div class="text-sm">
                        <p class="font-medium text-white">{{ auth()->user()->name }}</p>
                        <p class="text-gray-500 text-xs">{{ auth()->user()->getRoleNames()->first() ?? '-' }}</p>
                    </div>
                </div>
                <a href="{{ route('profile.edit') }}" class="block px-2 py-1.5 rounded text-gray-300 hover:bg-gray-800 hover:text-white">Profilim</a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="w-full text-left px-2 py-1.5 rounded text-gray-300 hover:bg-gray-800 hover:text-white">
                        Çıkış Yap
                    </button>
                </form>
            </div>
        </aside>

        <!-- Main content -->
        <div class="flex-1 flex flex-col min-w-0">
            <header class="h-16 bg-white border-b flex items-center justify-between px-4 lg:px-8">
                <button @click="sidebarOpen = true" class="lg:hidden text-gray-600 p-2.5 -m-2.5 rounded-md active:bg-gray-100">
                    <svg xmlns="http://www.w3.org/2000/svg" class="h-7 w-7" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16" />
                    </svg>
                </button>
                <h1 class="text-lg font-semibold text-gray-800">{{ $title ?? 'Ana Sayfa' }}</h1>
                <div class="w-6 lg:hidden"></div>
            </header>

            <main class="flex-1 p-4 lg:p-8">
                @if (session('success'))
                    <div class="mb-4 rounded-md bg-green-50 border border-green-200 text-green-800 px-4 py-3 text-sm">
                        {{ session('success') }}
                    </div>
                @endif
                @if (session('error'))
                    <div class="mb-4 rounded-md bg-red-50 border border-red-200 text-red-800 px-4 py-3 text-sm">
                        {{ session('error') }}
                    </div>
                @endif

                {{ $slot }}
            </main>

            <footer class="border-t bg-white px-4 lg:px-8 py-2 sm:py-3 text-center">
                <p class="text-[11px] sm:text-xs text-gray-400 truncate">
                    StokTakip360 © {{ date('Y') }} — Geliştiren: {{ \App\Models\Setting::get('company_name', 'Mikrolens Bilişim & Güvenlik Sistemleri') }}
                </p>
            </footer>
        </div>
    </div>

    <script data-enter-guard>
        // Enter must not save a data-entry form by accident (barcode scanners and tablet keyboards
        // send Enter too). In any POST form with two or more fields it moves to the next field; the
        // form is saved only through its Kaydet button. Opt out with data-enter-submit on the form.
        // Pages that handle Enter themselves (e.g. the order form) call preventDefault first and are skipped.
        document.addEventListener('keydown', function (e) {
            if (e.defaultPrevented || e.isComposing || (e.key !== 'Enter' && e.keyCode !== 13)) return;
            var t = e.target;
            var inert = ['hidden', 'submit', 'button', 'reset', 'image', 'file'];
            if (!t || t.tagName !== 'INPUT' || inert.indexOf((t.type || '').toLowerCase()) !== -1) return;
            var form = t.form;
            if (!form || String(form.getAttribute('method')).toLowerCase() !== 'post' || form.hasAttribute('data-enter-submit')) return;
            var fields = Array.prototype.filter.call(form.elements, function (el) {
                if (el.disabled || el.readOnly || el.getClientRects().length === 0) return false;
                if (el.tagName === 'SELECT' || el.tagName === 'TEXTAREA') return true;
                return el.tagName === 'INPUT' && inert.indexOf((el.type || '').toLowerCase()) === -1;
            });
            if (fields.length < 2) return;
            e.preventDefault();
            var i = fields.indexOf(t);
            if (i > -1 && i < fields.length - 1) fields[i + 1].focus();
        });
    </script>
</body>
</html>
