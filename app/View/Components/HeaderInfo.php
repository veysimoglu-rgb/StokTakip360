<?php

namespace App\View\Components;

use App\Services\ExchangeRateService;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Header widget: live HH:mm clock (updated by the browser) + cached TCMB USD/EUR rates.
 * Used only by the app layout header — never by receipts, PDFs or the footer.
 */
class HeaderInfo extends Component
{
    /** @var array<string, mixed> */
    public array $rates;

    /** Server-rendered fallback for the clock (Türkiye time); the browser replaces it with the device time. */
    public string $clock;

    public function __construct(ExchangeRateService $exchangeRates)
    {
        $this->rates = $exchangeRates->forHeader();
        $this->clock = now(ExchangeRateService::TIMEZONE)->format('H:i');
    }

    public function render(): View
    {
        return view('components.header-info');
    }
}
