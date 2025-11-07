<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Karyawan;
use App\Models\FcmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class MobileAuthController extends Controller
{
    /**
     * Login karyawan untuk mobile app
     */
    public function login(Request $request)
    {
        // Validate input
        try {
            $request->validate([
                'username' => 'required|string',
                'password' => 'required|string',
            ], [
                'username.required' => 'Username wajib diisi',
                'password.required' => 'Password wajib diisi',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors(),
            ], 422);
        }

        // Find karyawan
        $karyawan = Karyawan::where('username', $request->username)->first();

        // Check if user exists and password matches
        if (!$karyawan || !Hash::check($request->password, $karyawan->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Username atau password yang Anda masukkan salah',
            ], 422);
        }

        // Check if account is active
        if ($karyawan->status !== 'aktif') {
            return response()->json([
                'success' => false,
                'message' => 'Akun Anda tidak aktif. Silakan hubungi admin.',
            ], 403);
        }

        // Delete old FCM tokens for this karyawan
        try {
            $deletedTokens = FcmToken::where('karyawan_id', $karyawan->id)->delete();
            // Log::info("🗑️ LOGIN: Deleted {$deletedTokens} old FCM token(s) for karyawan: {$karyawan->id}");
        } catch (\Exception $e) {
            throw $e;
        }

        // Delete old Sanctum tokens
        $karyawan->tokens()->delete();

        // Generate new token
        $token = $karyawan->createToken('mobile-app')->plainTextToken;

        // Load relationships
        $karyawan->load(['divisi', 'jabatan', 'activeProject.project']);

        $projectData = null;
        if ($karyawan->activeProject && $karyawan->activeProject->project) {
            $project = $karyawan->activeProject->project;
            
            $tanggalAssign = $karyawan->activeProject->tanggal_assign;
            if ($tanggalAssign instanceof \DateTime || $tanggalAssign instanceof Carbon) {
                $tanggalAssignFormatted = $tanggalAssign->format('Y-m-d');
            } else {
                $tanggalAssignFormatted = $tanggalAssign;
            }
            
            $projectData = [
                'id' => $project->id,
                'nama' => $project->nama,
                'bagian' => $project->bagian,
                'tanggal_assign' => $tanggalAssignFormatted,
            ];
        }

        $formatDate = function($date) {
            if ($date instanceof \DateTime || $date instanceof Carbon) {
                return $date->format('Y-m-d');
            }
            return $date;
        };

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'token' => $token,
                'karyawan' => [
                    'id' => $karyawan->id,
                    'nik' => $karyawan->nik,
                    'nama' => $karyawan->nama,
                    'username' => $karyawan->username,
                    'no_telepon' => $karyawan->no_telepon,
                    'jenis_kelamin' => $karyawan->jenis_kelamin,
                    'tempat_lahir' => $karyawan->tempat_lahir,
                    'tanggal_lahir' => $formatDate($karyawan->tanggal_lahir),
                    'tanggal_bergabung' => $formatDate($karyawan->tanggal_bergabung),
                    'sisa_cuti_tahunan' => (int) $karyawan->sisa_cuti_tahunan,
                    'status' => $karyawan->status,
                    'divisi' => $karyawan->divisi ? [
                        'id' => $karyawan->divisi->id,
                        'nama' => $karyawan->divisi->nama,
                    ] : null,
                    'jabatan' => $karyawan->jabatan ? [
                        'id' => $karyawan->jabatan->id,
                        'nama' => $karyawan->jabatan->nama,
                    ] : null,
                    'project' => $projectData,
                ],
            ],
        ], 200);
    }

    /**
     * Get profile karyawan yang sedang login
     */
    public function me(Request $request)
    {
        $karyawan = $request->user();
        $karyawan->load(['divisi', 'jabatan', 'activeProject.project.shiftProjects']);

        $formatDate = function($date) {
            if ($date instanceof \DateTime || $date instanceof Carbon) {
                return $date->format('Y-m-d');
            }
            return $date;
        };

        $projectData = null;
        if ($karyawan->activeProject && $karyawan->activeProject->project) {
            $project = $karyawan->activeProject->project;
            
            $lokasiData = null;
            if ($project->lokasi) {
                $lokasi = is_string($project->lokasi) ? json_decode($project->lokasi, true) : $project->lokasi;
                $lokasiData = [
                    'nama' => $lokasi['nama'] ?? '',
                    'latitude' => (float)($lokasi['latitude'] ?? 0),
                    'longitude' => (float)($lokasi['longitude'] ?? 0),
                ];
            }

            $shifts = [];
            if ($project->shiftProjects) {
                $shifts = $project->shiftProjects->map(function($shift) {
                    return [
                        'id' => $shift->id,
                        'kode' => $shift->kode,
                        'waktu_mulai' => $shift->waktu_mulai,
                        'waktu_selesai' => $shift->waktu_selesai,
                    ];
                })->toArray();
            }

            $projectData = [
                'id' => $project->id,
                'nama' => $project->nama,
                'bagian' => $project->bagian,
                'lokasi' => $lokasiData,
                'radius' => (int)($project->radius ?? 0),
                'waktu_toleransi' => (int)($project->waktu_toleransi ?? 0),
                'tanggal_assign' => $formatDate($karyawan->activeProject->tanggal_assign),
                'shifts' => $shifts,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'id' => $karyawan->id,
                'nik' => $karyawan->nik,
                'nama' => $karyawan->nama,
                'username' => $karyawan->username,
                'no_telepon' => $karyawan->no_telepon,
                'jenis_kelamin' => $karyawan->jenis_kelamin,
                'tempat_lahir' => $karyawan->tempat_lahir,
                'tanggal_lahir' => $formatDate($karyawan->tanggal_lahir),
                'tanggal_bergabung' => $formatDate($karyawan->tanggal_bergabung),
                'sisa_cuti_tahunan' => (int) $karyawan->sisa_cuti_tahunan,
                'status' => $karyawan->status,
                'divisi' => $karyawan->divisi ? [
                        'id' => $karyawan->divisi->id,
                        'nama' => $karyawan->divisi->nama,
                    ] : null,
                'jabatan' => [
                    'id' => $karyawan->jabatan->id,
                    'nama' => $karyawan->jabatan->nama,
                ],
                'project' => $projectData,
            ],
        ], 200);
    }

    /**
     * Logout karyawan - FIXED WITH PROPER LOGGING & VERIFICATION
     */
    public function logout(Request $request)
    {
        // Log::info('═══════════════════════════════════');
        // Log::info('🔐 LOGOUT REQUEST RECEIVED');
        
        try {
            $karyawan = $request->user();
            $karyawanId = $karyawan->id;
            
            // Log::info("👤 Karyawan ID: {$karyawanId}");
            // Log::info("📧 Karyawan Name: {$karyawan->nama}");

            // Check FCM tokens BEFORE deletion
            $tokensBefore = FcmToken::where('karyawan_id', $karyawanId)->count();
            // Log::info("📊 FCM Tokens BEFORE deletion: {$tokensBefore}");

            // Show all tokens for this karyawan
            $allTokens = FcmToken::where('karyawan_id', $karyawanId)->get();
            foreach ($allTokens as $fcmToken) {
                Log::info("  └─ Token ID: {$fcmToken->id}, Token: " . substr($fcmToken->token, 0, 30) . "...");
            }

            // DELETE ALL FCM TOKENS
            try {
                $deletedCount = DB::table('fcm_tokens')
                    ->where('karyawan_id', $karyawanId)
                    ->delete();
                
                // Log::info("✅ DELETED {$deletedCount} FCM token(s) using DB::table");
            } catch (\Exception $e) {
                // Log::error('❌ Error with DB::table delete: ' . $e->getMessage());
                
                // Fallback to Eloquent
                try {
                    $deletedCount = FcmToken::where('karyawan_id', $karyawanId)->delete();
                    // Log::info("✅ DELETED {$deletedCount} FCM token(s) using Eloquent (fallback)");
                } catch (\Exception $e2) {
                    throw $e2;
                }
            }

            // Verify deletion
            $tokensAfter = FcmToken::where('karyawan_id', $karyawanId)->count();
            // Log::info("📊 FCM Tokens AFTER deletion: {$tokensAfter}");

            if ($tokensAfter > 0) {
                Log::warning("⚠️ WARNING: {$tokensAfter} tokens still remain!");
            } else {
                Log::info("✅ All FCM tokens successfully deleted");
            }

            // Delete current Sanctum token
            $request->user()->currentAccessToken()->delete();
            // Log::info('✅ Sanctum token deleted');
            
            // Log::info('═══════════════════════════════════');
            // Log::info('✅ LOGOUT COMPLETED SUCCESSFULLY');
            // Log::info('═══════════════════════════════════');

            return response()->json([
                'success' => true,
                'message' => 'Logout berhasil',
                'debug' => [
                    'fcm_tokens_deleted' => $deletedCount ?? 0,
                    'tokens_before' => $tokensBefore,
                    'tokens_after' => $tokensAfter,
                ]
            ], 200);

        } catch (\Exception $e) {
            // Log::error('═══════════════════════════════════');
            // Log::error('❌ LOGOUT ERROR: ' . $e->getMessage());
            // Log::error('Stack trace: ' . $e->getTraceAsString());
            // Log::error('═══════════════════════════════════');
            
            return response()->json([
                'success' => false,
                'message' => 'Logout gagal: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Change password
     */
    public function changePassword(Request $request)
    {
        try {
            $request->validate([
                'current_password' => 'required|string',
                'new_password' => 'required|string|min:6|confirmed',
            ], [
                'current_password.required' => 'Password lama wajib diisi',
                'new_password.required' => 'Password baru wajib diisi',
                'new_password.min' => 'Password baru minimal 6 karakter',
                'new_password.confirmed' => 'Konfirmasi password tidak sama',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors(),
            ], 422);
        }

        $karyawan = $request->user();

        if (!Hash::check($request->current_password, $karyawan->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Password lama tidak sesuai',
            ], 422);
        }

        // Update password
        $karyawan->update([
            'password' => Hash::make($request->new_password),
        ]);

        // Delete all FCM tokens
        try {
            $deletedTokens = DB::table('fcm_tokens')
                ->where('karyawan_id', $karyawan->id)
                ->delete();
            // Log::info("✅ PASSWORD CHANGE: Deleted {$deletedTokens} FCM token(s) for karyawan: {$karyawan->id}");
        } catch (\Exception $e) {
            throw $e;
        }

        // Delete all Sanctum tokens
        $karyawan->tokens()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Password berhasil diubah. Silakan login kembali.',
        ], 200);
    }
}