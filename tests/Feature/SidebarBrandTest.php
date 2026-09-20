<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use App\Models\User;
use App\Support\CompanyLogo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Sidebar brand block: [logo] / StokTakip360 / company (or user) name, and the logo upload behind it.
 * The logo is stored on the private "local" disk — faked here so tests never touch storage/app.
 */
class SidebarBrandTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        Role::firstOrCreate(['name' => 'Admin']);
        Role::firstOrCreate(['name' => 'Personel']);
        $this->admin = User::factory()->create(['name' => 'Ayşe Yönetici']);
        $this->admin->assignRole('Admin');
    }

    private function personel(): User
    {
        $user = User::factory()->create(['name' => 'Personel Kişi']);
        $user->assignRole('Personel');

        return $user;
    }

    /** @param  array<string, mixed>  $extra */
    private function save(array $extra = []): TestResponse
    {
        return $this->actingAs($this->admin)->put('/settings', $extra + [
            'company_name' => 'Fera Plastik Sanayi Ltd. Şti.',
            'currency' => 'TL',
        ]);
    }

    private function uploadLogo(string $name = 'logo.png', int $w = 600, int $h = 180): void
    {
        $this->save(['company_logo' => UploadedFile::fake()->image($name, $w, $h)])->assertSessionHasNoErrors();
    }

    private function brand(?User $user = null): string
    {
        $html = $this->actingAs($user ?? $this->admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, preg_match('#<div class="sb-brand[^"]*" data-sidebar-brand>.*?</div>#s', $html, $m), 'brand block found');

        return $m[0];
    }

    // ------------------------------------------------------------ layout & order

    public function test_without_a_logo_the_block_is_app_name_then_company_name_and_has_no_image(): void
    {
        Setting::set('company_name', 'Fera Plastik Sanayi Ltd. Şti.');

        $brand = $this->brand();

        $this->assertStringNotContainsString('<img', $brand);
        $this->assertStringNotContainsString('data-brand-logo', $brand);
        $this->assertLessThan(strpos($brand, 'Fera Plastik'), strpos($brand, 'StokTakip360'));
    }

    public function test_without_a_company_name_the_signed_in_user_is_shown_instead(): void
    {
        $brand = $this->brand();

        $this->assertStringContainsString('data-brand-subtitle', $brand);
        $this->assertStringContainsString('Ayşe Yönetici', $brand);

        Setting::set('company_name', '   ');
        $this->assertStringContainsString('Ayşe Yönetici', $this->brand());
    }

    public function test_with_a_logo_the_order_is_logo_then_app_name_then_company_name(): void
    {
        Setting::set('company_name', 'Fera Plastik Sanayi Ltd. Şti.');
        $this->uploadLogo();

        $brand = $this->brand();

        $img = strpos($brand, '<img');
        $app = strpos($brand, 'StokTakip360');
        $org = strpos($brand, 'Fera Plastik', $app);

        $this->assertNotFalse($img);
        $this->assertLessThan($app, $img, 'logo above the app name');
        $this->assertLessThan($org, $app, 'app name above the company name');
        $this->assertStringContainsString('alt="Fera Plastik Sanayi Ltd. Şti."', $brand);
        $this->assertStringContainsString('src="'.CompanyLogo::url().'"', $brand);
        $this->assertMatchesRegularExpression('#/branding/logo\?v=[A-Za-z0-9]{40}#', $brand);
        $this->assertSame(1, substr_count($brand, '<img'));
    }

    public function test_the_logo_is_contained_never_cropped_and_sized_for_a_220_by_56_area(): void
    {
        $this->uploadLogo();

        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('.sb-logo { display: flex; align-items: center; justify-content: flex-start; width: 100%; max-width: 220px; height: 56px; overflow: hidden; }', $html);
        $this->assertStringContainsString('.sb-logo img { display: block; max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain;', $html);
        $this->assertStringNotContainsString('object-fit: cover', $html);
        $this->assertStringNotContainsString('object-fit: fill', $html);
        $this->assertStringNotContainsString('object-fit: none', $html);
        // the image itself is never forced to a fixed width/height (that would stretch it)
        preg_match('#\.sb-logo img \{([^}]*)\}#', $html, $rule);
        $this->assertDoesNotMatchRegularExpression('/(?<!max-)(?<![\w-])(?:width|height):\s*(?:\d+px|\d+%)/', $rule[1]);
        // shorter viewports get a smaller area; the block never shrinks inside the flex column
        $this->assertStringContainsString('@media (max-height: 700px) { .sb-logo { height: 44px; } }', $html);
        $this->assertStringContainsString('.sb-brand { flex: 0 0 auto;', $html);
        // a logo that fails to load disappears instead of showing a broken image
        $this->assertStringContainsString("onerror=\"this.parentNode.style.display='none'\"", $html);
    }

    public function test_sidebar_width_and_menu_layout_are_unchanged(): void
    {
        $this->uploadLogo();

        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertStringContainsString('class="fixed inset-y-0 left-0 z-40 w-64 bg-gray-900 text-gray-100 flex flex-col transform transition-transform -translate-x-full lg:static lg:translate-x-0"', $html);
        $this->assertStringContainsString('<nav class="flex-1 overflow-y-auto py-4 px-3 space-y-6 text-sm">', $html);
        $this->assertStringContainsString('Profilim', $html);
        $this->assertStringContainsString('Çıkış Yap', $html);
        $this->assertStringContainsString('border-t border-gray-800 p-3', $html);
        // the brand block still closes with the dark divider line
        $this->assertStringContainsString('border-bottom: 1px solid #1f2937;', $html);
    }

    public function test_the_brand_block_links_home_and_is_only_in_the_sidebar(): void
    {
        $this->uploadLogo();

        $html = $this->actingAs($this->admin)->get('/dashboard')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-sidebar-brand'));
        $this->assertSame(1, preg_match('#<a href="[^"]*/dashboard" class="sb-home">.*?</a>#s', $html, $m));
        $this->assertStringContainsString('<img', $m[0]);
        $this->assertStringContainsString('StokTakip360', $m[0]);

        foreach (['header', 'main', 'footer'] as $tag) {
            preg_match('#<'.$tag.'\b.*?</'.$tag.'>#s', $html, $x);
            $this->assertStringNotContainsString('branding/logo', $x[0] ?? '', "<{$tag}> has no logo");
        }
    }

    public function test_receipt_and_login_never_get_the_logo(): void
    {
        $this->uploadLogo();
        $product = Product::factory()->create(['current_stock' => 50, 'sale_price' => 10, 'currency' => 'TL']);
        $this->actingAs($this->admin)->post('/sales', [
            'payment_type' => 'pesin',
            'items' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 10]],
        ])->assertSessionHasNoErrors();

        $receipt = $this->actingAs($this->admin)->get('/sales/'.Sale::first()->id.'/receipt')->assertOk()->getContent();
        $this->assertStringNotContainsString('branding/logo', $receipt);

        auth()->logout();
        $this->get('/login')->assertOk()->assertDontSee('branding/logo');
    }

    // ------------------------------------------------------------ upload

    public function test_png_jpg_and_webp_logos_are_accepted_and_stored_privately(): void
    {
        foreach (['logo.png' => 'png', 'logo.jpg' => 'jpg', 'logo.jpeg' => 'jpg', 'logo.webp' => 'webp'] as $name => $ext) {
            $this->save(['company_logo' => UploadedFile::fake()->image($name, 600, 180)])
                ->assertSessionHasNoErrors()
                ->assertSessionHas('success');

            $path = Setting::get(CompanyLogo::KEY);
            $this->assertMatchesRegularExpression('#^branding/[A-Za-z0-9]{40}\.'.$ext.'$#', $path, $name);
            Storage::disk('local')->assertExists($path);
        }

        $this->assertCount(1, Storage::disk('local')->files('branding'), 'only the newest logo file is kept');
    }

    public function test_any_aspect_ratio_is_allowed_not_just_600_by_180(): void
    {
        foreach ([[600, 180], [100, 400], [1200, 100], [300, 300], [64, 64], [4000, 1000]] as [$w, $h]) {
            $this->save(['company_logo' => UploadedFile::fake()->image('logo.png', $w, $h)])
                ->assertSessionHasNoErrors();
            $this->assertNotNull(CompanyLogo::path(), "{$w}x{$h} accepted");
        }
    }

    public function test_unsafe_or_oversized_files_are_rejected_and_the_current_logo_stays(): void
    {
        $this->uploadLogo();
        $current = Setting::get(CompanyLogo::KEY);

        $bad = [
            'svg (can carry scripts)' => UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            'gif' => UploadedFile::fake()->image('logo.gif', 100, 100),
            'php renamed to png' => UploadedFile::fake()->createWithContent('logo.png', '<?php echo "x";'),
            'pdf' => UploadedFile::fake()->create('logo.pdf', 10, 'application/pdf'),
            'over 2 MB' => UploadedFile::fake()->image('logo.png', 100, 100)->size(2049),
            'over 4000 px wide' => UploadedFile::fake()->image('logo.png', 4001, 100),
            'over 4000 px tall' => UploadedFile::fake()->image('logo.png', 100, 4001),
        ];

        foreach ($bad as $label => $file) {
            $this->save(['company_logo' => $file])->assertSessionHasErrors('company_logo');
            $this->assertSame($current, Setting::get(CompanyLogo::KEY), "{$label}: setting unchanged");
            $this->assertSame([$current], Storage::disk('local')->files('branding'), "{$label}: nothing new stored");
        }
    }

    public function test_the_error_messages_are_in_turkish(): void
    {
        $this->save(['company_logo' => UploadedFile::fake()->image('logo.gif', 10, 10)])
            ->assertSessionHasErrors(['company_logo' => 'Logo PNG, JPG veya WebP olmalıdır.']);
        $this->save(['company_logo' => UploadedFile::fake()->image('logo.png', 10, 10)->size(3000)])
            ->assertSessionHasErrors(['company_logo' => 'Logo en fazla 2 MB olabilir.']);
    }

    public function test_replacing_and_removing_the_logo_deletes_the_old_file(): void
    {
        $this->uploadLogo('a.png');
        $first = Setting::get(CompanyLogo::KEY);

        $this->uploadLogo('b.webp');
        $second = Setting::get(CompanyLogo::KEY);
        $this->assertNotSame($first, $second);
        Storage::disk('local')->assertMissing($first);
        Storage::disk('local')->assertExists($second);

        $this->save(['remove_company_logo' => '1'])->assertSessionHasNoErrors();
        $this->assertNull(CompanyLogo::path());
        Storage::disk('local')->assertMissing($second);
        $this->assertStringNotContainsString('<img', $this->brand());
    }

    public function test_saving_other_settings_without_a_file_keeps_the_logo(): void
    {
        $this->uploadLogo();
        $path = Setting::get(CompanyLogo::KEY);

        $this->save(['company_name' => 'Yeni Ad', 'company_phone' => '0212 000 00 00'])->assertSessionHasNoErrors();

        $this->assertSame($path, Setting::get(CompanyLogo::KEY));
        Storage::disk('local')->assertExists($path);
        $this->assertSame('Yeni Ad', Setting::get('company_name'));
        $this->assertSame('0212 000 00 00', Setting::get('company_phone'));
        $this->assertNull(Setting::get('remove_company_logo'));
    }

    public function test_only_admins_can_change_the_logo(): void
    {
        $this->actingAs($this->personel())
            ->put('/settings', ['currency' => 'TL', 'company_logo' => UploadedFile::fake()->image('logo.png', 600, 180)])
            ->assertForbidden();

        $this->assertNull(CompanyLogo::path());
    }

    public function test_settings_page_previews_the_logo_and_offers_removal_only_when_there_is_one(): void
    {
        $page = $this->actingAs($this->admin)->get('/settings')->assertOk();
        $page->assertSee('enctype="multipart/form-data"', false);
        $page->assertSee('name="company_logo"', false);
        $page->assertSee('600×180', false);
        $page->assertSee('zorunlu değildir', false);
        $page->assertDontSee('data-logo-preview', false);
        $page->assertDontSee('remove_company_logo', false);

        $this->uploadLogo();

        $page = $this->actingAs($this->admin)->get('/settings')->assertOk();
        $page->assertSee('data-logo-preview', false);
        $page->assertSee('name="remove_company_logo"', false);
        $page->assertSee(CompanyLogo::url(), false);
    }

    // ------------------------------------------------------------ serving

    public function test_every_signed_in_user_gets_the_logo_with_safe_headers(): void
    {
        $this->uploadLogo('logo.webp');
        $url = CompanyLogo::url();

        foreach ([$this->admin, $this->personel()] as $user) {
            $response = $this->actingAs($user)->get($url)->assertOk();
            $response->assertHeader('Content-Type', 'image/webp');
            $response->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertStringContainsString('immutable', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString('private', $response->headers->get('Cache-Control'));
        }

        $this->assertStringContainsString('<img', $this->brand($this->personel()), 'personel sees the logo in the sidebar too');
    }

    public function test_each_format_is_served_with_its_own_content_type(): void
    {
        foreach (['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'] as $ext => $mime) {
            $this->uploadLogo("logo.{$ext}");
            $this->actingAs($this->admin)->get(CompanyLogo::url())->assertOk()->assertHeader('Content-Type', $mime);
        }
    }

    public function test_guests_cannot_fetch_the_logo(): void
    {
        $this->uploadLogo();
        $url = CompanyLogo::url();

        auth()->logout();
        $this->get($url)->assertRedirect('/login');
    }

    public function test_without_a_logo_the_route_is_404_and_a_missing_file_drops_the_image_from_the_sidebar(): void
    {
        $this->actingAs($this->admin)->get('/branding/logo')->assertNotFound();

        $this->uploadLogo();
        Storage::disk('local')->delete(Setting::get(CompanyLogo::KEY));

        $this->assertNull(CompanyLogo::url());
        $this->assertStringNotContainsString('<img', $this->brand());
        $this->actingAs($this->admin)->get('/branding/logo')->assertNotFound();
    }

    public function test_a_tampered_setting_value_can_never_point_outside_the_logo_names_we_generate(): void
    {
        Storage::disk('local')->put('secret.txt', 'x');
        Storage::disk('local')->put('branding/notes.txt', 'x');

        foreach (['secret.txt', 'branding/../secret.txt', 'branding/notes.txt', '../../.env', 'branding/a/b.png', 'branding/x.svg', ''] as $value) {
            Setting::set(CompanyLogo::KEY, $value);

            $this->assertNull(CompanyLogo::path(), "'{$value}' is not served");
            $this->actingAs($this->admin)->get('/branding/logo')->assertNotFound();
        }
    }
}
