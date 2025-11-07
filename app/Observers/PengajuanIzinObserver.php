<?php

namespace App\Observers;

use App\Models\PengajuanIzin;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PengajuanIzinObserver
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the PengajuanIzin "created" event.
     * Triggered when karyawan submits new izin from mobile
     */
    public function created(PengajuanIzin $pengajuanIzin)
    {
        try {
            // Send notification to all admins
            $this->notificationService->notifyAdminNewIzin($pengajuanIzin);
            
            // Log::info('PengajuanIzin created notification sent', [
            //     'id' => $pengajuanIzin->id
            // ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle the PengajuanIzin "updated" event.
     * Triggered when admin approves or rejects izin
     */
    public function updated(PengajuanIzin $pengajuanIzin)
    {
        try {
            // Check if status changed
            if ($pengajuanIzin->isDirty('status')) {
                $newStatus = $pengajuanIzin->status;
                $oldStatus = $pengajuanIzin->getOriginal('status');

                // Log::info('PengajuanIzin status changed', [
                //     'id' => $pengajuanIzin->id,
                //     'old_status' => $oldStatus,
                //     'new_status' => $newStatus
                // ]);

                // If approved, notify karyawan
                if ($newStatus === 'disetujui' && $oldStatus === 'pending') {
                    $this->notificationService->notifyKaryawanIzinApproved($pengajuanIzin);
                }

                // If rejected, notify karyawan
                if ($newStatus === 'ditolak' && $oldStatus === 'pending') {
                    $this->notificationService->notifyKaryawanIzinRejected($pengajuanIzin);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle the PengajuanIzin "deleting" event.
     */
    public function deleting(PengajuanIzin $pengajuanIzin)
    {
        if ($pengajuanIzin->file_dokumen) {
            try {
                if (Storage::exists('public/' . $pengajuanIzin->file_dokumen)) {
                    Storage::delete('public/' . $pengajuanIzin->file_dokumen);
                    Log::info("File dokumen izin dihapus: {$pengajuanIzin->file_dokumen}");
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }

    /**
     * Handle the PengajuanIzin "force deleted" event.
     */
    public function forceDeleted(PengajuanIzin $pengajuanIzin)
    {
        if ($pengajuanIzin->file_dokumen) {
            try {
                if (Storage::exists('public/' . $pengajuanIzin->file_dokumen)) {
                    Storage::delete('public/' . $pengajuanIzin->file_dokumen);
                    Log::info("File dokumen izin force deleted: {$pengajuanIzin->file_dokumen}");
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }
}