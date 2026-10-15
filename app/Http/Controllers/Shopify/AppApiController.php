<?php

namespace App\Http\Controllers\Shopify;

use App\Http\Controllers\Controller;
use App\Http\Requests\Shopify\UpdateSettingsRequest;
use App\Models\Capture;
use App\Models\Shop;
use App\Models\TrackedCart;
use App\Services\AbandonedCartWebhook;
use App\Services\Shopify\Billing;
use App\Support\UsagePlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/** JSON API for the embedded app pages. The shop comes from AuthenticateShopifySession. */
class AppApiController extends Controller
{
    public function state(Request $request, Billing $billing): JsonResponse
    {
        return response()->json($this->statePayload($this->shop($request), $billing));
    }

    public function updateSettings(UpdateSettingsRequest $request, Billing $billing): JsonResponse
    {
        $shop = $this->shop($request);
        $incoming = $request->validated('settings');

        // Empty texts fall back to the defaults, so they aren't stored.
        $settings = collect($incoming)
            ->except('fields')
            ->reject(fn ($value) => $value === null || $value === '')
            ->all();

        $settings['fields'] = array_map(fn (array $field) => [
            'key' => $field['key'],
            'enabled' => (bool) $field['enabled'],
            'required' => (bool) $field['required'],
            'label' => filled($field['label'] ?? null) ? $field['label'] : null,
        ], $incoming['fields']);

        $shop->update([
            'mode' => $request->validated('mode'),
            'webhook_url' => $request->validated('webhook_url') ?: null,
            'settings' => $settings,
        ]);

        return response()->json($this->statePayload($shop->refresh(), $billing));
    }

