<?php

namespace App\Services;

use App\Models\Capture;
use App\Models\Shop;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * One row per (shop, email). A repeat submission refreshes the cart snapshot and
 * contact details but never blanks out a value the visitor gave us earlier.
 */
class CaptureRecorder
{
    /**
     * @param  array<string, mixed>  $data  validated StoreCaptureRequest payload
     * @param  array{ip_address?: ?string, user_agent?: ?string}  $context
     */
    public function record(Shop $shop, array $data, array $context = []): Capture
    {
        try {
            return $this->upsert($shop, $data, $context);
        } catch (UniqueConstraintViolationException) {
            // Two submissions raced on the same email — the row exists now, update it.
            return $this->upsert($shop, $data, $context);
        }
    }

    private function upsert(Shop $shop, array $data, array $context): Capture
    {
        $now = now();
        // Separate first/last fields win over a full name split on the first space.
        [$firstName, $lastName] = $this->splitName($data['name'] ?? null);
        $firstName = $data['first_name'] ?? $firstName;
        $lastName = $data['last_name'] ?? $lastName;
        $cart = $data['cart'] ?? [];

        $capture = Capture::query()->firstOrNew([
            'shop_id' => $shop->id,
            'email' => $data['email'],
        ]);

        $fresh = [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'phone' => $data['phone'] ?? null,
            'birthday' => $data['birthday'] ?? null,
            'locale' => $data['locale'] ?? null,
            'page_url' => $data['page_url'] ?? null,
            'ip_address' => $context['ip_address'] ?? null,
            'user_agent' => isset($context['user_agent']) ? Str::limit($context['user_agent'], 500, '') : null,
        ];

        if ($cart !== []) {
            $fresh += [
                'cart_token' => $cart['token'] ?? null,
                'cart_total_cents' => $cart['total_price'] ?? null,
                'currency' => isset($cart['currency']) ? Str::upper($cart['currency']) : null,
                'item_count' => $cart['item_count'] ?? null,
                'cart_items' => $this->cartItems($cart['items'] ?? []),
            ];
        }

        $capture->fill(array_filter($fresh, fn ($value) => $value !== null && $value !== ''));

        // Consent only ever moves forward from a submission; opting out happens elsewhere.
        if (! empty($data['accepts_marketing'])) {
            $capture->accepts_marketing = true;
        }

        if (! empty($data['accepts_sms'])) {
            $capture->accepts_sms = true;
        }

        if ($capture->exists) {
            $capture->submissions_count++;
        } else {
            $capture->first_captured_at = $now;
            // Where the lead first came from: the popup form or an email in a campaign link.
            $capture->source = $data['source'] ?? 'popup';
        }

        $capture->last_captured_at = $now;
        $capture->save();

        return $capture;
    }

    /** @return array{0: ?string, 1: ?string} */
    private function splitName(?string $name): array
    {
        if (! $name) {
            return [null, null];
        }

        $parts = explode(' ', $name, 2);

        return [$parts[0], $parts[1] ?? null];
    }

    private function cartItems(array $items): array
    {
        return array_map(fn (array $item) => Arr::only($item, [
            'variant_id', 'product_title', 'variant_title', 'quantity', 'price',
        ]), $items);
    }
}
