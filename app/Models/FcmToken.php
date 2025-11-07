<?php
// app/Models/FcmToken.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class FcmToken extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'karyawan_id',
        'token',
        'device_type',
        'device_name',
        'last_used_at',
        'is_active'
    ];

    protected $casts = [
        'last_used_at' => 'datetime',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function karyawan()
    {
        return $this->belongsTo(Karyawan::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForKaryawan($query, $karyawanId)
    {
        return $query->where('karyawan_id', $karyawanId);
    }

    // Static Methods
    public static function storeForUser($userId, $token, $deviceType = 'web', $deviceName = null)
    {
        try {
            $fcmToken = self::where('user_id', $userId)
                           ->where('token', $token)
                           ->first();

            if ($fcmToken) {
                // Update existing token
                $fcmToken->update([
                    'device_type' => $deviceType,
                    'device_name' => $deviceName,
                    'last_used_at' => Carbon::now(),
                    'is_active' => true
                ]);
            } else {
                // Create new token
                $fcmToken = self::create([
                    'user_id' => $userId,
                    'token' => $token,
                    'device_type' => $deviceType,
                    'device_name' => $deviceName,
                    'last_used_at' => Carbon::now(),
                    'is_active' => true
                ]);
            }

            // Log::info('FCM token stored for user', [
            //     'user_id' => $userId,
            //     'device_type' => $deviceType
            // ]);

            return $fcmToken;
        } catch (\Exception $e) {
            // Log::error('Store admin FCM token error: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function storeForKaryawan($karyawanId, $token, $deviceType = 'android', $deviceName = null)
    {
        try {
            $fcmToken = self::where('karyawan_id', $karyawanId)
                           ->where('token', $token)
                           ->first();

            if ($fcmToken) {
                // Update existing token
                $fcmToken->update([
                    'device_type' => $deviceType,
                    'device_name' => $deviceName,
                    'last_used_at' => Carbon::now(),
                    'is_active' => true
                ]);
            } else {
                // Create new token
                $fcmToken = self::create([
                    'karyawan_id' => $karyawanId,
                    'token' => $token,
                    'device_type' => $deviceType,
                    'device_name' => $deviceName,
                    'last_used_at' => Carbon::now(),
                    'is_active' => true
                ]);
            }

            // Log::info('FCM token stored for karyawan', [
            //     'karyawan_id' => $karyawanId,
            //     'device_type' => $deviceType
            // ]);

            return $fcmToken;
        } catch (\Exception $e) {
            // Log::error('Store karyawan FCM token error: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function deleteToken($token)
    {
        try {
            $deleted = self::where('token', $token)->delete();
            
            // if ($deleted > 0) {
            //     Log::info('✅ FCM token deleted', [
            //         'token' => substr($token, 0, 20) . '...',
            //         'count' => $deleted
            //     ]);
            // } else {
            //     Log::warning('⚠️ FCM token not found for deletion', [
            //         'token' => substr($token, 0, 20) . '...'
            //     ]);
            // }
            
            return $deleted > 0;
        } catch (\Exception $e) {
            return false;
        }
    }

    public static function getActiveTokensForUser($userId)
    {
        return self::forUser($userId)
                   ->active()
                   ->pluck('token')
                   ->toArray();
    }

    public static function getActiveTokensForKaryawan($karyawanId)
    {
        return self::forKaryawan($karyawanId)
                   ->active()
                   ->pluck('token')
                   ->toArray();
    }

    public static function cleanupInactiveTokens($daysInactive = 90)
    {
        try {
            $cutoffDate = Carbon::now()->subDays($daysInactive);
            
            $deleted = self::where('is_active', false)
                          ->where('last_used_at', '<', $cutoffDate)
                          ->delete();

            // Log::info("Cleaned up {$deleted} inactive FCM tokens");

            return $deleted;
        } catch (\Exception $e) {
            return 0;
        }
    }

    public function markAsUsed()
    {
        $this->update([
            'last_used_at' => Carbon::now(),
            'is_active' => true
        ]);
    }

    public function deactivate()
    {
        $this->update(['is_active' => false]);
    }
}