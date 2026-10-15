<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shops', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            // e.g. mills-il.myshopify.com — also used for the Storefront API call.
            $table->string('shop_domain')->unique();
            // Public storefront hostnames allowed to post captures (Origin check). Empty = any.
            $table->json('allowed_origins')->nullable();
            // Shipped to the browser in the snippet; identifies the shop, is not a secret.
            $table->string('public_key', 64)->unique();
            // Public Storefront API token, used client-side for cartBuyerIdentityUpdate.
            $table->string('storefront_access_token')->nullable();
            $table->boolean('is_active')->default(true);
            // Popup texts, field modes, colours — see Shop::DEFAULT_SETTINGS.
            $table->json('settings')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shops');
    }
};
