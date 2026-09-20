<?php

namespace App\Services;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * USD / EUR rates for the header, from the official TCMB daily bulletin
 * (https://www.tcmb.gov.tr/kurlar/today.xml).
 *
 * Which rate: "Döviz Alış" = the <ForexBuying> column (RATE_FIELD), shown as "USD Alış" / "EUR Alış".
 *
 * How often: TCMB is checked at most TWICE a day — at the first page view after ~09:00 and after
 * ~16:30 Türkiye time (CHECK_TIMES). TCMB fixes the indicative rates on business days around 15:30,
 * so the 09:00 check picks up the previous business day's bulletin and the 16:30 check the current
 * one. Checks are accounted per day-slot (checked_slot), not per timestamp: a page view before 09:00
 * only ever fetches when nothing has ever been fetched, and that first fetch is credited to the 09:00
 * slot, so the day still has just the 09:00 and 16:30 slots. Saturdays and Sundays have no slots at
 * all (TCMB publishes nothing new), which also keeps the request count down. Everything is cached
 * once for all users and rendered from the cache. No cron, queue or polling: the check is triggered
 * lazily by the first request after a slot (after the response is sent, or — only when nothing has
 * ever been fetched — during that one request with a short timeout).
 *
 * A check is counted the moment it starts, so a failing/slow TCMB is not retried before the next
 * slot. Weekends/holidays: TCMB keeps serving the last bulletin or errors;
 * either way the last successful rates stay in place. Any failure is swallowed (logged only) — the
 * header shows the last good values, or "—" when there never were any.
 */
class ExchangeRateService
{
    public const CACHE_KEY = 'tcmb:rates:v1';

    /** TCMB column shown in the header: Döviz Alış. */
    public const RATE_FIELD = 'ForexBuying';

    public const RATE_LABEL = 'Alış';

    public const CURRENCIES = ['USD', 'EUR'];

    /** Türkiye time. At most one check per slot => at most two per day. */
    public const CHECK_TIMES = ['09:00', '16:30'];

    public const TIMEZONE = 'Europe/Istanbul';

    public function enabled(): bool
    {
        return (bool) config('services.tcmb.enabled', true);
    }

    /**
     * What the header renders. Never throws.
     *
     * @return array{usd: ?float, eur: ?float, usd_text: string, eur_text: string, usd_precise: string, eur_precise: string, rate_date: ?string, checked_at: ?string, title: string}
     */
    public function forHeader(): array
    {
        try {
            $state = $this->state();

            if ($this->enabled() && $this->isDue($state)) {
                if ($state === null) {
                    // Nothing was ever fetched: the first page waits (short timeout) so it can show rates.
                    $this->refresh();
                    $state = $this->state();
                } else {
                    // Rates already on screen: refresh after the response has been sent.
                    app()->terminating(fn () => $this->refresh());
                }
            }

            return $this->present($state);
        } catch (Throwable $e) {
            Log::warning('TCMB rates: header data unavailable: '.$e->getMessage());

            return $this->present(null);
        }
    }

    /**
     * Whether a check is due: the current day-slot has not been checked yet. Before the first slot of
     * the day and on weekends nothing is due (except the very first fetch, when there is no state).
     */
    public function isDue(?array $state, ?CarbonInterface $now = null): bool
    {
        if ($state === null || ! isset($state['checked_slot'])) {
            return true;
        }

        $slot = $this->currentSlot($now ?? now());

        return $slot !== null && $slot->format('Y-m-d H:i') > $state['checked_slot'];
    }

    /**
     * The latest check slot of the current Türkiye day at or before $now; null before the first slot
     * and on Saturdays/Sundays.
     */
    public function currentSlot(CarbonInterface $now): ?CarbonInterface
    {
        $local = Carbon::instance($now)->timezone(self::TIMEZONE);

        if ($local->isWeekend()) {
            return null;
        }

        foreach (array_reverse(self::CHECK_TIMES) as $time) {
            $slot = $local->copy()->setTimeFromTimeString($time)->setSecond(0);

            if ($slot->lte($local)) {
                return $slot;
            }
        }

        return null;
    }

    /**
     * The slot a check made right now is credited to: the current slot; before the day's first slot
     * (or on a weekend) an early/idle fetch is credited to the first slot of the day / the last slot
     * of the previous business day, so it never adds a third check to a day.
     */
    public function slotToCredit(CarbonInterface $now): CarbonInterface
    {
        $local = Carbon::instance($now)->timezone(self::TIMEZONE);
        $slot = $this->currentSlot($local);

        if ($slot !== null) {
            return $slot;
        }

        if (! $local->isWeekend()) {
            return $local->copy()->setTimeFromTimeString(self::CHECK_TIMES[0])->setSecond(0);
        }

        $day = $local->copy();

        do {
            $day->subDay();
        } while ($day->isWeekend());

        return $day->setTimeFromTimeString(self::CHECK_TIMES[array_key_last(self::CHECK_TIMES)])->setSecond(0);
    }

    /**
     * Runs one check if (and only if) it is due. Safe to call concurrently: a cache lock lets exactly
     * one request talk to TCMB. Returns true when fresh rates were stored.
     */
    public function refresh(): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $lock = Cache::lock(self::CACHE_KEY.':lock', 30);

            if (! $lock->get()) {
                return false;
            }
        } catch (Throwable $e) {
            Log::warning('TCMB rates: could not take the refresh lock: '.$e->getMessage());

            return false;
        }

        try {
            $state = $this->state();

            if (! $this->isDue($state)) {
                return false;
            }

            $state = ($state ?? []) + ['usd' => null, 'eur' => null, 'rate_date' => null, 'fetched_at' => null];
            // Count the check before talking to TCMB: a failure or timeout is not retried until the next slot.
            $state['checked_at'] = now()->toIso8601String();
            $state['checked_slot'] = $this->slotToCredit(now())->format('Y-m-d H:i');
            $this->store($state);

            $fresh = $this->fetch();

            if ($fresh === null) {
                return false;
            }

            // Never replace a newer bulletin with an older one.
            if ($state['rate_date'] !== null && $fresh['rate_date'] < $state['rate_date']) {
                return false;
            }

            $this->store([
                'usd' => $fresh['usd'],
                'eur' => $fresh['eur'],
                'rate_date' => $fresh['rate_date'],
                'fetched_at' => now()->toIso8601String(),
                'checked_at' => $state['checked_at'],
                'checked_slot' => $state['checked_slot'],
            ]);

            return true;
        } catch (Throwable $e) {
            Log::warning('TCMB rates: refresh failed, keeping the last rates: '.$e->getMessage());

            return false;
        } finally {
            $lock->release();
        }
    }

    /**
     * The single outbound request. Returns null on any problem.
     *
     * @return array{usd: float, eur: float, rate_date: string}|null
     */
    private function fetch(): ?array
    {
        $response = Http::withUserAgent('StokTakip360')
            ->connectTimeout(2)
            ->timeout(max(1, (int) config('services.tcmb.timeout', 3)))
            ->get((string) config('services.tcmb.url'));

        if (! $response->successful()) {
            Log::warning('TCMB rates: unexpected HTTP '.$response->status().'.');

            return null;
        }

        return $this->parse($response->body());
    }

    /**
     * @return array{usd: float, eur: float, rate_date: string}|null
     */
    public function parse(string $body): ?array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false || $xml->getName() !== 'Tarih_Date') {
            Log::warning('TCMB rates: response is not a TCMB bulletin.');

            return null;
        }

        try {
            $date = Carbon::createFromFormat('!d.m.Y', (string) $xml['Tarih'], self::TIMEZONE);
        } catch (Throwable) {
            $date = false;
        }

        if (! $date) {
            Log::warning('TCMB rates: bulletin date missing or unreadable.');

            return null;
        }

        $rates = [];

        foreach ($xml->Currency as $currency) {
            $code = (string) $currency['Kod'];

            if (! in_array($code, self::CURRENCIES, true)) {
                continue;
            }

            $value = (float) str_replace(',', '.', trim((string) $currency->{self::RATE_FIELD}));
            $unit = max(1.0, (float) ((string) $currency->Unit ?: 1));

            if ($value <= 0 || $value > 100000) {
                continue;
            }

            $rates[strtolower($code)] = round($value / $unit, 4);
        }

        if (count($rates) !== count(self::CURRENCIES)) {
            Log::warning('TCMB rates: USD/EUR missing or invalid in the bulletin.');

            return null;
        }

        return ['usd' => $rates['usd'], 'eur' => $rates['eur'], 'rate_date' => $date->toDateString()];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function state(): ?array
    {
        $state = Cache::get(self::CACHE_KEY);

        return is_array($state) ? $state : null;
    }

    private function store(array $state): void
    {
        Cache::forever(self::CACHE_KEY, $state);
    }

    private function present(?array $state): array
    {
        $usd = isset($state['usd']) ? (float) $state['usd'] : null;
        $eur = isset($state['eur']) ? (float) $state['eur'] : null;
        $rateDate = isset($state['rate_date']) ? Carbon::parse($state['rate_date'])->format('d.m.Y') : null;
        $checkedAt = isset($state['checked_at']) ? Carbon::parse($state['checked_at'])->timezone(self::TIMEZONE)->format('d.m.Y H:i') : null;

        $title = ($usd === null && $eur === null)
            ? 'TCMB kuru şu an alınamadı'
            : 'TCMB Döviz '.self::RATE_LABEL.' (gösterge kur) · kur tarihi '.$rateDate.($checkedAt ? ' · son kontrol '.$checkedAt : '');

        return [
            'usd' => $usd,
            'eur' => $eur,
            'usd_text' => $usd === null ? '—' : number_format($usd, 2, ',', '.'),
            'eur_text' => $eur === null ? '—' : number_format($eur, 2, ',', '.'),
            'usd_precise' => $usd === null ? '—' : number_format($usd, 4, ',', '.'),
            'eur_precise' => $eur === null ? '—' : number_format($eur, 4, ',', '.'),
            'rate_date' => $rateDate,
            'checked_at' => $checkedAt,
            'title' => $title,
        ];
    }
}
