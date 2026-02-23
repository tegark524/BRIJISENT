<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Bikin Akun HR Default (User Zero)
        User::create([
            'name' => 'Admin HR BRIJISENT',
            'email' => 'admin@brijisent.com', // Ini yang dipakai buat Login
            'phone' => '081234567890',
            'password' => Hash::make('123456'), // Password gampang buat ngetes
            'role' => 'hr',
            'is_active' => true, // Langsung aktif tanpa perlu scan wajah
        ]);

        // Boleh ditambah 1 akun Intern buat ngetes sekalian kalau mau
        User::create([
            'name' => 'Tegar Satria Kirana',
            'email' => 'tegark524@gmail.com',
            'phone' => '089876543210',
            'password' => Hash::make('123456'),
            'role' => 'intern',
            'is_active' => false, // Sengaja false biar ngetes fitur daftar wajah nanti
        ]);
    }
}
