<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Notification;
use App\Models\FcmToken;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage; 

class CleanupOldNotifications extends Command
{
    protected $signature = 'notifications:cleanup-old';
    protected $description = 'Cleanup old read notifications and inactive FCM tokens (TESTING MODE - 2 hours)';

    public function handle()
    {
        $this->info('⚠️  TESTING MODE: Cleanup notifikasi & token lebih dari 2 jam');
        
        $cutoffTime = Carbon::now()->subHours(1)->subMinutes(30);
        $this->info("Cutoff time: {$cutoffTime->format('Y-m-d H:i:s')}");
        $this->info("Current time: " . Carbon::now()->format('Y-m-d H:i:s'));
        
        
        $this->info('Membersihkan notifikasi lama...');
        $notificationsCleaned = $this->deleteOldNotifications($cutoffTime);
        
        
        $this->info('Membersihkan FCM token tidak aktif...');
        $tokensCleaned = $this->cleanupInactiveTokens($cutoffTime);
        
        $this->info("Selesai!");
        $this->info("Notifikasi dibersihkan: {$notificationsCleaned}");
        $this->info("FCM token dibersihkan: {$tokensCleaned}");
        
        Log::info("Notification cleanup selesai - Notifications: {$notificationsCleaned}, Tokens: {$tokensCleaned}");

        return 0;
    }

    private function deleteOldNotifications($cutoffTime)
    {
        
        $deleted = Notification::where('is_read', true)
            ->where('read_at', '<', $cutoffTime)
            ->delete();

        $this->line("  - Deleted {$deleted} read notifications");

        return $deleted;
    }

    private function cleanupInactiveTokens($cutoffTime)
    {
        
        $deleted = FcmToken::where('is_active', false)
            ->where('updated_at', '<', $cutoffTime)
            ->delete();

        $this->line("  - Deleted {$deleted} inactive tokens");

        
        $staleDeleted = FcmToken::where('is_active', true)
            ->where('updated_at', '<', $cutoffTime->copy()->subHours(2)) 
            ->delete();

        if ($staleDeleted > 0) {
            $this->line("  - Deleted {$staleDeleted} stale active tokens");
        }

        return $deleted + $staleDeleted;
    }
}