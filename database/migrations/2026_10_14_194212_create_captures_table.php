<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('captures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->string('phone', 32)->nullable();
            $table->boolean('accepts_marketing')->default(false);

            // Snapshot of the cart at the moment of the latest submission.
            $table->string('cart_token')->nullable();
            $table->unsignedInteger('cart_total_cents')->nullable();
            $table->string('currency', 3)->nullable();
            $table->unsignedSmallInteger('item_count')->nullable();
            $table->json('cart_items')->nullable();

            $table->string('locale', 16)->nullable();
            $table->text('page_url')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            $table->unsignedInteger('submissions_count')->default(1);
            $table->timestamp('first_captured_at');
            $table->timestamp('last_captured_at');
            $table->timestamps();

            $table->unique(['shop_id', 'email']);
            $table->index(['shop_id', 'last_captured_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('captures');
    }
};
