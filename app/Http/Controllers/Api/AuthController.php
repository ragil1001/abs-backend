<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Karyawan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // public function registerAdmin(Request $request)
    // {
    //     $request->validate([
    //         'username' => 'required|string|max:255|unique:users',
    //         'password' => 'required|string|min:6|confirmed',
    //     ]);

    //     $user = User::create([
    //         'username' => $request->username,
    //         'password' => Hash::make($request->password),
    //     ]);

    //     $token = $user->createToken('admin-token')->plainTextToken;

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Admin registered successfully',
    //         'data' => [
    //             'user' => $user,
    //             'token' => $token,
    //             'token_type' => 'Bearer'
    //         ]
    //     ], 201);
    // }

    public function loginAdmin(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $user = User::where('username', $request->username)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('admin-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    public function loginKaryawan(Request $request)
    {
        $request->validate([
            'username' => 'required|string',
            'password' => 'required|string',
        ]);

        $karyawan = Karyawan::where('username', $request->username)
                           ->where('status', 'aktif')
                           ->first();

        if (!$karyawan || !Hash::check($request->password, $karyawan->password)) {
            throw ValidationException::withMessages([
                'username' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $karyawan->createToken('karyawan-token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'karyawan' => $karyawan->load(['divisi', 'jabatan']),
                'token' => $token,
                'token_type' => 'Bearer'
            ]
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Logged out successfully'
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'success' => true,
            'data' => $request->user()
        ]);
    }
    
    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required|string',
            'new_password' => 'required|string|min:6|confirmed',
        ], [
            'current_password.required' => 'Password lama wajib diisi',
            'new_password.required' => 'Password baru wajib diisi',
            'new_password.min' => 'Password baru minimal 6 karakter',
            'new_password.confirmed' => 'Konfirmasi password tidak cocok',
        ]);

        $user = $request->user();

        
        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password lama tidak sesuai',
                'errors' => [
                    'current_password' => ['Password lama tidak sesuai']
                ]
            ], 422);
        }

        
        if (Hash::check($request->new_password, $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password baru harus berbeda dengan password lama',
                'errors' => [
                    'new_password' => ['Password baru harus berbeda dengan password lama']
                ]
            ], 422);
        }

        
        $user->update([
            'password' => Hash::make($request->new_password)
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah'
        ]);
    }
}