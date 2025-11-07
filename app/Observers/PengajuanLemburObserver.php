<?php

namespace App\Observers;

use App\Models\PengajuanLembur;
use App\Services\NotificationService;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PengajuanLemburObserver
{
    protected $notificationService;

    public function __construct(NotificationService $notificationService)
    {
        $this->notificationService = $notificationService;
    }

    /**
     * Handle the PengajuanLembur "created" event.
     * Triggered when karyawan submits new lembur from mobile
     */
    public function created(PengajuanLembur $pengajuanLembur)
    {
        try {
            // Send notification to all admins
            $this->notificationService->notifyAdminNewLembur($pengajuanLembur);
            
            // Log::info('PengajuanLembur created notification sent', [
            //     'id' => $pengajuanLembur->id
            // ]);
        } catch (\Exception $e) {
            throw $e;
        }
    }

    /**
     * Handle the PengajuanLembur "deleting" event.
     */
    public function deleting(PengajuanLembur $pengajuanLembur)
    {
        if ($pengajuanLembur->file_skl) {
            try {
                if (Storage::exists('public/' . $pengajuanLembur->file_skl)) {
                    Storage::delete('public/' . $pengajuanLembur->file_skl);
                    Log::info("File SKL dihapus: {$pengajuanLembur->file_skl}");
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }

    /**
     * Handle the PengajuanLembur "force deleted" event.
     */
    public function forceDeleted(PengajuanLembur $pengajuanLembur)
    {
        if ($pengajuanLembur->file_skl) {
            try {
                if (Storage::exists('public/' . $pengajuanLembur->file_skl)) {
                    Storage::delete('public/' . $pengajuanLembur->file_skl);
                    Log::info("File SKL force deleted: {$pengajuanLembur->file_skl}");
                }
            } catch (\Exception $e) {
                throw $e;
            }
        }
    }

    public function updated(PengajuanLembur $pengajuanLembur)
    {
        try {
            // Check if status changed
            if ($pengajuanLembur->isDirty('status')) {
                $newStatus = $pengajuanLembur->status;
                $oldStatus = $pengajuanLembur->getOriginal('status');

                // Log::info('PengajuanLembur status changed', [
                //     'id' => $pengajuanLembur->id,
                //     'old_status' => $oldStatus,
                //     'new_status' => $newStatus
                // ]);

                // If approved, notify karyawan
                if ($newStatus === 'disetujui' && $oldStatus === 'pending') {
                    $this->notificationService->notifyKaryawanLemburApproved($pengajuanLembur);
                }

                // If rejected, notify karyawan
                if ($newStatus === 'ditolak' && $oldStatus === 'pending') {
                    $this->notificationService->notifyKaryawanLemburRejected($pengajuanLembur);
                }
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }
}