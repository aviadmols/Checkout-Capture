<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Abandoned carts are handed off by webhook — the shop's own tools send the recovery
 * message. URL and secret live in columns, not in `settings`, because settings are
 * served to the storefront.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('webhook_url', 2048)->nullable()->after('storefront_access_token');
            $table->string('webhook_secret', 64)->nullable()->after('webhook_url');
        });

        Schema::table('tracked_carts', function (Blueprint $table) {
            $table->string('webhook_status', 16)->nullable()->after('abandoned_at');
            $table->timestamp('webhook_attempted_at')->nullable()->after('webhook_status');
            $table->unsignedSmallInteger('webhook_response_code')->nullable()->after('webhook_attempted_at');
            $table->text('webhook_error')->nullable()->after('webhook_response_code');
        });
    }

    public function down(): void
    {
        Schema::table('tracked_carts', function (Blueprint $table) {
            $table->dropColumn(['webhook_status', 'webhook_attempted_at', 'webhook_response_code', 'webhook_error']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['webhook_url', 'webhook_secret']);
        });
    }
};
