<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $user = User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        $user->protector_public_key = '0de3fd0e238d01d57fb103b53f3581dded0b02a9741942e85368a4a7d216a32a';
        $user->save();

        $user->tokens()->create(['name' => 'Test Token', 'token' => 'cb519916c2f7a642839ac475db7f4bc7847b4bb5080add064b2b25d644f271bf', 'abilities' => ['protector:import']]);
    }
}
