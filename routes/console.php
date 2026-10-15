<?php

use App\Models\User;
use Illuminate\Support\Facades\Artisan;

/*
 * Runs on every boot (docker/start.sh). Creates the first admin from ADMIN_EMAIL /
 * ADMIN_PASSWORD; an existing user is left alone, so the password is never reset.
 */
Artisan::command('app:ensure-admin', function () {
    $email = env('ADMIN_EMAIL');
    $password = env('ADMIN_PASSWORD');

    if (! $email || ! $password) {
        $this->info('ADMIN_EMAIL / ADMIN_PASSWORD not set — skipping.');

        return;
    }

    $user = User::query()->firstOrCreate(
        ['email' => strtolower($email)],
        ['name' => 'Admin', 'password' => $password],
    );

    $this->info($user->wasRecentlyCreated ? "Admin {$user->email} created." : "Admin {$user->email} already exists.");
})->purpose('Create the first admin user from ADMIN_EMAIL / ADMIN_PASSWORD');
