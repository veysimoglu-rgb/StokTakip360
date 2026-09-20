<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use App\Services\ExchangeRateService;
use Carbon\Carbon;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Header widget: HH:mm clock (browser-side) + cached TCMB USD/EUR "Alış" rates.
 *
 * Every test that talks to "TCMB" uses Http::fake() — the real service is never called (phpunit.xml
 * switches the feature off, these tests switch it on together with Http::preventStrayRequests()).
 * Dates: 2026-09-16 is a Wednesday, 09-18 a Friday, 09-19/20 the weekend, 09-21 a Monday.
 */
class ExchangeRateHeaderTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** What the fake TCMB answers with; replaced per step with $this->tcmb(). */
    private Closure $tcmbAnswer;

    /** Requests that reached the HTTP layer (also the ones answered with a connection error). */
    private int $requests = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate(['name' => 'Admin']);
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');

        config(['services.tcmb.enabled' => true, 'services.tcmb.url' => 'https://www.tcmb.gov.tr/kurlar/today.xml']);

        Http::preventStrayRequests();
        $this->tcmbAnswer = fn () => Http::response(self::bulletin('18.09.2026'), 200);
        Http::fake(function (Request $request) {
            $this->requests++;

            return ($this->tcmbAnswer)($request);
        });
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    private static function bulletin(string $date, string $usd = '48.6116', string $eur = '55.7981'): string
    {
        return <<<XML
        <?xml version="1.0" encoding="UTF-8"?>
        <?xml-stylesheet type="text/xsl" href="isokur.xsl"?>
        <Tarih_Date Tarih="{$date}" Date="01/01/2026" Bulten_No="2026/1">
            <Currency CrossOrder="0" Kod="USD" CurrencyCode="USD">
                <Unit>1</Unit><Isim>ABD DOLARI</Isim>
                <ForexBuying>{$usd}</ForexBuying><ForexSelling>48.6992</ForexSelling>
                <BanknoteBuying>48.5776</BanknoteBuying><BanknoteSelling>48.7723</BanknoteSelling>
            </Currency>
            <Currency CrossOrder="1" Kod="AUD" CurrencyCode="AUD">
                <Unit>1</Unit><ForexBuying>34.5568</ForexBuying><ForexSelling>34.7821</ForexSelling>
            </Currency>
            <Currency CrossOrder="9" Kod="EUR" CurrencyCode="EUR">
                <Unit>1</Unit><Isim>EURO</Isim>
                <ForexBuying>{$eur}</ForexBuying><ForexSelling>55.8986</ForexSelling>
                <BanknoteBuying>55.7590</BanknoteBuying><BanknoteSelling>55.9824</BanknoteSelling>
            </Currency>
        </Tarih_Date>
        XML;
    }

    /** Set "now" to a Türkiye wall-clock time. */
    private function at(string $istanbul): void
    {
        Carbon::setTestNow(Carbon::parse($istanbul, 'Europe/Istanbul'));
    }

    /** @param  Closure|string  $answer  body string, or a closure returning a response / throwing */
    private function tcmb(Closure|string $answer): void
    {
        $this->tcmbAnswer = is_string($answer) ? fn () => Http::response($answer, 200) : $answer;
    }

    private function tcmbDown(): void
    {
        $this->tcmb(fn () => throw new ConnectionException('cURL error 28: timed out'));
    }

    /** Number of TCMB requests attempted through the HTTP client so far (successful or not). */
    private function sent(): int
    {
        return $this->requests;
    }

    /** Opens the dashboard as the admin and returns the <header> markup. */
    private function header(?User $user = null): string
    {
        $response = $this->actingAs($user ?? $this->admin)->get('/dashboard')->assertOk();

        $this->assertSame(1, preg_match('#<header\b.*?</header>#s', $response->getContent(), $m), 'layout header found');

        return $m[0];
    }

    private function widgetText(string $header): string
    {
        preg_match('#<div class="hdr-info".*?</div>#s', $header, $m);

        return trim(preg_replace('/\s+/', ' ', strip_tags($m[0] ?? '')));
    }

    /** Seeds "yesterday's" state the way a real earlier check would have (through the service). */
    private function seedRatesAt(string $istanbul, string $bulletinDate = '15.09.2026', string $usd = '47.1000', string $eur = '54.2000'): void
    {
        $this->at($istanbul);
        $this->tcmb(self::bulletin($bulletinDate, $usd, $eur));
        $this->assertTrue(app(ExchangeRateService::class)->refresh(), 'seed check stored rates');
        $this->tcmb(fn () => Http::response(self::bulletin('18.09.2026'), 200));
    }

    // ------------------------------------------------------------ clock

    public function test_clock_shows_hh_mm_without_seconds_in_turkiye_time(): void
    {
        $this->at('2026-09-16 13:47:12');

        $header = $this->header();

        $this->assertStringContainsString('<time class="hdr-clock" data-live-clock datetime="13:47">13:47</time>', $header);
        $this->assertStringNotContainsString('13:47:', $header);
        $this->assertDoesNotMatchRegularExpression('/\b\d{2}:\d{2}:\d{2}\b/', strip_tags($header));
    }

    public function test_clock_pads_hours_and_minutes_and_uses_turkiye_time_whatever_the_server_zone(): void
    {
        // 06:05 UTC == 09:05 in Türkiye
        Carbon::setTestNow(Carbon::parse('2026-09-16 06:05:40', 'UTC'));

        $this->assertStringContainsString('data-live-clock datetime="09:05">09:05</time>', $this->header());
    }

    public function test_clock_is_updated_by_the_browser_only_and_never_asks_the_server(): void
    {
        $header = $this->header();

        preg_match('#<script>(.*?)</script>#s', $header, $m);
        $script = $m[1] ?? '';

        $this->assertNotSame('', $script);
        // paints HH:mm from the device clock, on the minute boundary
        $this->assertStringContainsString('getHours()', $script);
        $this->assertStringContainsString('getMinutes()', $script);
        $this->assertStringContainsString('60000 - (Date.now() % 60000)', $script);
        $this->assertStringNotContainsString('getSeconds', $script);
        // no network of any kind, no 1-second timer
        foreach (['fetch(', 'XMLHttpRequest', 'sendBeacon', 'axios', 'EventSource', 'WebSocket', 'setInterval', 'location.reload'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $script, "clock script must not use {$forbidden}");
        }
        $this->assertSame(1, $this->sent(), 'rendering the clock sends nothing beyond the one cold-start rates check');
    }

    // ------------------------------------------------------------ rates on screen

    public function test_header_shows_usd_and_eur_alis_rates_and_the_source(): void
    {
        $this->at('2026-09-16 10:00:00');

        $text = $this->widgetText($this->header());

        $this->assertSame('10:00 | USD Alış 48,61 | EUR Alış 55,80 | TCMB', $text);
    }

    public function test_the_shown_rate_is_the_forex_buying_column_and_says_so(): void
    {
        $this->assertSame('ForexBuying', ExchangeRateService::RATE_FIELD);
        $this->assertSame('Alış', ExchangeRateService::RATE_LABEL);

        $header = $this->header();

        // not ForexSelling (48,70 / 55,90) and not BanknoteBuying (48,58 / 55,76)
        foreach (['48,70', '55,90', '48,58', '55,76', '48,77', '55,98'] as $other) {
            $this->assertStringNotContainsString($other, $header);
        }
        $this->assertStringContainsString('data-rate="USD"', $header);
        $this->assertStringContainsString('data-rate="EUR"', $header);
        $this->assertStringContainsString('<span class="hdr-kind">Alış</span>', $header);
        $this->assertStringContainsString('TCMB Döviz Alış (gösterge kur) · kur tarihi 18.09.2026', $header);
        $this->assertStringContainsString('1 USD = 48,6116 ₺', $header);
        $this->assertStringContainsString('1 EUR = 55,7981 ₺', $header);
    }

    public function test_the_widget_is_only_in_the_layout_header_once(): void
    {
        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-header-info'));
        $this->assertSame(1, substr_count($html, '<time class="hdr-clock"'));
        $this->assertStringNotContainsString('hdr-', preg_match('#<footer\b.*?</footer>#s', $html, $f) ? $f[0] : 'x');
        $this->assertStringNotContainsString('hdr-', preg_match('#<main\b.*?</main>#s', $html, $m) ? $m[0] : 'x');
    }

    public function test_receipt_pdf_login_and_footer_never_get_the_clock_or_rates(): void
    {
        $product = Product::factory()->create(['current_stock' => 50, 'sale_price' => 10, 'currency' => 'TL']);
        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();

        $receipt = $this->actingAs($this->admin)->get('/sales/'.Sale::first()->id.'/receipt')->assertOk()->getContent();

        foreach (['data-live-clock', 'data-header-info', 'hdr-', 'TCMB', 'USD Alış', 'EUR Alış'] as $needle) {
            $this->assertStringNotContainsString($needle, $receipt, "receipt must not contain {$needle}");
        }

        $sentBefore = $this->sent();
        auth()->logout();
        $login = $this->get('/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-header-info', $login);
        $this->assertStringNotContainsString('TCMB', $login);
        $this->assertSame($sentBefore, $this->sent(), 'guest pages never trigger a TCMB request');
    }

    // ------------------------------------------------------------ requests & cache

    public function test_first_ever_page_view_fetches_once_and_already_shows_the_rates(): void
    {
        $this->at('2026-09-16 10:00:00');

        $text = $this->widgetText($this->header());

        $this->assertStringContainsString('USD Alış 48,61', $text);
        $this->assertSame(1, $this->sent());
        Http::assertSent(fn (Request $r) => $r->url() === 'https://www.tcmb.gov.tr/kurlar/today.xml' && $r->method() === 'GET');
    }

    public function test_page_refreshes_and_further_users_never_repeat_the_request_within_a_slot(): void
    {
        $second = User::factory()->create();
        $second->assignRole('Admin');

        $this->at('2026-09-16 10:00:00');
        $this->header();

        foreach (['10:01', '11:30', '12:00', '13:59', '16:29'] as $time) {
            $this->at("2026-09-16 {$time}:00");
            $this->header();            // F5 by the same user
            $this->header($second);     // another user
        }

        $this->assertSame(1, $this->sent(), '12 page views in the same slot => still one request');
        $this->assertSame('48,61', trim(strip_tags(preg_match('#data-rate="USD".*?<b>(.*?)</b>#s', $this->header($second), $m) ? $m[1] : '')));
    }

    public function test_rates_are_one_shared_cache_entry_not_per_user_or_per_browser(): void
    {
        $this->at('2026-09-16 10:00:00');
        $this->header();

        $state = Cache::get(ExchangeRateService::CACHE_KEY);

        $this->assertEqualsWithDelta(48.6116, $state['usd'], 0.00001);
        $this->assertEqualsWithDelta(55.7981, $state['eur'], 0.00001);
        $this->assertSame('2026-09-18', $state['rate_date']);
        $this->assertSame('2026-09-16 09:00', $state['checked_slot']);
        $this->assertSame(['usd', 'eur', 'rate_date', 'fetched_at', 'checked_at', 'checked_slot'], array_keys($state));
    }

    public function test_at_most_two_tcmb_checks_per_weekday_at_0900_and_1630(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00'); // Tuesday evening, one earlier check
        $base = $this->sent();
        $expectedSent = [
            // time  => cumulative requests on Wednesday 2026-09-16
            '00:05' => 0, '08:59' => 0,
            '09:00' => 1, '09:01' => 1, '12:00' => 1, '16:29' => 1,
            '16:30' => 2, '16:31' => 2, '20:00' => 2, '23:59' => 2,
        ];

        foreach ($expectedSent as $time => $count) {
            $this->at("2026-09-16 {$time}:00");
            $this->header();
            $this->assertSame($count, $this->sent() - $base, "requests on Wednesday after the {$time} page view");
        }

        // Thursday's first views before 09:00 are quiet again
        $this->at('2026-09-17 00:10:00');
        $this->header();
        $this->at('2026-09-17 08:59:00');
        $this->header();
        $this->assertSame(2, $this->sent() - $base);
    }

    public function test_an_early_first_fetch_counts_for_the_0900_slot_so_the_day_still_has_two_checks(): void
    {
        $this->at('2026-09-16 00:05:00');
        $this->header();                            // nothing cached yet: fetch (credited to 09:00)
        $this->assertSame(1, $this->sent());

        foreach (['08:59', '09:00', '13:00', '16:29'] as $time) {
            $this->at("2026-09-16 {$time}:00");
            $this->header();
        }
        $this->assertSame(1, $this->sent());

        $this->at('2026-09-16 16:30:00');
        $this->header();
        $this->assertSame(2, $this->sent(), 'the 16:30 check is the day\'s second and last');

        $this->at('2026-09-16 23:59:00');
        $this->header();
        $this->assertSame(2, $this->sent());
    }

    public function test_the_refresh_of_an_already_cached_page_runs_after_the_response_not_before(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00');
        $base = $this->sent();
        $this->at('2026-09-16 09:30:00');

        $service = app(ExchangeRateService::class);
        $shown = $service->forHeader();

        $this->assertSame($base, $this->sent(), 'rendering the header waits for nothing');
        $this->assertSame('47,10', $shown['usd_text'], 'the page shows the previous rates meanwhile');

        $this->app->terminate();

        $this->assertSame($base + 1, $this->sent(), 'the check runs once, after the response');
        $this->assertSame('48,61', $service->forHeader()['usd_text']);
    }

    public function test_concurrent_refresh_calls_send_a_single_request(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00');
        $base = $this->sent();
        $this->at('2026-09-16 09:30:00');

        $service = app(ExchangeRateService::class);
        $this->assertTrue($service->refresh());
        $this->assertFalse($service->refresh());
        $this->assertFalse($service->refresh());
        $this->assertSame($base + 1, $this->sent());

        // a second worker holding the lock does not fetch either
        $this->at('2026-09-16 16:31:00');
        $lock = Cache::lock(ExchangeRateService::CACHE_KEY.':lock', 30);
        $this->assertTrue($lock->get());
        $this->assertFalse($service->refresh());
        $this->assertSame($base + 1, $this->sent());
        $lock->release();
    }

    // ------------------------------------------------------------ weekend / holiday

    public function test_weekend_sends_nothing_and_keeps_the_last_rates_until_monday_0900(): void
    {
        $this->seedRatesAt('2026-09-18 17:00:00', '18.09.2026', '48.6116', '55.7981'); // Friday, after 16:30
        $base = $this->sent();

        foreach (['2026-09-18 23:30', '2026-09-19 00:01', '2026-09-19 09:00', '2026-09-19 16:30', '2026-09-20 10:00', '2026-09-20 23:59', '2026-09-21 08:59'] as $moment) {
            $this->at("{$moment}:00");
            $text = $this->widgetText($this->header());
            $this->assertStringContainsString('USD Alış 48,61 | EUR Alış 55,80', $text, "rates kept at {$moment}");
        }
        $this->assertSame($base, $this->sent(), 'no TCMB request from Friday 16:30 until Monday 09:00');

        $this->at('2026-09-21 09:00:00');
        $this->header();
        $this->assertSame($base + 1, $this->sent());
    }

    public function test_a_first_ever_fetch_on_a_weekend_does_not_add_an_extra_check_on_monday(): void
    {
        $this->at('2026-09-20 11:00:00'); // Sunday
        $this->assertStringContainsString('USD Alış 48,61', $this->widgetText($this->header()));
        $this->assertSame(1, $this->sent());

        $this->at('2026-09-20 18:00:00');
        $this->header();
        $this->at('2026-09-21 08:59:00');
        $this->header();
        $this->assertSame(1, $this->sent());

        $this->at('2026-09-21 09:00:00');
        $this->header();
        $this->assertSame(2, $this->sent());
    }

    public function test_on_a_holiday_tcmb_serves_the_old_bulletin_or_fails_and_the_last_rates_stay(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00', '15.09.2026', '48.0000', '55.0000');

        // Wednesday is a public holiday: TCMB keeps serving Tuesday's bulletin (same date) at 09:00 ...
        $this->at('2026-09-16 09:10:00');
        $this->tcmb(self::bulletin('15.09.2026', '48.0000', '55.0000'));
        $this->assertStringContainsString('USD Alış 48,00 | EUR Alış 55,00', $this->widgetText($this->header()));

        // ... and answers with an error at 16:30
        $this->at('2026-09-16 16:40:00');
        $this->tcmb(fn () => Http::response('Service Unavailable', 503));
        $this->assertStringContainsString('USD Alış 48,00 | EUR Alış 55,00', $this->widgetText($this->header()));
    }

    public function test_an_older_bulletin_never_replaces_a_newer_one(): void
    {
        $this->seedRatesAt('2026-09-18 17:00:00', '18.09.2026', '48.6116', '55.7981');

        $this->at('2026-09-21 09:05:00');
        $this->tcmb(self::bulletin('11.09.2026', '40.0000', '45.0000'));
        $text = $this->widgetText($this->header());

        $this->assertStringContainsString('USD Alış 48,61 | EUR Alış 55,80', $text);
        $this->assertSame('2026-09-18', Cache::get(ExchangeRateService::CACHE_KEY)['rate_date']);
    }

    public function test_a_newer_bulletin_replaces_the_old_one(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00', '14.09.2026', '47.0000', '54.0000');

        $this->at('2026-09-16 16:31:00');
        $this->tcmb(self::bulletin('16.09.2026', '48.9000', '56.1000'));
        $this->header();
        $this->app->terminate();

        $text = $this->widgetText($this->header());
        $this->assertStringContainsString('USD Alış 48,90 | EUR Alış 56,10', $text);
        $this->assertSame('2026-09-16', Cache::get(ExchangeRateService::CACHE_KEY)['rate_date']);
    }

    // ------------------------------------------------------------ failures

    public function test_unreachable_tcmb_keeps_the_last_good_rates_and_the_page_still_works(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00');

        $this->at('2026-09-16 09:05:00');
        $this->tcmbDown();

        $text = $this->widgetText($this->header());

        $this->assertStringContainsString('USD Alış 47,10 | EUR Alış 54,20', $text);
    }

    public function test_a_failing_tcmb_is_not_retried_before_the_next_slot(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00');
        $base = $this->sent();
        $this->tcmbDown();

        foreach (['09:00', '09:01', '09:30', '12:00', '16:29'] as $time) {
            $this->at("2026-09-16 {$time}:00");
            $this->header();
        }
        $this->assertSame($base + 1, $this->sent(), 'one failed attempt, no hammering');

        $this->at('2026-09-16 16:30:00');
        $this->header();
        $this->at('2026-09-16 21:00:00');
        $this->header();
        $this->assertSame($base + 2, $this->sent(), 'the next slot tries once more; never more than two a day');
    }

    public function test_when_tcmb_recovers_the_next_slot_picks_the_new_rates_up(): void
    {
        $this->seedRatesAt('2026-09-15 17:00:00');
        $this->tcmbDown();
        $this->at('2026-09-16 09:05:00');
        $this->header();

        $this->tcmb(self::bulletin('16.09.2026', '49.0000', '56.0000'));
        $this->at('2026-09-16 16:35:00');
        $this->header();

        $this->assertStringContainsString('USD Alış 49,00 | EUR Alış 56,00', $this->widgetText($this->header()));
    }

    public function test_without_any_data_the_header_shows_a_dash_instead_of_a_number(): void
    {
        $this->tcmbDown();
        $this->at('2026-09-16 10:00:00');

        $text = $this->widgetText($this->header());

        $this->assertSame('10:00 | USD Alış — | EUR Alış — | TCMB', $text);
        $this->assertStringContainsString('TCMB kuru şu an alınamadı', $this->header());
        $this->assertSame(1, $this->sent(), 'still only the single cold-start attempt for the slot');
    }

    public function test_garbage_answers_are_treated_as_failures(): void
    {
        $bad = [
            'html error page' => '<html><body>Bakımdayız</body></html>',
            'empty body' => '',
            'not xml at all' => 'USD=48.61',
            'wrong root' => '<?xml version="1.0"?><Other/>',
            'no date' => '<Tarih_Date><Currency Kod="USD"><Unit>1</Unit><ForexBuying>48.6</ForexBuying></Currency><Currency Kod="EUR"><Unit>1</Unit><ForexBuying>55.8</ForexBuying></Currency></Tarih_Date>',
            'EUR missing' => '<Tarih_Date Tarih="18.09.2026"><Currency Kod="USD"><Unit>1</Unit><ForexBuying>48.6</ForexBuying></Currency></Tarih_Date>',
            'zero rate' => self::bulletin('18.09.2026', '0.0000'),
            'non-numeric rate' => self::bulletin('18.09.2026', 'abc'),
        ];

        foreach ($bad as $label => $body) {
            Cache::flush();
            $this->at('2026-09-16 10:00:00');
            $this->tcmb($body);

            $text = $this->widgetText($this->header());

            $this->assertSame('10:00 | USD Alış — | EUR Alış — | TCMB', $text, $label);
        }
    }

    public function test_an_unreadable_cache_entry_never_breaks_the_page(): void
    {
        Cache::forever(ExchangeRateService::CACHE_KEY, 'corrupted');
        $this->at('2026-09-16 10:00:00');

        $this->assertStringContainsString('USD Alış 48,61', $this->widgetText($this->header()));
    }

    public function test_a_broken_cache_store_shows_dashes_but_the_page_renders(): void
    {
        config(['cache.default' => 'nonexistent-store']);
        $this->app->forgetInstance('cache');
        $this->app->forgetInstance('cache.store');
        Facade::clearResolvedInstance('cache');

        $service = app(ExchangeRateService::class);
        $shown = $service->forHeader();

        $this->assertSame('—', $shown['usd_text']);
        $this->assertSame('—', $shown['eur_text']);
    }

    // ------------------------------------------------------------ switch off / isolation

    public function test_when_switched_off_nothing_is_ever_requested_and_dashes_show(): void
    {
        config(['services.tcmb.enabled' => false]);

        $text = $this->widgetText($this->header());

        $this->assertStringContainsString('USD Alış — | EUR Alış —', $text);
        $this->assertSame(0, $this->sent());
    }

    public function test_the_test_suite_default_is_switched_off_so_no_test_can_reach_tcmb_by_accident(): void
    {
        $this->assertTrue((bool) config('services.tcmb.enabled'), 'this class switches it on in setUp');

        $fresh = require base_path('config/services.php');
        $this->assertFalse($fresh['tcmb']['enabled'], 'phpunit.xml forces TCMB_RATES_ENABLED=false');
    }

    // ------------------------------------------------------------ slot arithmetic

    public function test_slot_arithmetic_is_in_turkiye_time_and_skips_weekends(): void
    {
        $service = app(ExchangeRateService::class);
        $slot = fn (string $utc) => $service->currentSlot(Carbon::parse($utc, 'UTC'))?->format('Y-m-d H:i');

        $this->assertNull($slot('2026-09-16 05:59:59'), '08:59:59 Türkiye');
        $this->assertSame('2026-09-16 09:00', $slot('2026-09-16 06:00:00'));
        $this->assertSame('2026-09-16 09:00', $slot('2026-09-16 13:29:59'));
        $this->assertSame('2026-09-16 16:30', $slot('2026-09-16 13:30:00'));
        $this->assertSame('2026-09-16 16:30', $slot('2026-09-16 20:59:59'));
        $this->assertNull($slot('2026-09-19 07:00:00'), 'Saturday');
        $this->assertNull($slot('2026-09-20 12:00:00'), 'Sunday');
        // 22:00 UTC Sunday is already 01:00 Monday in Türkiye
        $this->assertNull($slot('2026-09-20 22:00:00'));
        $this->assertSame('2026-09-21 09:00', $slot('2026-09-21 06:00:00'));

        $credit = fn (string $utc) => $service->slotToCredit(Carbon::parse($utc, 'UTC'))->format('Y-m-d H:i');
        $this->assertSame('2026-09-16 09:00', $credit('2026-09-15 22:00:00'), '01:00 Wednesday');
        $this->assertSame('2026-09-18 16:30', $credit('2026-09-20 12:00:00'), 'Sunday -> Friday 16:30');
        $this->assertSame('2026-09-18 16:30', $credit('2026-09-19 12:00:00'), 'Saturday -> Friday 16:30');
    }

    public function test_the_parser_reads_unit_dates_and_ignores_other_currencies(): void
    {
        $service = app(ExchangeRateService::class);

        $ok = $service->parse(self::bulletin('18.09.2026'));
        $this->assertSame(['usd' => 48.6116, 'eur' => 55.7981, 'rate_date' => '2026-09-18'], $ok);

        $unit100 = str_replace('<Unit>1</Unit><Isim>ABD DOLARI</Isim>', '<Unit>100</Unit><Isim>ABD DOLARI</Isim>', self::bulletin('18.09.2026'));
        $this->assertSame(0.4861, $service->parse($unit100)['usd']);

        $this->assertNull($service->parse('<Tarih_Date Tarih="31.02.2026"/>'));
    }

    // ------------------------------------------------------------ layout contract (measured for real in the Edge check)

    public function test_header_keeps_its_height_and_the_widget_has_a_two_line_mobile_layout(): void
    {
        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('<header class="h-16 bg-white border-b flex items-center justify-between gap-3 px-4 lg:px-8">', $html);
        $this->assertStringNotContainsString('min-h-16 bg-white border-b', $html);

        // mobile (<700px): the clock takes a line of its own, separators / "Alış" / TCMB are hidden
        $this->assertStringContainsString('@media (max-width: 699px)', $html);
        $this->assertStringContainsString('.hdr-clock { flex-basis: 100%; text-align: right;', $html);
        $this->assertStringContainsString('.hdr-sep, .hdr-src, .hdr-kind { display: none; }', $html);
        // desktop/tablet: one line that may wrap, never wider than its container
        $this->assertStringContainsString('.hdr-info { display: flex; align-items: center; justify-content: flex-end; flex-wrap: wrap;', $html);
        $this->assertStringContainsString('max-width: 100%;', $html);
        $this->assertStringContainsString('white-space: nowrap;', $html);
        // title yields space instead of overlapping the widget
        $this->assertStringContainsString('.app-header-title { flex: 1 1 0; min-width: 0;', $html);
        $this->assertStringContainsString('text-overflow: ellipsis;', $html);
        // hamburger, sidebar and footer are where they were
        $this->assertStringContainsString('@click="sidebarOpen = true" class="lg:hidden', $html);
        $this->assertStringContainsString('.app-footer { position: fixed; bottom: 0; left: 0; right: 0; z-index: 20; }', $html);
        $this->assertStringContainsString('<script data-enter-guard>', $html);
    }
}
