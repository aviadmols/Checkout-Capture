<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Shops become Shopify app installations: an offline Admin API token from token
 * exchange, a usage-billing subscription, and orders attributed to captured emails.
 * Shops added by hand in the operator admin keep working without any of this.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            // Expiring offline token (1 hour) + refresh token (90 days), from token exchange.
            $table->text('shopify_access_token')->nullable()->after('webhook_secret');
            $table->timestamp('shopify_token_expires_at')->nullable()->after('shopify_access_token');
            $table->text('shopify_refresh_token')->nullable()->after('shopify_token_expires_at');
            $table->timestamp('shopify_refresh_expires_at')->nullable()->after('shopify_refresh_token');
            $table->string('shopify_scopes', 1024)->nullable()->after('shopify_refresh_expires_at');
            // Last time the storefront widget asked for its config — "is the app embed on?".
            $table->timestamp('widget_seen_at')->nullable()->after('shopify_scopes');
            $table->timestamp('installed_at')->nullable()->after('shopify_scopes');
            $table->timestamp('uninstalled_at')->nullable()->after('installed_at');

            $table->string('billing_subscription_id')->nullable()->after('uninstalled_at');
            $table->string('billing_line_item_id')->nullable()->after('billing_subscription_id');
            // pending (merchant hasn't approved yet) | active | declined | cancelled
            $table->string('billing_status', 16)->nullable()->after('billing_line_item_id');
            // Shopify bills app usage in 30-day cycles from activation; periods follow this anchor.
            $table->timestamp('billing_cycle_anchor')->nullable()->after('billing_status');
        });

        // Periods are "2026-10" (calendar month) or "2026-10-15" (start of a Shopify billing cycle).
        Schema::table('activations', function (Blueprint $table) {
            $table->string('period', 10)->change();
        });

        Schema::create('usage_charges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('period', 10);
            $table->string('tier', 16);
            $table->decimal('amount', 8, 2);
            $table->string('shopify_usage_record_id')->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();

            // One charge per tier per period: crossing 50 charges once, crossing 1,000 tops up once.
            $table->unique(['shop_id', 'period', 'tier']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capture_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('shopify_order_id');
            $table->string('name', 32)->nullable();
            $table->string('email');
            // attribute = the order carried our _capture_email cart attribute.
            $table->string('matched_by', 16);
            $table->unsignedInteger('total_cents');
            $table->string('currency', 3);
            $table->timestamp('ordered_at');
            $table->timestamps();

            $table->unique(['shop_id', 'shopify_order_id']);
            $table->index(['shop_id', 'ordered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
        Schema::dropIfExists('usage_charges');

        Schema::table('activations', function (Blueprint $table) {
            $table->char('period', 7)->change();
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn([
                'shopify_access_token', 'shopify_token_expires_at', 'shopify_refresh_token', 'shopify_refresh_expires_at',
                'shopify_scopes', 'widget_seen_at', 'installed_at', 'uninstalled_at',
                'billing_subscription_id', 'billing_line_item_id', 'billing_status', 'billing_cycle_anchor',
            ]);
        });
    }
};
