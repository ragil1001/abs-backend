<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\FcmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NotificationController extends Controller
{
    /**
     * Get notifications for authenticated admin user (web)
     */
    public function getAdminNotifications(Request $request)
    {
        try {
            $user = $request->user();
            
            $perPage = $request->input('per_page', 20);
            $onlyUnread = $request->input('only_unread', false);

            $query = Notification::forUser($user->id)
                                 ->orderBy('created_at', 'desc');

            if ($onlyUnread) {
                $query->unread();
            }

            $notifications = $query->paginate($perPage);

            $data = $notifications->map(function($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'data' => $notification->data,
                    'is_read' => $notification->is_read,
                    'read_at' => $notification->read_at?->format('Y-m-d H:i:s'),
                    'time_ago' => $notification->time_ago,
                    'created_at' => $notification->created_at->format('Y-m-d H:i:s')
                ];
            });

            $unreadCount = Notification::getUnreadCountForUser($user->id);

            return response()->json([
                'success' => true,
                'data' => $data,
                'unread_count' => $unreadCount,
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Get admin notifications error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get notifications for authenticated karyawan (mobile)
     */
    public function getKaryawanNotifications(Request $request)
    {
        try {
            $karyawan = $request->user();
            
            $perPage = $request->input('per_page', 20);
            $onlyUnread = $request->input('only_unread', false);

            $query = Notification::forKaryawan($karyawan->id)
                                 ->orderBy('created_at', 'desc');

            if ($onlyUnread) {
                $query->unread();
            }

            $notifications = $query->paginate($perPage);

            $data = $notifications->map(function($notification) {
                return [
                    'id' => $notification->id,
                    'type' => $notification->type,
                    'title' => $notification->title,
                    'body' => $notification->body,
                    'data' => $notification->data,
                    'is_read' => $notification->is_read,
                    'read_at' => $notification->read_at?->format('Y-m-d H:i:s'),
                    'time_ago' => $notification->time_ago,
                    'created_at' => $notification->created_at->format('Y-m-d H:i:s')
                ];
            });

            $unreadCount = Notification::getUnreadCountForKaryawan($karyawan->id);

            return response()->json([
                'success' => true,
                'data' => $data,
                'unread_count' => $unreadCount,
                'pagination' => [
                    'current_page' => $notifications->currentPage(),
                    'per_page' => $notifications->perPage(),
                    'total' => $notifications->total(),
                    'last_page' => $notifications->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            // Log::error('Get karyawan notifications error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unread count for admin
     */
    public function getAdminUnreadCount(Request $request)
    {
        try {
            $user = $request->user();
            $count = Notification::getUnreadCountForUser($user->id);

            return response()->json([
                'success' => true,
                'unread_count' => $count
            ]);

        } catch (\Exception $e) {
            // Log::error('Get admin unread count error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat jumlah notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get unread count for karyawan
     */
    public function getKaryawanUnreadCount(Request $request)
    {
        try {
            $karyawan = $request->user();
            $count = Notification::getUnreadCountForKaryawan($karyawan->id);

            return response()->json([
                'success' => true,
                'unread_count' => $count
            ]);

        } catch (\Exception $e) {
            // Log::error('Get karyawan unread count error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat jumlah notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
 * Mark notification as read
 */
public function markAsRead(Request $request, $notificationId)
{
    try {
        $user = $request->user();
        
        $notification = Notification::findOrFail($notificationId);

        // Check ownership - FIXED LOGIC
        $isOwner = false;
        
        if ($notification->user_id) {
            $isOwner = ($user instanceof \App\Models\User) && ($notification->user_id === $user->id);
        }
        
        if ($notification->karyawan_id) {
            $isOwner = ($user instanceof \App\Models\Karyawan) && ($notification->karyawan_id === $user->id);
        }

        if (!$isOwner) {
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk notifikasi ini'
            ], 403);
        }

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi ditandai sebagai dibaca'
        ]);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Notifikasi tidak ditemukan'
        ], 404);
    } catch (\Exception $e) {
        // Log::error('Mark notification as read error: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal menandai notifikasi: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Mark all notifications as read
     */
    public function markAllAsRead(Request $request)
    {
        try {
            $user = $request->user();

            // Determine if admin or karyawan
            if ($user instanceof \App\Models\User) {
                // Admin
                $count = Notification::markAllAsReadForUser($user->id);
            } else {
                // Karyawan
                $count = Notification::markAllAsReadForKaryawan($user->id);
            }

            return response()->json([
                'success' => true,
                'message' => 'Semua notifikasi ditandai sebagai dibaca',
                'count' => $count
            ]);

        } catch (\Exception $e) {
            // Log::error('Mark all as read error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai semua notifikasi: ' . $e->getMessage()
            ], 500);
        }
    }

   /**
 * Delete notification
 */
public function delete(Request $request, $notificationId)
{
    try {
        $user = $request->user();
        
        $notification = Notification::findOrFail($notificationId);

        // Simplified ownership check
        $isOwner = false;
        
        // Get the guard name to determine user type
        $guard = $request->user() ? get_class($request->user()) : null;
        
        // Log::info('Delete notification debug', [
        //     'notification_id' => $notificationId,
        //     'user_id' => $notification->user_id,
        //     'karyawan_id' => $notification->karyawan_id,
        //     'current_user_id' => $user->id,
        //     'guard' => $guard
        // ]);

        // Check if user owns this notification
        if ($notification->user_id && $notification->user_id == $user->id) {
            $isOwner = true;
        }
        
        if ($notification->karyawan_id && $notification->karyawan_id == $user->id) {
            $isOwner = true;
        }

        if (!$isOwner) {
            // Log::warning('Unauthorized delete attempt', [
            //     'notification_id' => $notificationId,
            //     'user_id' => $user->id,
            //     'notification_owner' => [
            //         'user_id' => $notification->user_id,
            //         'karyawan_id' => $notification->karyawan_id
            //     ]
            // ]);
            
            return response()->json([
                'success' => false,
                'message' => 'Anda tidak memiliki akses untuk menghapus notifikasi ini'
            ], 403);
        }

        $notification->delete();

        return response()->json([
            'success' => true,
            'message' => 'Notifikasi berhasil dihapus'
        ]);

    } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
        return response()->json([
            'success' => false,
            'message' => 'Notifikasi tidak ditemukan'
        ], 404);
    } catch (\Exception $e) {
        // Log::error('Delete notification error: ' . $e->getMessage(), [
        //     'notification_id' => $notificationId,
        //     'trace' => $e->getTraceAsString()
        // ]);
        
        return response()->json([
            'success' => false,
            'message' => 'Gagal menghapus notifikasi: ' . $e->getMessage()
        ], 500);
    }
}

    /**
     * Store FCM token for admin (web)
     */
    public function storeAdminToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'device_name' => 'nullable|string|max:255'
        ]);

        try {
            $user = $request->user();

            FcmToken::storeForUser(
                $user->id,
                $request->token,
                'web',
                $request->device_name
            );

            return response()->json([
                'success' => true,
                'message' => 'FCM token berhasil disimpan'
            ]);

        } catch (\Exception $e) {
            // Log::error('Store admin FCM token error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan FCM token: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Store FCM token for karyawan (mobile)
     */
    public function storeKaryawanToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'device_type' => 'required|in:android,ios',
            'device_name' => 'nullable|string|max:255'
        ]);

        try {
            $karyawan = $request->user();

            FcmToken::storeForKaryawan(
                $karyawan->id,
                $request->token,
                $request->device_type,
                $request->device_name
            );

            return response()->json([
                'success' => true,
                'message' => 'FCM token berhasil disimpan'
            ]);

        } catch (\Exception $e) {
            // Log::error('Store karyawan FCM token error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan FCM token: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete FCM token (for logout)
     */
    public function deleteToken(Request $request)
    {
        $request->validate([
            'token' => 'required|string'
        ]);

        try {
            FcmToken::deleteToken($request->token);

            return response()->json([
                'success' => true,
                'message' => 'FCM token berhasil dihapus'
            ]);

        } catch (\Exception $e) {
            // Log::error('Delete FCM token error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus FCM token: ' . $e->getMessage()
            ], 500);
        }
    }
}