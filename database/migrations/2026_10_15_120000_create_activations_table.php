<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per billable activation: a cart the widget attached an email to on its way to checkout.
        Schema::create('activations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('capture_id')->nullable()->constrained()->nullOnDelete();
            $table->string('email');
            $table->string('cart_token')->nullable();
            $table->string('source', 16);
            $table->char('period', 7);
            // Cart token, or the email when the cart couldn't be read — one activation per cart per month.
            $table->string('dedupe_key', 64);
            $table->timestamps();

            $table->unique(['shop_id', 'period', 'dedupe_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activations');
    }
};
