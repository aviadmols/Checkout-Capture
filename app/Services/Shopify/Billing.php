<?php

namespace App\Services\Shopify;

use App\Models\Shop;
use App\Models\UsageCharge;
use App\Support\UsagePlan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Usage pricing through the Shopify Billing API: one usage subscription capped at the top
 * tier's price. When a 30-day cycle's checkouts cross a tier, a usage record tops the bill
 * up to that tier's price — $14 past 50 checkouts, +$11 (= $25) past 1,000.
 */
class Billing
{
    public const SUBSCRIPTION_NAME = 'Checkout Capture';

    public function __construct(private ShopifyClient $shopify) {}

    public static function terms(): string
    {
        return 'Free up to 50 checkouts per 30-day billing cycle. $14 when a cycle passes 50 checkouts, $25 in total when it passes 1,000.';
    }

    /** Creates the usage subscription; the merchant approves it at the returned URL. */
    public function subscribe(Shop $shop): string
    {
        $cap = collect(UsagePlan::TIERS)->max('price');

        // Development stores (including the ones Shopify's reviewers use) only accept test charges.
        $plan = $this->shopify->graphql($shop, 'query { shop { plan { partnerDevelopment } } }');
        $test = (bool) config('shopify.billing_test') || (bool) ($plan['shop']['plan']['partnerDevelopment'] ?? false);

        $result = $this->shopify->graphql($shop, <<<'GQL'
            mutation($name: String!, $returnUrl: URL!, $lineItems: [AppSubscriptionLineItemInput!]!, $test: Boolean) {
              appSubscriptionCreate(name: $name, returnUrl: $returnUrl, lineItems: $lineItems, test: $test) {
                confirmationUrl
                appSubscription { id }
                userErrors { field message }
              }
            }
            GQL, [
            'name' => self::SUBSCRIPTION_NAME,
            'returnUrl' => route('shopify.billing.return', ['shop' => $shop->shop_domain]),
            'test' => $test,
            'lineItems' => [[
                'plan' => ['appUsagePricingDetails' => [
                    'terms' => self::terms(),
                    'cappedAmount' => ['amount' => $cap, 'currencyCode' => 'USD'],
                ]],
            ]],
        ]);

        $payload = $result['appSubscriptionCreate'] ?? null;
        ShopifyClient::assertNoUserErrors($payload, 'appSubscriptionCreate');

        $shop->update([
            'billing_subscription_id' => $payload['appSubscription']['id'],
            'billing_status' => 'pending',
        ]);

        return $payload['confirmationUrl'];
    }

    /** Reads the active subscription from Shopify and mirrors it on the shop. */
    public function sync(Shop $shop): void
    {
        $result = $this->shopify->graphql($shop, <<<'GQL'
            query {
              currentAppInstallation {
                activeSubscriptions {
                  id name status currentPeriodEnd
                  lineItems { id plan { pricingDetails { __typename } } }
                }
              }
            }
            GQL);

        $subscription = collect($result['currentAppInstallation']['activeSubscriptions'] ?? [])
            ->first(fn (array $s) => $s['status'] === 'ACTIVE' && $s['name'] === self::SUBSCRIPTION_NAME);

        if (! $subscription) {
            if ($shop->billing_status === 'active') {
                $shop->update(['billing_status' => 'cancelled', 'billing_line_item_id' => null]);
            }

            return;
        }

        $lineItem = collect($subscription['lineItems'])
            ->first(fn (array $item) => ($item['plan']['pricingDetails']['__typename'] ?? null) === 'AppUsagePricing');

        $shop->update([
            'billing_subscription_id' => $subscription['id'],
            'billing_line_item_id' => $lineItem['id'] ?? null,
            'billing_status' => 'active',
            // Usage periods follow Shopify's 30-day cycle so each charge lands in its own cycle.
            'billing_cycle_anchor' => $subscription['currentPeriodEnd']
                ? Carbon::parse($subscription['currentPeriodEnd'])->subDays(30)
                : ($shop->billing_cycle_anchor ?? now()),
        ]);
    }

    public function isActive(Shop $shop): bool
    {
        return $shop->billing_status === 'active' && filled($shop->billing_line_item_id);
    }

    /**
     * Shops installed through Shopify serve the free tier without a plan; past it the widget
     * steps aside (checkout keeps working) until the merchant approves the subscription.
     */
    public function canServe(Shop $shop): bool
    {
        if (! $shop->isInstalled() || $this->isActive($shop)) {
            return true;
        }

        return $shop->activationCount() < UsagePlan::TIERS[0]['up_to'];
    }

    /** Creates any usage record the current cycle owes. Safe to call after every activation. */
    public function chargeForUsage(Shop $shop): void
    {
        if (! $this->isActive($shop)) {
            return;
        }

        $period = $shop->periodFor();
        $count = $shop->activationCount($period);
        $tiers = UsagePlan::TIERS;

        for ($i = 1; $i < count($tiers); $i++) {
            if ($count <= $tiers[$i - 1]['up_to']) {
                break;
            }

            $this->charge($shop, $period, $tiers[$i]['name'], $tiers[$i]['price'] - $tiers[$i - 1]['price']);
        }
    }

    private function charge(Shop $shop, string $period, string $tier, int $amount): void
    {
        $charge = UsageCharge::query()->firstOrCreate(
            ['shop_id' => $shop->id, 'period' => $period, 'tier' => Str::lower($tier)],
            ['amount' => $amount, 'status' => 'pending'],
        );

        if ($charge->status === 'charged') {
            return;
        }

        try {
            $result = $this->shopify->graphql($shop, <<<'GQL'
                mutation($lineItemId: ID!, $price: MoneyInput!, $description: String!, $idempotencyKey: String) {
                  appUsageRecordCreate(subscriptionLineItemId: $lineItemId, price: $price, description: $description, idempotencyKey: $idempotencyKey) {
                    appUsageRecord { id }
                    userErrors { field message }
                  }
                }
                GQL, [
                'lineItemId' => $shop->billing_line_item_id,
                'price' => ['amount' => $amount, 'currencyCode' => 'USD'],
                'description' => "{$tier} plan — billing cycle starting {$period}",
                'idempotencyKey' => "cc-{$shop->id}-{$period}-".Str::lower($tier),
            ]);

            $payload = $result['appUsageRecordCreate'] ?? null;
            ShopifyClient::assertNoUserErrors($payload, 'appUsageRecordCreate');

            $charge->update(['status' => 'charged', 'shopify_usage_record_id' => $payload['appUsageRecord']['id'], 'error' => null]);
        } catch (Throwable $e) {
            $charge->update(['status' => 'failed', 'error' => Str::limit($e->getMessage(), 500)]);

            if (! $e instanceof RuntimeException) {
                throw $e;
            }
        }
    }
}
