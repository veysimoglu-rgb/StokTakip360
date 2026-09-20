<?php

namespace App\View\Components;

use App\Models\Setting;
use App\Support\CompanyLogo;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Top block of the sidebar: [logo] / StokTakip360 / company name (or, without one, the signed-in user).
 */
class SidebarBrand extends Component
{
    public ?string $logoUrl;

    public string $companyName;

    public string $subtitle;

    public function __construct()
    {
        $this->logoUrl = CompanyLogo::url();
        $this->companyName = trim((string) Setting::get('company_name'));
        $this->subtitle = $this->companyName !== '' ? $this->companyName : (string) auth()->user()?->name;
    }

    public function render(): View
    {
        return view('components.sidebar-brand');
    }
}
