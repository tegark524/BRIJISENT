<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    // LOGIKA LOGIN
    public function login(Request $request)
{
    // 1. Wajib isi email & password
    $request->validate([
        'email' => 'required|email',
        'password' => 'required'
    ]);

    // 2. Cari user berdasarkan email
    $user = User::where('email', $request->email)->first();

    // 3. Cek apakah user ada dan password hash-nya cocok
    if (!$user || !Hash::check($request->password, $user->password)) {
        return response()->json([
            'success' => false,
            'message' => 'Email atau Password salah!'
        ], 401);
    }

    // 4. Lolos!
    return response()->json([
        'success' => true,
        'user' => $user
    ]);

}
}
