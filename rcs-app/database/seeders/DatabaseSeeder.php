<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create admin user for Risk Control Services
        User::updateOrCreate(
            ['email' => 'admin@rcsn.com'],
            [
                'name' => 'RCS Admin',
                'email' => 'admin@rcsn.com',
                'password' => Hash::make('admin@123'),
                'email_verified_at' => now(),
            ]
        );

        $this->command->info('✅ Admin user created successfully!');
        $this->command->info('   Email: admin@rcsn.com');
        $this->command->info('   Password: admin@123');
    }
}
