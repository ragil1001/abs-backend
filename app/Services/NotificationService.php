<?php

namespace App\Services;

use App\Models\Notification;
use App\Models\User;
use App\Models\Karyawan;
use App\Models\PengajuanIzin;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Carbon\Carbon;

class NotificationService
{
    protected $firebaseService;

    public function __construct(FirebaseService $firebaseService)
    {
        $this->firebaseService = $firebaseService;
    }

    private function getFrontendUrl()
    {
        return env('FRONTEND_URL', 'http://localhost:3000');
    }

    // ========================================
    // PENGAJUAN IZIN NOTIFICATIONS
    // ========================================

    /**
     * Send notification to admin (web) when karyawan submits izin
     */
    public function notifyAdminNewIzin($pengajuanIzin)
    {
        try {
            // ✅ EAGER LOAD all relationships to prevent null values
            $pengajuanIzin->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $karyawan = $pengajuanIzin->jadwalKaryawan->karyawanProject->karyawan;
            $project = $pengajuanIzin->jadwalKaryawan->karyawanProject->project;

            $title = 'Pengajuan Izin Baru';
            $body = "{$karyawan->nama} mengajukan {$pengajuanIzin->kategori_izin} di project {$project->nama_project}";

            $data = [
                'type' => 'izin_pending',
                'pengajuan_izin_id' => (string) $pengajuanIzin->id,
                'karyawan_id' => (string) $karyawan->id,
                'karyawan_nama' => $karyawan->nama,
                'karyawan_nik' => $karyawan->nik,
                'kategori_izin' => $pengajuanIzin->kategori_izin,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'tanggal_mulai' => $pengajuanIzin->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $pengajuanIzin->tanggal_selesai->format('Y-m-d'),
                'durasi_hari' => (string) $pengajuanIzin->durasi_hari
            ];

            // Get all admin users
            $adminUsers = User::all();

            foreach ($adminUsers as $admin) {
                // Create notification record in database
                Notification::createForAdmin(
                    $admin->id,
                    'izin_pending',
                    $title,
                    $body,
                    $data,
                    $pengajuanIzin
                );

                // Send push notification
                $this->firebaseService->sendToUser($admin->id, $title, $body, $data);
            }

            // Log::info('Admin notified for new izin', [
            //     'pengajuan_izin_id' => $pengajuanIzin->id,
            //     'karyawan' => $karyawan->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify admin for new izin', [
            //     'error' => $e->getMessage(),
            //     'pengajuan_izin_id' => $pengajuanIzin->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Send notification to karyawan (mobile) when izin is approved
     */
    public function notifyKaryawanIzinApproved($pengajuanIzin)
    {
        try {
            // ✅ EAGER LOAD relationships
            $pengajuanIzin->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $karyawan = $pengajuanIzin->jadwalKaryawan->karyawanProject->karyawan;
            $project = $pengajuanIzin->jadwalKaryawan->karyawanProject->project;

            $title = 'Pengajuan Izin Disetujui';
            $body = "Pengajuan {$pengajuanIzin->kategori_izin} Anda di project {$project->nama_project} telah disetujui";

            if ($pengajuanIzin->catatan_admin) {
                $body .= ": " . $pengajuanIzin->catatan_admin;
            }

            $data = [
                'type' => 'izin_approved',
                'pengajuan_izin_id' => (string) $pengajuanIzin->id,
                'kategori_izin' => $pengajuanIzin->kategori_izin,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'tanggal_mulai' => $pengajuanIzin->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $pengajuanIzin->tanggal_selesai->format('Y-m-d'),
                'durasi_hari' => (string) $pengajuanIzin->durasi_hari,
                'catatan_admin' => $pengajuanIzin->catatan_admin ?? '',
                'screen' => 'izin_detail',
                'screen_params' => json_encode(['id' => $pengajuanIzin->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $karyawan->id,
                'izin_approved',
                $title,
                $body,
                $data,
                $pengajuanIzin
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Karyawan notified for approved izin', [
            //     'pengajuan_izin_id' => $pengajuanIzin->id,
            //     'karyawan' => $karyawan->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify karyawan for approved izin', [
            //     'error' => $e->getMessage(),
            //     'pengajuan_izin_id' => $pengajuanIzin->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Send notification to karyawan (mobile) when izin is rejected
     */
    public function notifyKaryawanIzinRejected($pengajuanIzin)
    {
        try {
            // ✅ EAGER LOAD relationships
            $pengajuanIzin->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $karyawan = $pengajuanIzin->jadwalKaryawan->karyawanProject->karyawan;
            $project = $pengajuanIzin->jadwalKaryawan->karyawanProject->project;

            $title = 'Pengajuan Izin Ditolak';
            $body = "Pengajuan {$pengajuanIzin->kategori_izin} Anda di project {$project->nama_project} ditolak";

            if ($pengajuanIzin->catatan_admin) {
                $body .= ": " . $pengajuanIzin->catatan_admin;
            }

            $data = [
                'type' => 'izin_rejected',
                'pengajuan_izin_id' => (string) $pengajuanIzin->id,
                'kategori_izin' => $pengajuanIzin->kategori_izin,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'tanggal_mulai' => $pengajuanIzin->tanggal_mulai->format('Y-m-d'),
                'tanggal_selesai' => $pengajuanIzin->tanggal_selesai->format('Y-m-d'),
                'durasi_hari' => (string) $pengajuanIzin->durasi_hari,
                'catatan_admin' => $pengajuanIzin->catatan_admin ?? '',
                'screen' => 'izin_detail',
                'screen_params' => json_encode(['id' => $pengajuanIzin->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $karyawan->id,
                'izin_rejected',
                $title,
                $body,
                $data,
                $pengajuanIzin
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Karyawan notified for rejected izin', [
            //     'pengajuan_izin_id' => $pengajuanIzin->id,
            //     'karyawan' => $karyawan->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify karyawan for rejected izin', [
            //     'error' => $e->getMessage(),
            //     'pengajuan_izin_id' => $pengajuanIzin->id ?? null
            // ]);

            return false;
        }
    }

    // ========================================
    // PRESENSI ALPA NOTIFICATIONS
    // ========================================

    public function notifyKaryawanAlpa($presensi)
    {
        try {
            // Log::info('📢 Starting notifyKaryawanAlpa', [
            //     'presensi_id' => $presensi->id
            // ]);

            // ✅ EAGER LOAD all relationships
            $presensi->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project.shiftProjects'
            ]);

            $jadwal = $presensi->jadwalKaryawan;
            if (!$jadwal) {
                // Log::error('Jadwal not found for presensi', ['presensi_id' => $presensi->id]);
                return false;
            }

            $karyawanProject = $jadwal->karyawanProject;
            if (!$karyawanProject) {
                // Log::error('KaryawanProject not found', ['jadwal_id' => $jadwal->id]);
                return false;
            }

            $karyawan = $karyawanProject->karyawan;
            if (!$karyawan) {
                // Log::error('Karyawan not found', ['karyawan_project_id' => $karyawanProject->id]);
                return false;
            }

            $project = $karyawanProject->project;
            if (!$project) {
                // Log::error('Project not found', ['karyawan_project_id' => $karyawanProject->id]);
                return false;
            }

            // Log::info('✅ All relationships loaded', [
            //     'karyawan_id' => $karyawan->id,
            //     'karyawan_nama' => $karyawan->nama,
            //     'project_id' => $project->id,
            //     'project_nama' => $project->nama_project
            // ]);

            // Get shift info
            $shift = $project->shiftProjects->where('kode', $jadwal->shift_code)->first();

            // Format tanggal ke bahasa Indonesia MANUAL
            $tanggalObj = Carbon::parse($jadwal->tanggal);
            $tanggalFormatted = $this->formatTanggalIndonesia($tanggalObj);

            $title = 'Presensi Tidak Hadir';
            $body = "Anda tidak melakukan presensi masuk pada " .
                $tanggalFormatted .
                " shift {$jadwal->shift_code}";

            if ($shift) {
                $body .= " ({$shift->waktu_mulai} - {$shift->waktu_selesai})";
            }

            // Add project name to body
            $body .= " di project {$project->nama_project}";

            $data = [
                'type' => 'presensi_alpa',
                'jadwal_id' => (string) $jadwal->id,
                'tanggal' => $jadwal->tanggal,
                'shift_code' => $jadwal->shift_code,
                'shift_waktu' => $shift ? "{$shift->waktu_mulai} - {$shift->waktu_selesai}" : null,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'status' => 'alpa',
                'screen' => 'data_absensi',
                'screen_params' => json_encode(['tanggal' => $jadwal->tanggal])
            ];

            // Log::info('📝 Creating notification in database', [
            //     'karyawan_id' => $karyawan->id,
            //     'type' => 'presensi_alpa',
            //     'title' => $title,
            //     'body' => $body
            // ]);

            // Create notification record in database
            $notification = Notification::createForKaryawan(
                $karyawan->id,
                'presensi_alpa',
                $title,
                $body,
                $data,
                $presensi
            );

            if (!$notification) {
                // Log::error('Failed to create notification in database');
                return false;
            }

            // Log::info('✅ Notification created in database', [
            //     'notification_id' => $notification->id
            // ]);

            // Send push notification
            // Log::info('📱 Sending push notification', [
            //     'karyawan_id' => $karyawan->id,
            //     'title' => $title
            // ]);

            $pushResult = $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('📤 Push notification result', [
            //     'karyawan_id' => $karyawan->id,
            //     'result' => $pushResult
            // ]);

            // Log::info('✅ Karyawan notified for alpa', [
            //     'karyawan_id' => $karyawan->id,
            //     'karyawan_nama' => $karyawan->nama,
            //     'jadwal_id' => $jadwal->id,
            //     'tanggal' => $jadwal->tanggal,
            //     'shift' => $jadwal->shift_code,
            //     'project' => $project->nama_project,
            //     'notification_id' => $notification->id
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify karyawan for alpa', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            //     'presensi_id' => $presensi->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Format tanggal ke bahasa Indonesia
     * Contoh: Senin, 12 Oktober 2025
     */
    private function formatTanggalIndonesia($date)
    {
        $days = [
            'Monday' => 'Senin',
            'Tuesday' => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday' => 'Kamis',
            'Friday' => 'Jumat',
            'Saturday' => 'Sabtu',
            'Sunday' => 'Minggu'
        ];

        $months = [
            1 => 'Januari',
            2 => 'Februari',
            3 => 'Maret',
            4 => 'April',
            5 => 'Mei',
            6 => 'Juni',
            7 => 'Juli',
            8 => 'Agustus',
            9 => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember'
        ];

        $dayName = $days[$date->format('l')] ?? $date->format('l');
        $day = $date->format('d');
        $month = $months[$date->format('n')] ?? $date->format('F');
        $year = $date->format('Y');

        return "{$dayName}, {$day} {$month} {$year}";
    }

    /**
     * Send notification to karyawan when marked as tidak presensi pulang
     */
    public function notifyKaryawanTidakPresensiPulang($presensi)
    {
        try {
            // ✅ EAGER LOAD relationships
            $presensi->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project.shiftProjects'
            ]);

            $jadwal = $presensi->jadwalKaryawan;
            $karyawan = $jadwal->karyawanProject->karyawan;
            $project = $jadwal->karyawanProject->project;

            // Get shift info
            $shift = $project->shiftProjects->where('kode', $jadwal->shift_code)->first();

            $tanggalObj = Carbon::parse($jadwal->tanggal);
            $tanggalFormatted = $this->formatTanggalIndonesia($tanggalObj);

            $title = 'Tidak Presensi Pulang';
            $body = "Anda tidak melakukan presensi pulang pada " .
                $tanggalFormatted .
                " shift {$jadwal->shift_code}";

            if ($shift) {
                $body .= " ({$shift->waktu_mulai} - {$shift->waktu_selesai})";
            }

            // Add project name
            $body .= " di project {$project->nama_project}";

            $data = [
                'type' => 'presensi_tidak_pulang',
                'jadwal_id' => (string) $jadwal->id,
                'tanggal' => $jadwal->tanggal,
                'shift_code' => $jadwal->shift_code,
                'shift_waktu' => $shift ? "{$shift->waktu_mulai} - {$shift->waktu_selesai}" : null,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'status' => 'tidak_presensi_pulang',
                'screen' => 'data_absensi',
                'screen_params' => json_encode(['tanggal' => $jadwal->tanggal])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $karyawan->id,
                'presensi_tidak_pulang',
                $title,
                $body,
                $data,
                $presensi
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Karyawan notified for tidak presensi pulang', [
            //     'karyawan_id' => $karyawan->id,
            //     'karyawan_nama' => $karyawan->nama,
            //     'jadwal_id' => $jadwal->id,
            //     'tanggal' => $jadwal->tanggal,
            //     'shift' => $jadwal->shift_code,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify karyawan for tidak presensi pulang', [
            //     'error' => $e->getMessage(),
            //     'presensi_id' => $presensi->id ?? null
            // ]);

            return false;
        }
    }

    public function sendReminderNotification($karyawanId, $title, $body, $data)
    {
        try {
            // Log::info('📢 Sending reminder notification', [
            //     'karyawan_id' => $karyawanId,
            //     'title' => $title,
            //     'type' => $data['type'] ?? 'unknown'
            // ]);

            // Kirim push notification LANGSUNG (tidak disimpan ke DB)
            $result = $this->firebaseService->sendToKaryawan(
                $karyawanId,
                $title,
                $body,
                $data
            );

            // ✅ FIX: Comprehensive result checking
            $success = false;
            $successCount = 0;

            if (is_array($result)) {
                // Case 1: Standard success response
                if (isset($result['success']) && $result['success'] === true) {
                    $success = true;

                    // Try to get count from various possible keys
                    if (isset($result['success_count'])) {
                        $successCount = $result['success_count'];
                    } elseif (isset($result['sent'])) {
                        $successCount = $result['sent'];
                    } else {
                        $successCount = 1; // Default to 1 if no count specified but success=true
                    }
                }
                // Case 2: success_count exists (multicast response)
                elseif (isset($result['success_count'])) {
                    $successCount = (int) $result['success_count'];
                    $success = $successCount > 0;
                }
                // Case 3: Check for 'sent' key
                elseif (isset($result['sent'])) {
                    $successCount = (int) $result['sent'];
                    $success = $successCount > 0;
                }
                // Case 4: Check if there's message_id (single send success)
                elseif (isset($result['message_id'])) {
                    $success = true;
                    $successCount = 1;
                }
            } elseif (is_bool($result)) {
                $success = $result;
                $successCount = $success ? 1 : 0;
            }

            if ($success && $successCount > 0) {
                // Log::info('✅ Reminder notification sent successfully', [
                //     'karyawan_id' => $karyawanId,
                //     'title' => $title,
                //     'success_count' => $successCount,
                //     'full_result' => $result
                // ]);
                return true;
            } else {
                // Log::warning('⚠️ Reminder notification failed to send', [
                //     'karyawan_id' => $karyawanId,
                //     'title' => $title,
                //     'success' => $success,
                //     'success_count' => $successCount,
                //     'full_result' => $result
                // ]);
                return false;
            }
        } catch (\Exception $e) {
            // Log::error('❌ Error sending reminder notification', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            //     'karyawan_id' => $karyawanId,
            //     'title' => $title
            // ]);

            return false;
        }
    }

    // Continue with other methods...
    // (Tukar shift, Lembur, etc. - apply same eager loading pattern)

    /**
     * Send notification to target karyawan when someone requests shift swap
     */
    public function notifyKaryawanNewTukarShift($tukarShift)
    {
        try {
            // ✅ EAGER LOAD relationships
            $tukarShift->load([
                'peminta',
                'target',
                'jadwalPeminta',
                'jadwalTarget',
                'project'
            ]);

            $peminta = $tukarShift->peminta;
            $target = $tukarShift->target;
            $jadwalPeminta = $tukarShift->jadwalPeminta;
            $jadwalTarget = $tukarShift->jadwalTarget;
            $project = $tukarShift->project;

            $title = 'Permintaan Tukar Shift Baru';
            $body = "{$peminta->nama} ingin menukar shift dengan Anda di project {$project->nama_project}";

            $data = [
                'type' => 'tukar_shift_request',
                'tukar_shift_id' => (string) $tukarShift->id,
                'peminta_id' => (string) $peminta->id,
                'peminta_nama' => $peminta->nama,
                'peminta_nik' => $peminta->nik,
                'target_id' => (string) $target->id,
                'target_nama' => $target->nama,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'jadwal_peminta_tanggal' => $jadwalPeminta->tanggal,
                'jadwal_peminta_shift' => $jadwalPeminta->shift_code,
                'jadwal_target_tanggal' => $jadwalTarget->tanggal,
                'jadwal_target_shift' => $jadwalTarget->shift_code,
                'catatan' => $tukarShift->catatan ?? '',
                'screen' => 'tukar_shift_detail',
                'screen_params' => json_encode(['id' => $tukarShift->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $target->id,
                'tukar_shift_request',
                $title,
                $body,
                $data,
                $tukarShift
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($target->id, $title, $body, $data);

            // Log::info('Target karyawan notified for new tukar shift', [
            //     'tukar_shift_id' => $tukarShift->id,
            //     'peminta' => $peminta->nama,
            //     'target' => $target->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify target karyawan for new tukar shift', [
            //     'error' => $e->getMessage(),
            //     'tukar_shift_id' => $tukarShift->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Notify karyawan when lembur is approved
     */
    public function notifyKaryawanLemburApproved($pengajuanLembur)
    {
        try {
            // ✅ EAGER LOAD relationships
            $pengajuanLembur->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $karyawan = $pengajuanLembur->jadwalKaryawan->karyawanProject->karyawan;
            $project = $pengajuanLembur->jadwalKaryawan->karyawanProject->project;

            $tanggalLembur = $pengajuanLembur->tanggal->format('d/m/Y');

            $title = 'Pengajuan Lembur Disetujui';
            $body = "Pengajuan lembur Anda untuk tanggal {$tanggalLembur} di project {$project->nama_project} telah disetujui";

            if ($pengajuanLembur->catatan_admin) {
                $body .= ": " . $pengajuanLembur->catatan_admin;
            }

            $data = [
                'type' => 'lembur_approved',
                'pengajuan_lembur_id' => (string) $pengajuanLembur->id,
                'tanggal' => $pengajuanLembur->tanggal->format('Y-m-d'),
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'catatan_admin' => $pengajuanLembur->catatan_admin ?? '',
                'screen' => 'lembur_detail',
                'screen_params' => json_encode(['id' => $pengajuanLembur->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $karyawan->id,
                'lembur_approved',
                $title,
                $body,
                $data,
                $pengajuanLembur
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Karyawan notified for approved lembur', [
            //     'pengajuan_lembur_id' => $pengajuanLembur->id,
            //     'karyawan' => $karyawan->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify karyawan for approved lembur', [
            //     'error' => $e->getMessage(),
            //     'pengajuan_lembur_id' => $pengajuanLembur->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Notify karyawan when lembur is rejected
     */
    public function notifyKaryawanLemburRejected($pengajuanLembur)
    {
        try {
            // ✅ EAGER LOAD relationships
            $pengajuanLembur->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $karyawan = $pengajuanLembur->jadwalKaryawan->karyawanProject->karyawan;
            $project = $pengajuanLembur->jadwalKaryawan->karyawanProject->project;

            $tanggalLembur = $pengajuanLembur->tanggal->format('d/m/Y');

            $title = 'Pengajuan Lembur Ditolak';
            $body = "Pengajuan lembur Anda untuk tanggal {$tanggalLembur} di project {$project->nama_project} ditolak";

            if ($pengajuanLembur->catatan_admin) {
                $body .= ": " . $pengajuanLembur->catatan_admin;
            }

            $data = [
                'type' => 'lembur_rejected',
                'pengajuan_lembur_id' => (string) $pengajuanLembur->id,
                'tanggal' => $pengajuanLembur->tanggal->format('Y-m-d'),
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'catatan_admin' => $pengajuanLembur->catatan_admin ?? '',
                'screen' => 'lembur_detail',
                'screen_params' => json_encode(['id' => $pengajuanLembur->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $karyawan->id,
                'lembur_rejected',
                $title,
                $body,
                $data,
                $pengajuanLembur
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Karyawan notified for rejected lembur', [
            //     'pengajuan_lembur_id' => $pengajuanLembur->id,
            //     'karyawan' => $karyawan->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify karyawan for rejected lembur', [
            //     'error' => $e->getMessage(),
            //     'pengajuan_lembur_id' => $pengajuanLembur->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Send notification to admin (web) when karyawan submits new lembur
     */
    public function notifyAdminNewLembur($pengajuanLembur)
    {
        try {
            // ✅ EAGER LOAD relationships
            $pengajuanLembur->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $karyawan = $pengajuanLembur->jadwalKaryawan->karyawanProject->karyawan;
            $project = $pengajuanLembur->jadwalKaryawan->karyawanProject->project;

            $tanggalLembur = $pengajuanLembur->tanggal->format('d/m/Y');

            $title = 'Pengajuan Lembur Baru';
            $body = "{$karyawan->nama} mengajukan lembur untuk tanggal {$tanggalLembur} di project {$project->nama_project}";

            $data = [
                'type' => 'lembur_new',
                'pengajuan_lembur_id' => (string) $pengajuanLembur->id,
                'karyawan_id' => (string) $karyawan->id,
                'karyawan_nama' => $karyawan->nama,
                'karyawan_nik' => $karyawan->nik,
                'tanggal' => $pengajuanLembur->tanggal->format('Y-m-d'),
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project
            ];

            // Get all admin users
            $adminUsers = User::all();

            foreach ($adminUsers as $admin) {
                // Create notification record in database
                Notification::createForAdmin(
                    $admin->id,
                    'lembur_new',
                    $title,
                    $body,
                    $data,
                    $pengajuanLembur
                );

                // Send push notification
                $this->firebaseService->sendToUser($admin->id, $title, $body, $data);
            }

            // Log::info('Admin notified for new lembur', [
            //     'pengajuan_lembur_id' => $pengajuanLembur->id,
            //     'karyawan' => $karyawan->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify admin for new lembur', [
            //     'error' => $e->getMessage(),
            //     'pengajuan_lembur_id' => $pengajuanLembur->id ?? null
            // ]);

            return false;
        }
    }

    public function notifyKaryawanLemburDikonfirmasi($presensi)
    {
        try {
            // ✅ EAGER LOAD relationships
            $presensi->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $jadwal = $presensi->jadwalKaryawan;
            $karyawan = $jadwal->karyawanProject->karyawan;
            $project = $jadwal->karyawanProject->project;

            $title = '✅ Lembur Dikonfirmasi';
            $body = "Presensi lembur Anda pada {$jadwal->tanggal} untuk shift {$jadwal->shift_code} di project {$project->nama_project} telah dikonfirmasi admin.";

            $data = [
                'type' => 'lembur_dikonfirmasi',
                'presensi_id' => (string) $presensi->id,
                'jadwal_id' => (string) $jadwal->id,
                'tanggal' => $jadwal->tanggal,
                'shift_code' => $jadwal->shift_code,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'screen' => 'data_absensi'
            ];

            // Simpan ke database
            $notification = Notification::createForKaryawan(
                $karyawan->id,
                'lembur_dikonfirmasi',
                $title,
                $body,
                $data,
                $presensi
            );

            // Kirim push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Notifikasi lembur dikonfirmasi', [
            //     'karyawan_id' => $karyawan->id,
            //     'presensi_id' => $presensi->id,
            //     'notification_id' => $notification->id,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify lembur dikonfirmasi', [
            //     'error' => $e->getMessage(),
            //     'presensi_id' => $presensi->id ?? null
            // ]);
            return false;
        }
    }

    public function notifyKaryawanLemburDitolak($presensi, $alasan = null)
    {
        try {
            // ✅ EAGER LOAD relationships
            $presensi->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $jadwal = $presensi->jadwalKaryawan;
            $karyawan = $jadwal->karyawanProject->karyawan;
            $project = $jadwal->karyawanProject->project;

            $title = '❌ Lembur Ditolak';
            $body = "Presensi lembur Anda pada {$jadwal->tanggal} untuk shift {$jadwal->shift_code} di project {$project->nama_project} telah ditolak.";

            if ($alasan) {
                $body .= " Alasan: {$alasan}";
            }

            $data = [
                'type' => 'lembur_ditolak',
                'presensi_id' => (string) $presensi->id,
                'jadwal_id' => (string) $jadwal->id,
                'tanggal' => $jadwal->tanggal,
                'shift_code' => $jadwal->shift_code,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'alasan' => $alasan ?? '',
                'screen' => 'data_absensi'
            ];

            // Simpan ke database
            $notification = Notification::createForKaryawan(
                $karyawan->id,
                'lembur_ditolak',
                $title,
                $body,
                $data,
                $presensi
            );

            // Kirim push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Notifikasi lembur ditolak', [
            //     'karyawan_id' => $karyawan->id,
            //     'presensi_id' => $presensi->id,
            //     'notification_id' => $notification->id,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify lembur ditolak', [
            //     'error' => $e->getMessage(),
            //     'presensi_id' => $presensi->id ?? null
            // ]);
            return false;
        }
    }

    public function notifyKaryawanPresensiDiupdate($presensi, $statusBaru, $statusLama, $keterangan = null)
    {
        try {
            // Skip jika hanya lembur yang dikonfirmasi/ditolak
            if (in_array($statusBaru, ['lembur', 'lembur_pending'])) {
                // Log::info('Skipping: gunakan notifyKaryawanLemburDikonfirmasi untuk lembur');
                return false;
            }

            // ✅ EAGER LOAD relationships
            $presensi->load([
                'jadwalKaryawan.karyawanProject.karyawan',
                'jadwalKaryawan.karyawanProject.project'
            ]);

            $jadwal = $presensi->jadwalKaryawan;
            $karyawan = $jadwal->karyawanProject->karyawan;
            $project = $jadwal->karyawanProject->project;

            $title = '📝 Data Presensi Diperbarui Admin';
            $body = "Status presensi Anda pada {$jadwal->tanggal} shift {$jadwal->shift_code} di project {$project->nama_project} telah diubah dari {$statusLama} menjadi {$statusBaru}.";

            if ($keterangan) {
                $body .= " ({$keterangan})";
            }

            $data = [
                'type' => 'presensi_diupdate',
                'presensi_id' => (string) $presensi->id,
                'jadwal_id' => (string) $jadwal->id,
                'tanggal' => $jadwal->tanggal,
                'shift_code' => $jadwal->shift_code,
                'status_lama' => $statusLama,
                'status_baru' => $statusBaru,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'keterangan' => $keterangan ?? '',
                'screen' => 'data_absensi'
            ];

            // Simpan ke database
            $notification = Notification::createForKaryawan(
                $karyawan->id,
                'presensi_diupdate',
                $title,
                $body,
                $data,
                $presensi
            );

            // Kirim push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Notifikasi presensi diupdate', [
            //     'karyawan_id' => $karyawan->id,
            //     'presensi_id' => $presensi->id,
            //     'status_lama' => $statusLama,
            //     'status_baru' => $statusBaru,
            //     'notification_id' => $notification->id,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify presensi diupdate', [
            //     'error' => $e->getMessage(),
            //     'presensi_id' => $presensi->id ?? null
            // ]);
            return false;
        }
    }

    public function notifyKaryawanJadwalBaru($karyawanProject, $jadwals)
    {
        try {
            // ✅ EAGER LOAD relationships
            $karyawanProject->load(['karyawan', 'project']);

            $karyawan = $karyawanProject->karyawan;
            $project = $karyawanProject->project;

            $tanggalAwal = collect($jadwals)->min('tanggal');
            $tanggalAkhir = collect($jadwals)->max('tanggal');
            $totalJadwal = count($jadwals);

            $title = '📅 Jadwal Baru Ditambahkan';
            $body = "Anda memiliki {$totalJadwal} jadwal baru di project {$project->nama_project} dari {$tanggalAwal} hingga {$tanggalAkhir}.";

            $jadwalDetail = collect($jadwals)->map(function ($j) {
                return [
                    'tanggal' => $j->tanggal,
                    'shift_code' => $j->shift_code
                ];
            })->toArray();

            $data = [
                'type' => 'jadwal_baru',
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'tanggal_awal' => $tanggalAwal,
                'tanggal_akhir' => $tanggalAkhir,
                'total_jadwal' => (string) $totalJadwal,
                'jadwal_detail' => json_encode($jadwalDetail),
                'screen' => 'jadwal'
            ];

            // Simpan ke database
            $notification = Notification::createForKaryawan(
                $karyawan->id,
                'jadwal_baru',
                $title,
                $body,
                $data
            );

            // Kirim push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Notifikasi jadwal baru', [
            //     'karyawan_id' => $karyawan->id,
            //     'project_id' => $project->id,
            //     'total_jadwal' => $totalJadwal,
            //     'notification_id' => $notification->id,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify jadwal baru', [
            //     'error' => $e->getMessage(),
            //     'karyawan_project_id' => $karyawanProject->id ?? null
            // ]);
            return false;
        }
    }

    public function notifyKaryawanJadwalDiupdate($karyawanProject, $jadwals, $jenis = 'update')
    {
        try {
            // ✅ EAGER LOAD relationships
            $karyawanProject->load(['karyawan', 'project']);

            $karyawan = $karyawanProject->karyawan;
            $project = $karyawanProject->project;

            $tanggalAwal = collect($jadwals)->min('tanggal');
            $tanggalAkhir = collect($jadwals)->max('tanggal');
            $totalJadwal = count($jadwals);

            $title = '📝 Jadwal Diperbarui';
            $body = "Jadwal Anda di project {$project->nama_project} telah diperbarui. {$totalJadwal} jadwal dari {$tanggalAwal} hingga {$tanggalAkhir}.";

            $jadwalDetail = collect($jadwals)->map(function ($j) {
                return [
                    'tanggal' => $j->tanggal,
                    'shift_code' => $j->shift_code
                ];
            })->toArray();

            $data = [
                'type' => 'jadwal_diupdate',
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'tanggal_awal' => $tanggalAwal,
                'tanggal_akhir' => $tanggalAkhir,
                'total_jadwal' => (string) $totalJadwal,
                'jadwal_detail' => json_encode($jadwalDetail),
                'jenis' => $jenis,
                'screen' => 'jadwal'
            ];

            // Simpan ke database
            $notification = Notification::createForKaryawan(
                $karyawan->id,
                'jadwal_diupdate',
                $title,
                $body,
                $data
            );

            // Kirim push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('Notifikasi jadwal diupdate', [
            //     'karyawan_id' => $karyawan->id,
            //     'project_id' => $project->id,
            //     'total_jadwal' => $totalJadwal,
            //     'notification_id' => $notification->id,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify jadwal diupdate', [
            //     'error' => $e->getMessage(),
            //     'karyawan_project_id' => $karyawanProject->id ?? null
            // ]);
            return false;
        }
    }

    /**
     * Send notification to peminta when tukar shift is approved
     */
    public function notifyKaryawanTukarShiftApproved($tukarShift)
    {
        try {
            // ✅ EAGER LOAD relationships
            $tukarShift->load([
                'peminta',
                'target',
                'jadwalPeminta',
                'jadwalTarget',
                'project'
            ]);

            $peminta = $tukarShift->peminta;
            $target = $tukarShift->target;
            $jadwalPeminta = $tukarShift->jadwalPeminta;
            $jadwalTarget = $tukarShift->jadwalTarget;
            $project = $tukarShift->project;

            $title = 'Permintaan Tukar Shift Disetujui';
            $body = "{$target->nama} menyetujui permintaan tukar shift Anda di project {$project->nama_project}";

            $data = [
                'type' => 'tukar_shift_approved',
                'tukar_shift_id' => (string) $tukarShift->id,
                'peminta_id' => (string) $peminta->id,
                'peminta_nama' => $peminta->nama,
                'target_id' => (string) $target->id,
                'target_nama' => $target->nama,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'jadwal_peminta_tanggal' => $jadwalPeminta->tanggal,
                'jadwal_peminta_shift_before' => $jadwalTarget->shift_code,
                'jadwal_peminta_shift_after' => $jadwalTarget->shift_code,
                'jadwal_target_tanggal' => $jadwalTarget->tanggal,
                'jadwal_target_shift_before' => $jadwalPeminta->shift_code,
                'jadwal_target_shift_after' => $jadwalPeminta->shift_code,
                'screen' => 'tukar_shift_detail',
                'screen_params' => json_encode(['id' => $tukarShift->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $peminta->id,
                'tukar_shift_approved',
                $title,
                $body,
                $data,
                $tukarShift
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($peminta->id, $title, $body, $data);

            // Log::info('Peminta karyawan notified for approved tukar shift', [
            //     'tukar_shift_id' => $tukarShift->id,
            //     'peminta' => $peminta->nama,
            //     'target' => $target->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify peminta for approved tukar shift', [
            //     'error' => $e->getMessage(),
            //     'tukar_shift_id' => $tukarShift->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Send notification to peminta when tukar shift is rejected
     */
    public function notifyKaryawanTukarShiftRejected($tukarShift)
    {
        try {
            // ✅ EAGER LOAD relationships
            $tukarShift->load([
                'peminta',
                'target',
                'jadwalPeminta',
                'jadwalTarget',
                'project'
            ]);

            $peminta = $tukarShift->peminta;
            $target = $tukarShift->target;
            $jadwalPeminta = $tukarShift->jadwalPeminta;
            $jadwalTarget = $tukarShift->jadwalTarget;
            $project = $tukarShift->project;

            $title = 'Permintaan Tukar Shift Ditolak';
            $body = "{$target->nama} menolak permintaan tukar shift Anda di project {$project->nama_project}";

            if ($tukarShift->alasan_penolakan) {
                $body .= ": " . $tukarShift->alasan_penolakan;
            }

            $data = [
                'type' => 'tukar_shift_rejected',
                'tukar_shift_id' => (string) $tukarShift->id,
                'peminta_id' => (string) $peminta->id,
                'peminta_nama' => $peminta->nama,
                'target_id' => (string) $target->id,
                'target_nama' => $target->nama,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
                'jadwal_peminta_tanggal' => $jadwalPeminta->tanggal,
                'jadwal_peminta_shift' => $jadwalPeminta->shift_code,
                'jadwal_target_tanggal' => $jadwalTarget->tanggal,
                'jadwal_target_shift' => $jadwalTarget->shift_code,
                'alasan_penolakan' => $tukarShift->alasan_penolakan ?? '',
                'screen' => 'tukar_shift_detail',
                'screen_params' => json_encode(['id' => $tukarShift->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $peminta->id,
                'tukar_shift_rejected',
                $title,
                $body,
                $data,
                $tukarShift
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($peminta->id, $title, $body, $data);

            // Log::info('Peminta karyawan notified for rejected tukar shift', [
            //     'tukar_shift_id' => $tukarShift->id,
            //     'peminta' => $peminta->nama,
            //     'target' => $target->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify peminta for rejected tukar shift', [
            //     'error' => $e->getMessage(),
            //     'tukar_shift_id' => $tukarShift->id ?? null
            // ]);

            return false;
        }
    }

    /**
     * Send notification to admin when new tukar shift is submitted
     */
    public function notifyAdminNewTukarShift($tukarShift)
    {
        try {
            // ✅ EAGER LOAD relationships
            $tukarShift->load(['peminta', 'target', 'project']);

            $peminta = $tukarShift->peminta;
            $target = $tukarShift->target;
            $project = $tukarShift->project;

            $title = 'Permintaan Tukar Shift Baru';
            $body = "{$peminta->nama} mengajukan tukar shift dengan {$target->nama} di project {$project->nama_project}";

            $data = [
                'type' => 'tukar_shift_pending',
                'tukar_shift_id' => (string) $tukarShift->id,
                'peminta_id' => (string) $peminta->id,
                'peminta_nama' => $peminta->nama,
                'target_id' => (string) $target->id,
                'target_nama' => $target->nama,
                'project_id' => (string) $project->id,
                'project_nama' => $project->nama_project,
            ];

            // Get all admin users
            $adminUsers = User::all();

            foreach ($adminUsers as $admin) {
                // Create notification record in database
                Notification::createForAdmin(
                    $admin->id,
                    'tukar_shift_pending',
                    $title,
                    $body,
                    $data,
                    $tukarShift
                );

                // Send push notification
                $this->firebaseService->sendToUser($admin->id, $title, $body, $data);
            }

            // Log::info('Admin notified for new tukar shift', [
            //     'tukar_shift_id' => $tukarShift->id,
            //     'peminta' => $peminta->nama,
            //     'target' => $target->nama,
            //     'project' => $project->nama_project
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('Failed to notify admin for new tukar shift', [
            //     'error' => $e->getMessage(),
            //     'tukar_shift_id' => $tukarShift->id ?? null
            // ]);

            return false;
        }
    }

    public function notifyKaryawanNewInformasi($informasi, $karyawanId)
    {
        try {
            $karyawan = \App\Models\Karyawan::find($karyawanId);

            if (!$karyawan) {
                // Log::warning('Karyawan not found for informasi notification', [
                //     'karyawan_id' => $karyawanId
                // ]);
                return false;
            }

            // ✅ PERBAIKAN: Get informasi_karyawan record untuk mendapatkan informasi_karyawan_id
            $informasiKaryawan = \App\Models\InformasiKaryawan::where('informasi_id', $informasi->id)
                ->where('karyawan_id', $karyawan->id)
                ->first();

            if (!$informasiKaryawan) {
                // Log::warning('InformasiKaryawan not found', [
                //     'informasi_id' => $informasi->id,
                //     'karyawan_id' => $karyawan->id
                // ]);
                return false;
            }

            $title = '📢 Informasi Baru';
            $body = $informasi->judul;

            // Limit body to 100 chars for preview
            if (strlen($body) > 100) {
                $body = substr($body, 0, 100) . '...';
            }

            $data = [
                // ✅ FIX: Gunakan type yang konsisten dengan Flutter handler
                'type' => 'informasi_baru',  // ✅ SESUAI dengan Flutter handler

                // ✅ FIX: Kirim informasi_karyawan_id, bukan informasi_id
                'informasi_karyawan_id' => (string) $informasiKaryawan->id,  // ✅ CRITICAL

                // Optional: kirim juga informasi_id untuk referensi
                'informasi_id' => (string) $informasi->id,

                'judul' => $informasi->judul,
                'konten_preview' => Str::limit($informasi->konten, 100),
                'has_file' => !empty($informasi->file_path),
                'file_name' => $informasi->file_name,
                'dikirim_at' => $informasi->dikirim_at?->format('Y-m-d H:i:s'),

                // ✅ Navigation info untuk Flutter
                'screen' => 'informasi_detail',
                'screen_params' => json_encode(['informasi_karyawan_id' => $informasiKaryawan->id])
            ];

            // Create notification record in database
            Notification::createForKaryawan(
                $karyawan->id,
                'informasi_baru',  // ✅ Konsisten dengan type di data
                $title,
                $body,
                $data,
                $informasi
            );

            // Send push notification
            $this->firebaseService->sendToKaryawan($karyawan->id, $title, $body, $data);

            // Log::info('✅ Karyawan notified for new informasi', [
            //     'informasi_id' => $informasi->id,
            //     'informasi_karyawan_id' => $informasiKaryawan->id,
            //     'karyawan_id' => $karyawan->id,
            //     'karyawan_nama' => $karyawan->nama
            // ]);

            return true;
        } catch (\Exception $e) {
            // Log::error('❌ Failed to notify karyawan for new informasi', [
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString(),
            //     'informasi_id' => $informasi->id ?? null,
            //     'karyawan_id' => $karyawanId
            // ]);

            return false;
        }
    }
}