    public function leads(Request $request): JsonResponse
    {
        $page = Capture::query()
            ->where('shop_id', $this->shop($request)->id)
            ->when(in_array($request->query('source'), Shop::SOURCES, true), fn ($q) => $q->where('source', $request->query('source')))
            ->when($request->query('q'), fn ($q, $term) => $q->where('email', 'like', '%'.mb_strtolower($term).'%'))
            ->orderByDesc('last_captured_at')
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (Capture $c) => [
                'email' => $c->email,
                'name' => $c->fullName(),
                'phone' => $c->phone,
                'source' => $c->source,
                'accepts_marketing' => $c->accepts_marketing,
                'accepts_sms' => $c->accepts_sms,
                'cart_total' => $c->cart_total_cents !== null ? $c->cart_total_cents / 100 : null,
                'currency' => $c->currency,
                'last_captured_at' => $c->last_captured_at?->toIso8601String(),
            ]),
            'page' => $page->currentPage(),
            'has_next' => $page->hasMorePages(),
            'total' => $page->total(),
        ]);
    }

    /** Downloads can't run inside the admin iframe — hand out a 5-minute signed link instead. */
    public function exportLink(Request $request): JsonResponse
    {
        return response()->json([
            'url' => URL::temporarySignedRoute('shopify.leads.export', now()->addMinutes(5), ['shop' => $this->shop($request)]),
        ]);
    }

    public static function exportLeads(Shop $shop): StreamedResponse
    {
        return response()->streamDownload(function () use ($shop) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['email', 'first_name', 'last_name', 'phone', 'birthday', 'accepts_marketing', 'accepts_sms', 'source', 'cart_total', 'currency', 'first_seen', 'last_seen']);
            $shop->captures()->orderBy('id')->lazyById(500)->each(fn (Capture $c) => fputcsv($out, [
                $c->email, $c->first_name, $c->last_name, $c->phone, $c->birthday?->toDateString(),
                $c->accepts_marketing ? 'yes' : 'no', $c->accepts_sms ? 'yes' : 'no', $c->source,
                $c->cart_total_cents !== null ? number_format($c->cart_total_cents / 100, 2, '.', '') : null, $c->currency,
                $c->first_captured_at?->toDateTimeString(), $c->last_captured_at?->toDateTimeString(),
            ]));
            fclose($out);
        }, 'checkout-capture-leads.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function carts(Request $request): JsonResponse
    {
        $status = $request->query('status', TrackedCart::ABANDONED);

        $page = TrackedCart::query()
            ->with('shop')
            ->where('shop_id', $this->shop($request)->id)
            ->when($status !== 'all', fn ($q) => $q->where('status', $status))
            ->orderByDesc('last_activity_at')
            ->paginate(25);

        return response()->json([
            'data' => $page->getCollection()->map(fn (TrackedCart $c) => [
                'id' => $c->id,
                'email' => $c->email,
                'status' => $c->status,
                'source' => $c->source,
                'products' => collect($c->items)->map(fn (array $i) => ($i['product_title'] ?? '').' × '.($i['quantity'] ?? 1))->implode(', '),
                'total' => $c->total_cents !== null ? $c->total_cents / 100 : null,
                'currency' => $c->currency,
                'last_activity_at' => $c->last_activity_at?->toIso8601String(),
                'abandoned_at' => $c->abandoned_at?->toIso8601String(),
                'webhook_status' => $c->webhook_status,
                'recovery_url' => $c->recoveryUrl(),
            ]),
            'page' => $page->currentPage(),
            'has_next' => $page->hasMorePages(),
            'total' => $page->total(),
        ]);
    }

    public function subscribe(Request $request, Billing $billing): JsonResponse
    {
        try {
            return response()->json(['confirmation_url' => $billing->subscribe($this->shop($request))]);
        } catch (Throwable $e) {
            return response()->json(['message' => 'Shopify could not start the plan approval: '.$e->getMessage()], 502);
        }
    }

    public function testWebhook(Request $request, AbandonedCartWebhook $webhook): JsonResponse
    {
        $shop = $this->shop($request);

        if (! $shop->webhook_url) {
            return response()->json(['ok' => false, 'message' => 'Save a webhook URL first.'], 422);
        }

        $result = $webhook->sendTest($shop);

        return response()->json([
            'ok' => $result['ok'],
            'message' => $result['ok'] ? "Delivered (HTTP {$result['status']})." : 'Failed: '.($result['status'] ? "HTTP {$result['status']} " : '').$result['error'],
        ]);
    }

    private function statePayload(Shop $shop, Billing $billing): array
    {
        $period = $shop->periodFor();
        $checkouts = $shop->activationCount($period);
        $tier = UsagePlan::forActivations($checkouts);
        $periodStart = strlen($period) === 10 ? Carbon::parse($period) : Carbon::parse("{$period}-01");
        $orders = $shop->orders()->where('ordered_at', '>=', $periodStart);
        $freeLimit = UsagePlan::TIERS[0]['up_to'];

        return [
            'shop' => [
                'name' => $shop->name,
                'domain' => $shop->shop_domain,
                'storefront' => $shop->storefrontHost(),
            ],
            'setup' => [
                'embed_live' => $shop->widget_seen_at?->gt(now()->subDays(2)) ?? false,
                'widget_seen_at' => $shop->widget_seen_at?->toIso8601String(),
                'embed_url' => "https://{$shop->shop_domain}/admin/themes/current/editor?context=apps&activateAppId="
                    .config('shopify.api_key').'/'.config('shopify.embed_handle'),
                'preview_url' => URL::temporarySignedRoute('shops.preview.signed', now()->addHour(), ['shop' => $shop]),
            ],
            'billing' => [
                'active' => $billing->isActive($shop),
                'status' => $shop->billing_status,
                'terms' => Billing::terms(),
                'serving' => $billing->canServe($shop),
                'free_limit' => $freeLimit,
            ],
            'usage' => [
                'period_start' => $periodStart->toDateString(),
                'checkouts' => $checkouts,
                'plan' => $tier['name'],
                'price' => $tier['price'],
                'orders' => (clone $orders)->count(),
                'revenue' => round((clone $orders)->sum('total_cents') / 100, 2),
                'revenue_currency' => (clone $orders)->value('currency'),
                'leads_total' => $shop->captures()->count(),
                'abandoned_open' => $shop->trackedCarts()->where('status', TrackedCart::ABANDONED)->count(),
            ],
            'settings' => [
                'mode' => $shop->mode,
                'webhook_url' => $shop->webhook_url,
                'webhook_secret' => $shop->webhook_url ? $shop->webhook_secret : null,
                'campaign_link' => $shop->campaignLinkExample(),
                // Raw saved values (empty = default) plus the defaults to show as placeholders.
                'values' => array_merge(
                    array_fill_keys(array_keys(Shop::DEFAULT_SETTINGS), null),
                    collect($shop->settings ?? [])->except('fields')->all(),
                    collect(['design', 'direction', 'accent_color', 'url_capture', 'form_capture', 'abandoned_carts', 'identify_marketing_apps', 'allow_skip'])
                        ->mapWithKeys(fn ($key) => [$key => $shop->settings[$key] ?? Shop::DEFAULT_SETTINGS[$key]])
                        ->all(),
                ),
                'defaults' => Shop::DEFAULT_SETTINGS,
                'fields' => array_map(fn (array $f) => [
                    'key' => $f['key'],
                    'name' => Shop::FIELD_NAMES[$f['key']],
                    'type' => $f['type'],
                    'enabled' => $f['enabled'],
                    'required' => $f['required'],
                    'label' => $f['label'] === Shop::FIELDS[$f['key']]['label'] ? null : $f['label'],
                    'default_label' => Shop::FIELDS[$f['key']]['label'],
                ], $shop->resolvedFields()),
                'hebrew' => ['texts' => Shop::HEBREW_TEXTS, 'labels' => Shop::HEBREW_FIELD_LABELS],
            ],
        ];
    }

    private function shop(Request $request): Shop
    {
        return $request->attributes->get('shop');
    }
}
