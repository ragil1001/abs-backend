<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     * 
     * Creates default admin user with credentials:
     * - Username: admin
     * - Password: admin123
     */
    public function run(): void
    {
        $adminExists = User::where('username', 'admin')->exists();

        if (!$adminExists) {
            User::create([
                'username' => 'admin',
                'password' => Hash::make('admin123'),
            ]);
        }
    }
}