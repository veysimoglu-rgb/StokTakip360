<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Sipariş Fişi — {{ $sale->number }}</title>
    <style>
        /* Margin 0 makes browsers omit their own print header/footer (date, page title, URL);
           the 10mm spacing is reproduced as body padding when printing. */
        @page { size: A5; margin: 0; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 12px; }
        h1 { font-size: 16px; margin: 0 0 2px; text-transform: uppercase; }
        h2 { font-size: 13px; margin: 0 0 4px; color: #444; font-weight: normal; text-transform: uppercase; }
        .disclaimer { font-size: 9px; color: #777; margin: 0; }
        .header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        .meta { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .meta div { line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { padding: 4px 6px; font-size: 11px; text-align: left; vertical-align: top; }
        thead th { border-bottom: 1px solid #111; }
        tbody tr { border-bottom: 1px dashed #ccc; }
        .text-right { text-align: right; }
        .code { width: 1%; white-space: nowrap; font-size: 10px; }
        .sub { display: block; font-size: 9px; color: #666; }
        .sub-warn { display: block; font-size: 9px; color: #b91c1c; }
        .summary { width: 65%; margin-left: auto; }
        .summary div { display: flex; justify-content: space-between; padding: 2px 0; }
        .summary .total { font-weight: bold; font-size: 13px; border-top: 1px solid #111; margin-top: 4px; padding-top: 4px; }
        .summary .grand { font-weight: bold; font-size: 13px; border-top: 1px solid #111; margin-top: 4px; padding-top: 4px; }
        .summary .weight { border-top: 1px dashed #999; margin-top: 6px; padding-top: 4px; color: #333; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-weight: bold; font-size: 11px; margin-top: 8px; background: #fee2e2; color: #991b1b; }
        .no-print { margin-bottom: 12px; }
        @media print {
            .no-print { display: none; }
            body { padding: calc(10mm + 12px); }
        }
    </style>
</head>
<body>
    @php
        $hasWeight = $sale->items->contains(fn ($i) => $i->line_weight_kg !== null);
        $carried = $sale->carriedBalance();
    @endphp

    <div class="no-print">
        <button onclick="window.print()">Yazdır / PDF Kaydet</button>
    </div>

    <div class="header">
        <h1>{{ $sale->account?->name ?? 'Genel Müşteri' }}</h1>
        <h2>Sipariş Fişi</h2>
        <p class="disclaimer">Bu belge resmi fatura veya irsaliye yerine geçmez.</p>
    </div>

    <div class="meta">
        <div>
            <strong>Müşteri:</strong> {{ $sale->account?->name ?? 'Genel Müşteri' }}<br>
            @if ($sale->account?->phone)
                <strong>Telefon:</strong> {{ $sale->account->phone }}<br>
            @endif
        </div>
        <div class="text-right">
            <strong>Tarih:</strong> {{ $sale->sale_date->format('d.m.Y') }}<br>
            <strong>Sipariş No:</strong> {{ $sale->number }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th class="code">Ürün Kodu</th>
                <th>Ürün</th>
                <th class="text-right">Miktar</th>
                <th class="text-right">Birim Fiyat</th>
                <th class="text-right">Tutar</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td class="code">{{ $item->product->code }}</td>
                    <td>{{ $item->product->name }}</td>
                    <td class="text-right">
                        @if ($item->package_qty_input !== null)
                            {{ \App\Support\Quantity::format($item->package_qty_input) }} {{ $item->product->package_label ?: 'Paket' }}
                            <span class="sub">{{ \App\Support\Quantity::format($item->quantity) }} {{ $item->product->unit }}{{ $item->line_weight_kg !== null ? ' · '.\App\Support\Quantity::format($item->line_weight_kg).' kg' : '' }}</span>
                        @else
                            {{ \App\Support\Quantity::format($item->quantity) }} {{ $item->product->unit }}
                        @endif
                        @if ((float) $item->stock_shortfall_quantity > 0)
                            <span class="sub-warn">{{ \App\Support\Quantity::format($item->stock_shortfall_quantity) }} stokta karşılanamadı</span>
                        @endif
                    </td>
                    <td class="text-right">{{ \App\Support\Currency::format($item->unit_price, $sale->currency) }}</td>
                    <td class="text-right">{{ \App\Support\Currency::format($item->line_total, $sale->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <div class="total"><span>Sipariş Toplamı</span><span>{{ \App\Support\Currency::format($sale->total, $sale->currency) }}</span></div>
        @if ($carried !== null)
            <div><span>Devreden Bakiye</span><span>{{ \App\Support\Currency::format($carried, $sale->currency) }}</span></div>
            <div class="grand"><span>Toplam Bakiye</span><span>{{ \App\Support\Currency::format($carried + (float) $sale->total, $sale->currency) }}</span></div>
        @endif
        @if ($hasWeight)
            <div class="weight"><span>Toplam Ağırlık</span><span>{{ \App\Support\Quantity::format($sale->totalWeightKg()) }} kg</span></div>
        @endif
    </div>

    @if ($sale->isCancelled())
        <div style="text-align: center;"><span class="badge">İPTAL EDİLDİ</span></div>
    @endif

</body>
</html>
