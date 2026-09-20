{{-- Sidebar brand block: logo on top, "StokTakip360" under it, company (or user) name under that.
     Plain CSS on purpose (no dependency on the Tailwind build); the sidebar width (w-64) and the menu
     below are untouched. The logo is shown "contain" inside a 220x56 area — never cropped or
     stretched, any aspect ratio, transparent PNG/WebP sits directly on the dark background and a
     white-background image stays inside the box with softly rounded corners. --}}
<style>
    .sb-brand { flex: 0 0 auto; display: flex; flex-direction: column; justify-content: center; min-height: 4rem; padding: .5rem 1.25rem; border-bottom: 1px solid #1f2937; }
    .sb-brand--logo { padding-top: .875rem; padding-bottom: .75rem; }
    .sb-home { display: flex; flex-direction: column; gap: .25rem; min-width: 0; text-decoration: none; }
    .sb-logo { display: flex; align-items: center; justify-content: flex-start; width: 100%; max-width: 220px; height: 56px; overflow: hidden; }
    .sb-logo img { display: block; max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain; object-position: left center; border-radius: .375rem; }
    .sb-app { font-size: 1.125rem; font-weight: 700; line-height: 1.25; color: #fff; }
    .sb-org { display: block; margin-top: .125rem; font-size: .75rem; line-height: 1.25; color: #9ca3af; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-height: 700px) { .sb-logo { height: 44px; } }
</style>
<div class="sb-brand {{ $logoUrl ? 'sb-brand--logo' : '' }}" data-sidebar-brand>
    <a href="{{ route('dashboard') }}" class="sb-home">
        @if ($logoUrl)
            <span class="sb-logo" data-brand-logo><img src="{{ $logoUrl }}" alt="{{ $companyName !== '' ? $companyName : 'Firma logosu' }}" decoding="async" draggable="false" onerror="this.parentNode.style.display='none'"></span>
        @endif
        <span class="sb-app">StokTakip360</span>
    </a>
    @if ($subtitle !== '')
        <span class="sb-org" data-brand-subtitle title="{{ $subtitle }}">{{ $subtitle }}</span>
    @endif
</div>
