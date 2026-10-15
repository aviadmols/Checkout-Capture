<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * - shops.is_active becomes shops.mode: popup | url (no form, email only from the link) | off
 * - the fixed name/phone/consent settings become a configurable `settings.fields` list
 * - captures gain the new optional fields and the source they came from (popup | url)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('mode', 8)->default('popup')->after('storefront_access_token');
        });

        DB::table('shops')->where('is_active', false)->update(['mode' => 'off']);

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        DB::table('shops')->orderBy('id')->each(function ($shop) {
            $settings = json_decode($shop->settings ?? '[]', true) ?: [];

            if ($settings === [] || isset($settings['fields'])) {
                return;
            }

            $mode = fn (?string $value, string $default) => $value ?? $default;
            $name = $mode($settings['name_field'] ?? null, 'optional');
            $phone = $mode($settings['phone_field'] ?? null, 'hidden');

            $settings['fields'] = [
                ['key' => 'email', 'enabled' => true, 'required' => true, 'label' => $settings['email_label'] ?? null],
                ['key' => 'full_name', 'enabled' => $name !== 'hidden', 'required' => $name === 'required', 'label' => $settings['name_label'] ?? null],
                ['key' => 'phone', 'enabled' => $phone !== 'hidden', 'required' => $phone === 'required', 'label' => $settings['phone_label'] ?? null],
                ['key' => 'accepts_marketing', 'enabled' => (bool) ($settings['consent_enabled'] ?? true), 'required' => false, 'label' => $settings['consent_label'] ?? null],
            ];

            foreach (['email_label', 'name_label', 'phone_label', 'consent_label', 'name_field', 'phone_field', 'consent_enabled', 'consent_checked'] as $legacy) {
                unset($settings[$legacy]);
            }

            DB::table('shops')->where('id', $shop->id)->update(['settings' => json_encode($settings, JSON_UNESCAPED_UNICODE)]);
        });

        Schema::table('captures', function (Blueprint $table) {
            $table->date('birthday')->nullable()->after('phone');
            $table->boolean('accepts_sms')->default(false)->after('accepts_marketing');
            $table->string('source', 16)->default('popup')->after('accepts_sms');
        });
    }

    public function down(): void
    {
        Schema::table('captures', function (Blueprint $table) {
            $table->dropColumn(['birthday', 'accepts_sms', 'source']);
        });

        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        DB::table('shops')->where('mode', 'off')->update(['is_active' => false]);

        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('mode');
        });
    }
};
