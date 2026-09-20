{{-- Header widget: [HH:mm] | USD Alış 48,61 | EUR Alış 55,80 | TCMB
     Plain CSS on purpose (no dependency on the Tailwind build).
     >= 700px: one compact line. < 700px: two lines — the clock on top, "USD 48,61  EUR 55,80" below —
     so the 64px header never grows. The clock is JavaScript-only (no server request, updates on the
     minute); the rates come from the server-side cache. --}}
<style>
    .hdr-info { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap; flex: 0 0 auto; gap: 0 .5rem; max-width: 100%; font-size: .75rem; line-height: 1rem; color: #4b5563; white-space: nowrap; }
    .hdr-clock { font-size: .875rem; font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; }
    .hdr-sep { color: #d1d5db; }
    .hdr-rate b { font-weight: 600; color: #111827; font-variant-numeric: tabular-nums; }
    .hdr-src { font-size: .6875rem; letter-spacing: .04em; color: #9ca3af; }
    .app-header-title { flex: 1 1 0; min-width: 0; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    @media (max-width: 699px) {
        .hdr-info { flex-direction: row; max-width: 11rem; }
        .hdr-clock { flex-basis: 100%; text-align: right; line-height: 1rem; }
        .hdr-sep, .hdr-src, .hdr-kind { display: none; }
        .hdr-rate { font-size: .6875rem; }
    }
    @media (max-width: 1023px) {
        .app-header-title { text-align: center; }
    }
</style>
<div class="hdr-info" data-header-info>
    <time class="hdr-clock" data-live-clock datetime="{{ $clock }}">{{ $clock }}</time>
    <span class="hdr-sep" aria-hidden="true">|</span>
    <span class="hdr-rate" data-rate="USD" title="{{ $rates['title'] }} · 1 USD = {{ $rates['usd_precise'] }} ₺"><span class="hdr-code">USD</span> <span class="hdr-kind">{{ \App\Services\ExchangeRateService::RATE_LABEL }}</span> <b>{{ $rates['usd_text'] }}</b></span>
    <span class="hdr-sep" aria-hidden="true">|</span>
    <span class="hdr-rate" data-rate="EUR" title="{{ $rates['title'] }} · 1 EUR = {{ $rates['eur_precise'] }} ₺"><span class="hdr-code">EUR</span> <span class="hdr-kind">{{ \App\Services\ExchangeRateService::RATE_LABEL }}</span> <b>{{ $rates['eur_text'] }}</b></span>
    <span class="hdr-sep" aria-hidden="true">|</span>
    <span class="hdr-src" title="Türkiye Cumhuriyet Merkez Bankası">TCMB</span>
</div>
<script>
    (function () {
        var clocks = document.querySelectorAll('[data-live-clock]');
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        function now() { var d = new Date(); return pad(d.getHours()) + ':' + pad(d.getMinutes()); }
        function paint() { var v = now(); clocks.forEach(function (el) { if (el.textContent !== v) { el.textContent = v; el.setAttribute('datetime', v); } }); }
        // Browser-side only: repaint exactly on the next minute, then every minute. No server calls.
        function tick() { paint(); setTimeout(tick, 60000 - (Date.now() % 60000) + 25); }
        tick();
        document.addEventListener('visibilitychange', function () { if (!document.hidden) { paint(); } });
    })();
</script>
