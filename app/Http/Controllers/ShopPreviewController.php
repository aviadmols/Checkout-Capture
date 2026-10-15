<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin-only page that renders a shop's popup with its saved settings. The widget
 * runs in preview mode: config is inlined and nothing is sent to Laravel or Shopify.
 */
class ShopPreviewController extends Controller
{
    public function __invoke(Request $request, Shop $shop): View
    {
        $config = $shop->widgetConfig();
        $design = $request->query('design');

        if (in_array($design, Shop::DESIGNS, true)) {
            $config['settings']['design'] = $design;
        }

        return view('preview', [
            'shop' => $shop,
            'config' => ['enabled' => true, ...$config, 'mode' => Shop::MODE_POPUP],
            'design' => $config['settings']['design'],
        ]);
    }
}
