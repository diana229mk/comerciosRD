<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\MenuCatalogService;
use App\Services\QrCodeService;
use Illuminate\Http\Response;
use Illuminate\View\View;

class PublicMenuController extends Controller
{
    public function show(string $slug, MenuCatalogService $menus): View
    {
        $catalog = $menus->forBusiness($this->business($slug));

        return view('menu.public', [
            'business' => $catalog['business'],
            'categories' => $catalog['categories'],
        ]);
    }

    public function poster(string $slug, MenuCatalogService $menus, QrCodeService $qr): View
    {
        $business = $this->business($slug);
        $url = $menus->publicUrl($business);

        return view('menu.poster', [
            'business' => $business,
            'url' => $url,
            'qrSvg' => $qr->svg($url, 360),
        ]);
    }

    public function qrSvg(string $slug, MenuCatalogService $menus, QrCodeService $qr): Response
    {
        $url = $menus->publicUrl($this->business($slug));
        $svg = $qr->svg($url, 480);

        return response($svg, 200, [
            'Content-Type' => 'image/svg+xml; charset=UTF-8',
            'Content-Disposition' => 'inline; filename="menu-'.$slug.'.svg"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    protected function business(string $slug): Business
    {
        $business = Business::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->first();

        abort_unless($business, 404);

        return $business;
    }
}
