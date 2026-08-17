<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the single system owner user for lavss monitor.
     */
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => env('DEV_ADMIN_EMAIL', 'lavss@lavss.ru')],
            [
                'name' => env('DEV_ADMIN_NAME', 'lavss-ru'),
                'password' => Hash::make(env('DEV_ADMIN_PASSWORD', 'password')),
            ]
        );
    }
}
