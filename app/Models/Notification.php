<?php
// app/Models/Notification.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

class Notification extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'karyawan_id',
        'type',
        'title',
        'body',
        'data',
        'is_read',
        'read_at',
        'notifiable_type',
        'notifiable_id'
    ];

    protected $casts = [
        'data' => 'array',
        'is_read' => 'boolean',
        'read_at' => 'datetime',
    ];

    protected $appends = ['time_ago'];

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
    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForKaryawan($query, $karyawanId)
    {
        return $query->where('karyawan_id', $karyawanId);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    public function scopeByType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Accessors
    public function getTimeAgoAttribute()
    {
        return $this->created_at->diffForHumans();
    }

    // Methods
    public function markAsRead()
    {
        if (!$this->is_read) {
            $this->update([
                'is_read' => true,
                'read_at' => Carbon::now()
            ]);
        }
    }

    // Static Methods
    public static function createForAdmin($userId, $type, $title, $body, $data = [], $relatedModel = null)
    {
        try {
            $notification = self::create([
                'user_id' => $userId,
                'type' => $type,
                'title' => $title,
                'body' => $body,
                'data' => $data,
                'notifiable_type' => $relatedModel ? get_class($relatedModel) : null,
                'notifiable_id' => $relatedModel ? $relatedModel->id : null
            ]);

            // Log::info('Notification created for admin', [
            //     'notification_id' => $notification->id,
            //     'user_id' => $userId,
            //     'type' => $type
            // ]);

            return $notification;
        } catch (\Exception $e) {
            Log::error('Create admin notification error: ' . $e->getMessage());
            throw $e;
        }
    }

    public static function createForKaryawan($karyawanId, $type, $title, $body, $data, $relatedModel = null)
{
    try {
        $notification = self::create([
            'karyawan_id' => $karyawanId,
            'user_id' => null,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'is_read' => false,
            'related_type' => $relatedModel ? get_class($relatedModel) : null,
            'related_id' => $relatedModel ? $relatedModel->id : null,
        ]);

        // Log::info('Notification created for karyawan', [
        //     'notification_id' => $notification->id,
        //     'karyawan_id' => $karyawanId,
        //     'type' => $type
        // ]);

        return $notification;
    } catch (\Exception $e) {
        Log::error('Failed to create notification for karyawan', [
            'error' => $e->getMessage(),
            'karyawan_id' => $karyawanId,
            'type' => $type
        ]);
        throw $e;
    }
}

    public static function getUnreadCountForUser($userId)
    {
        return self::forUser($userId)->unread()->count();
    }

    public static function getUnreadCountForKaryawan($karyawanId)
    {
        return self::forKaryawan($karyawanId)->unread()->count();
    }

    public static function markAllAsReadForUser($userId)
    {
        try {
            $count = self::forUser($userId)
                        ->unread()
                        ->update([
                            'is_read' => true,
                            'read_at' => Carbon::now()
                        ]);

            Log::info("Marked {$count} notifications as read for user {$userId}");

            return $count;
        } catch (\Exception $e) {
            Log::error('Mark all as read error: ' . $e->getMessage());
            return 0;
        }
    }

    public static function markAllAsReadForKaryawan($karyawanId)
    {
        try {
            $count = self::forKaryawan($karyawanId)
                        ->unread()
                        ->update([
                            'is_read' => true,
                            'read_at' => Carbon::now()
                        ]);

            // Log::info("Marked {$count} notifications as read for karyawan {$karyawanId}");

            return $count;
        } catch (\Exception $e) {
            Log::error('Mark all as read error: ' . $e->getMessage());
            return 0;
        }
    }

    public static function deleteOldNotifications($daysOld = 90)
    {
        try {
            $cutoffDate = Carbon::now()->subDays($daysOld);
            
            $deleted = self::where('is_read', true)
                          ->where('created_at', '<', $cutoffDate)
                          ->delete();

            // Log::info("Deleted {$deleted} old notifications");

            return $deleted;
        } catch (\Exception $e) {
            Log::error('Delete old notifications error: ' . $e->getMessage());
            return 0;
        }
    }
}