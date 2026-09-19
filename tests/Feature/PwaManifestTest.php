<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The install icon must show the whole Mikrolens logo: the manifest points at
 * the dedicated /pwa icon set (any + maskable), every file exists with the
 * declared size and PNG type, and the maskable artwork stays inside the 80%
 * safe-zone circle so phone masks can never cut it.
 */
class PwaManifestTest extends TestCase
{
    use RefreshDatabase;

    private function manifest(): array
    {
        $path = public_path('manifest.json');
        $this->assertFileExists($path);

        $manifest = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($manifest);

        return $manifest;
    }

    private function iconsByPurpose(): array
    {
        $byKey = [];

        foreach ($this->manifest()['icons'] as $icon) {
            $byKey[($icon['purpose'] ?? 'any').':'.$icon['sizes']] = $icon;
        }

        return $byKey;
    }

    public function test_the_app_layout_links_the_manifest(): void
    {
        Role::firstOrCreate(['name' => 'Admin']);
        $admin = User::factory()->create();
        $admin->assignRole('Admin');

        $this->actingAs($admin)->get('/dashboard')->assertOk()
            ->assertSee('<link rel="manifest" href="'.asset('manifest.json').'">', false);
    }

    public function test_the_manifest_is_installable_and_keeps_its_identity(): void
    {
        $manifest = $this->manifest();

        $this->assertSame('StokTakip360', $manifest['name']);
        $this->assertSame('StokTakip360', $manifest['short_name']);
        $this->assertSame('/dashboard', $manifest['start_url']);
        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('#4f46e5', $manifest['theme_color']);
    }

    public function test_the_manifest_declares_any_and_maskable_icons_at_192_and_512(): void
    {
        $icons = $this->iconsByPurpose();

        $expected = [
            'any:192x192' => '/pwa/icon-192.png',
            'any:512x512' => '/pwa/icon-512.png',
            'maskable:192x192' => '/pwa/icon-maskable-192.png',
            'maskable:512x512' => '/pwa/icon-maskable-512.png',
        ];

        $this->assertCount(4, $this->manifest()['icons']);

        foreach ($expected as $key => $src) {
            $this->assertArrayHasKey($key, $icons, "missing icon {$key}");
            $this->assertSame($src, $icons[$key]['src']);
            $this->assertSame('image/png', $icons[$key]['type']);
        }
    }

    public function test_every_declared_icon_file_is_a_png_of_the_declared_size(): void
    {
        foreach ($this->manifest()['icons'] as $icon) {
            $file = public_path(ltrim($icon['src'], '/'));
            $this->assertFileExists($file, $icon['src']);

            [$w, $h, $type] = array_pad(getimagesize($file), 5, null);
            $this->assertSame(IMAGETYPE_PNG, $type, "{$icon['src']} is not a PNG");
            $this->assertSame($icon['sizes'], "{$w}x{$h}", "{$icon['src']} size differs from the manifest");
            $this->assertSame('image/png', mime_content_type($file));
            $this->assertLessThan(100 * 1024, filesize($file), "{$icon['src']} is unexpectedly large");
        }
    }

    public function test_the_old_cropped_icons_are_gone_and_no_longer_referenced(): void
    {
        $json = file_get_contents(public_path('manifest.json'));

        $this->assertStringNotContainsString('"/icon-192.png"', $json);
        $this->assertStringNotContainsString('"/icon-512.png"', $json);
        $this->assertFileDoesNotExist(public_path('icon-192.png'));
        $this->assertFileDoesNotExist(public_path('icon-512.png'));
    }

    /**
     * @return array{radius: float, left: float, right: float, top: float, bottom: float}
     */
    private function artworkExtent(string $file): array
    {
        $img = imagecreatefrompng($file);
        $n = imagesx($img);
        $maxR = 0.0;
        $minX = $minY = $n;
        $maxX = $maxY = 0;

        for ($y = 0; $y < $n; $y++) {
            for ($x = 0; $x < $n; $x++) {
                $c = imagecolorat($img, $x, $y);
                $ink = 255 - min(($c >> 16) & 255, ($c >> 8) & 255, $c & 255);

                if ($ink <= 8) {
                    continue;
                }

                $maxR = max($maxR, hypot($x + 0.5 - $n / 2, $y + 0.5 - $n / 2));
                $minX = min($minX, $x);
                $maxX = max($maxX, $x);
                $minY = min($minY, $y);
                $maxY = max($maxY, $y);
            }
        }

        return [
            'radius' => $maxR / $n,
            'left' => $minX / $n,
            'right' => ($n - 1 - $maxX) / $n,
            'top' => $minY / $n,
            'bottom' => ($n - 1 - $maxY) / $n,
        ];
    }

    public function test_maskable_icons_keep_the_whole_logo_inside_the_safe_zone_circle(): void
    {
        foreach (['icon-maskable-192.png', 'icon-maskable-512.png'] as $name) {
            $extent = $this->artworkExtent(public_path("pwa/{$name}"));

            // Android's safe zone: a circle of radius 40% of the icon.
            $this->assertLessThan(0.40, $extent['radius'], "{$name} artwork leaves the safe-zone circle");
            $this->assertGreaterThan(0.10, $extent['left'], "{$name} not padded on the left");
            $this->assertGreaterThan(0.10, $extent['right'], "{$name} not padded on the right");
        }
    }

    public function test_regular_icons_have_breathing_room_and_the_logo_is_centred(): void
    {
        foreach (['icon-192.png', 'icon-512.png', 'icon-maskable-192.png', 'icon-maskable-512.png'] as $name) {
            $extent = $this->artworkExtent(public_path("pwa/{$name}"));

            $this->assertGreaterThan(0.05, $extent['left'], "{$name} touches the left edge");
            $this->assertGreaterThan(0.05, $extent['right'], "{$name} touches the right edge");
            $this->assertEqualsWithDelta($extent['left'], $extent['right'], 0.015, "{$name} is not horizontally centred");
            $this->assertEqualsWithDelta($extent['top'], $extent['bottom'], 0.05, "{$name} is not vertically centred");
        }
    }

    public function test_the_icons_are_opaque_so_masks_never_show_a_dark_background(): void
    {
        foreach (['icon-192.png', 'icon-512.png', 'icon-maskable-192.png', 'icon-maskable-512.png'] as $name) {
            $img = imagecreatefrompng(public_path("pwa/{$name}"));
            $n = imagesx($img);

            foreach ([[0, 0], [$n - 1, 0], [0, $n - 1], [$n - 1, $n - 1], [intdiv($n, 2), 2]] as [$x, $y]) {
                $c = imagecolorat($img, $x, $y);
                $this->assertSame(0, ($c >> 24) & 127, "{$name} has a transparent pixel at {$x},{$y}");
                $this->assertSame(0xFFFFFF, $c & 0xFFFFFF, "{$name} background is not white at {$x},{$y}");
            }
        }
    }

    // Favicon ve masaüstü/sidebar logosu bu düzeltmenin dışında kalır
    public function test_the_favicon_and_layout_branding_are_untouched(): void
    {
        $this->assertFileExists(public_path('favicon.ico'));

        $layout = file_get_contents(resource_path('views/layouts/app.blade.php'));
        $this->assertStringContainsString('<a href="{{ route(\'dashboard\') }}" class="text-lg font-bold text-white leading-tight">StokTakip360</a>', $layout);
    }
}
