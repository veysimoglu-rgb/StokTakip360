<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <title>Satış Fişi — {{ $sale->number }}</title>
    <style>
        @page { size: A5; margin: 10mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, sans-serif; font-size: 12px; color: #111; margin: 0; padding: 12px; }
        h1 { font-size: 16px; margin: 0 0 2px; }
        h2 { font-size: 13px; margin: 0 0 10px; color: #444; font-weight: normal; }
        .header { text-align: center; border-bottom: 2px solid #111; padding-bottom: 8px; margin-bottom: 10px; }
        .meta { display: flex; justify-content: space-between; margin-bottom: 10px; }
        .meta div { line-height: 1.5; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        th, td { padding: 4px 6px; font-size: 11px; text-align: left; }
        thead th { border-bottom: 1px solid #111; }
        tbody tr { border-bottom: 1px dashed #ccc; }
        .text-right { text-align: right; }
        .summary { width: 60%; margin-left: auto; }
        .summary div { display: flex; justify-content: space-between; padding: 2px 0; }
        .summary .total { font-weight: bold; font-size: 13px; border-top: 1px solid #111; margin-top: 4px; padding-top: 4px; }
        .summary .remaining { font-weight: bold; color: #b91c1c; }
        .badge { display: inline-block; padding: 3px 10px; border-radius: 4px; font-weight: bold; font-size: 11px; margin-top: 8px; }
        .badge-paid { background: #dcfce7; color: #166534; }
        .badge-partial { background: #fef9c3; color: #854d0e; }
        .badge-unpaid { background: #fee2e2; color: #991b1b; }
        .footer { margin-top: 14px; text-align: center; font-size: 10px; color: #777; }
        .no-print { margin-bottom: 12px; }
        @media print {
            .no-print { display: none; }
        }
    </style>
</head>
<body>
    <div class="no-print">
        <button onclick="window.print()">Yazdır / PDF Kaydet</button>
    </div>

    <div class="header">
        <h1>{{ config('app.name') }}</h1>
        <h2>Satış Fişi</h2>
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
            <strong>Satış No:</strong> {{ $sale->number }}
        </div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Ürün</th>
                <th class="text-right">Adet</th>
                <th class="text-right">Birim Fiyat</th>
                <th class="text-right">Tutar</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $item)
                <tr>
                    <td>{{ $item->product->name }}</td>
                    <td class="text-right">{{ $item->quantity }}</td>
                    <td class="text-right">{{ \App\Support\Currency::format($item->unit_price, $sale->currency) }}</td>
                    <td class="text-right">{{ \App\Support\Currency::format($item->line_total, $sale->currency) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <div class="summary">
        <div><span>Ara Toplam</span><span>{{ \App\Support\Currency::format($sale->subtotal, $sale->currency) }}</span></div>
        @if ($sale->discount_total > 0)
            <div><span>İskonto</span><span>-{{ \App\Support\Currency::format($sale->discount_total, $sale->currency) }}</span></div>
        @endif
        <div class="total"><span>Toplam</span><span>{{ \App\Support\Currency::format($sale->total, $sale->currency) }}</span></div>
        <div><span>Tahsil Edilen</span><span>{{ \App\Support\Currency::format($sale->paid_amount, $sale->currency) }}</span></div>
        <div class="remaining"><span>Kalan Borç</span><span>{{ $sale->remaining() > 0 ? \App\Support\Currency::format($sale->remaining(), $sale->currency) : 'Borç Yok' }}</span></div>
        @if ($sale->remaining() > 0 && $sale->due_date)
            <div><span>Vade Tarihi</span><span>{{ $sale->due_date->format('d.m.Y') }}</span></div>
        @endif
    </div>

    <div style="text-align: center;">
        @if ($sale->status === 'paid')
            <span class="badge badge-paid">TAM ÖDENDİ</span>
        @elseif ($sale->status === 'partial')
            <span class="badge badge-partial">KISMİ ÖDENDİ</span>
        @else
            <span class="badge badge-unpaid">VADELİ / ÖDENMEDİ</span>
        @endif
        @if ($sale->isCancelled())
            <span class="badge badge-unpaid">İPTAL EDİLDİ</span>
        @endif
    </div>

    <div class="footer">{{ config('app.name') }} tarafından oluşturulmuştur.</div>
</body>
</html>
