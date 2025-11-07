<?php
// app/Console/Commands/PresensiReminderNotification.php
// ✅ COMPLETE VERSION: Using MINUTES with Smart Cache

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\JadwalKaryawan;
use App\Models\ShiftProject;
use App\Models\Presensi;
use App\Services\NotificationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class PresensiReminderNotification extends Command
{
    protected $signature = 'presensi:reminder-notification';
    protected $description = 'Kirim reminder notification untuk berbagai tahap presensi';

    protected $notificationService;

    // ✅ CHANGED: 2 MINUTES window
    private const NOTIFICATION_WINDOW_MINUTES = 1;

    public function __construct(NotificationService $notificationService)
    {
        parent::__construct();
        $this->notificationService = $notificationService;
    }

    public function handle()
{
    $this->info('📢 Memulai pengiriman reminder notification presensi...');
    
    $now = Carbon::now();
    $today = Carbon::today();
    
    // $this->info("⏰ Waktu server: {$now->format('Y-m-d H:i:s')}");

    $yesterday = Carbon::yesterday();
    
    $jadwals = JadwalKaryawan::with([
            'karyawanProject.karyawan.divisi',
            'karyawanProject.karyawan.jabatan',
            'karyawanProject.project.shiftProjects'
        ])
        ->whereIn('tanggal', [$yesterday->format('Y-m-d'), $today->format('Y-m-d')])
        ->get();

    if ($jadwals->isEmpty()) {
        // $this->info('ℹ️ Tidak ada jadwal untuk hari ini/kemarin');
        return 0;
    }

    $reminderSent = 0;

    foreach ($jadwals as $jadwal) {
        try {
            $shiftCode = strtoupper(trim($jadwal->shift_code ?? ''));
            
            // ✅ CRITICAL: Skip reminder untuk hari libur
            if ($shiftCode === 'L' || empty($shiftCode)) {
                // Log::info('Skip reminder untuk hari libur', [
                //     'jadwal_id' => $jadwal->id,
                //     'tanggal' => $jadwal->tanggal
                // ]);
                continue;
            }

            $project = $jadwal->karyawanProject->project;
            $karyawan = $jadwal->karyawanProject->karyawan;

            $shift = $project->shiftProjects()
                ->whereRaw('UPPER(kode) = ?', [$shiftCode])
                ->first();

            if (!$shift) {
                // Log::warning("⚠️ Shift tidak ditemukan untuk jadwal ID: {$jadwal->id}");
                continue;
            }

            $tanggalJadwal = Carbon::parse($jadwal->tanggal);
            $waktuMulaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_mulai);
            $waktuSelesaiShift = Carbon::parse($tanggalJadwal->format('Y-m-d') . ' ' . $shift->waktu_selesai);
            
            if ($waktuSelesaiShift->lessThanOrEqualTo($waktuMulaiShift)) {
                $waktuSelesaiShift->addDay();
            }

            if ($now->greaterThan($waktuSelesaiShift->copy()->addHours(4))) {
                continue;
            }

            $waktuToleransi = (int)($project->waktu_toleransi ?? 0);
            $waktuReminder1 = $waktuMulaiShift->copy()->subMinutes(30);

            $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                     ->where('tipe', 'masuk')
                                     ->first();

            $presensiPulang = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                      ->where('tipe', 'pulang')
                                      ->first();

            // REMINDER 1
            $this->checkAndSendReminder1_BukaPresensi(
                $now, $waktuReminder1, $waktuMulaiShift, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            // REMINDER 2
            $this->checkAndSendReminder2_TidakPresensiMasuk(
                $now, $waktuMulaiShift, $presensiMasuk, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            // REMINDER 3
            $this->checkAndSendReminder3_PresensiPulang(
                $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            // REMINDER 4
            $this->checkAndSendReminder4_TidakPresensiPulang(
                $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            // REMINDER 5
            $this->checkAndSendReminder5_LemburBelumKonfirmasi(
                $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

        } catch (\Exception $e) {
            throw $e;
        }
    }

    // $this->newLine();
    // $this->info("========================================");
    // $this->info("✅ Reminder notification selesai");
    // $this->info("========================================");
    // $this->info("📤 Total reminder dikirim: {$reminderSent}");
    // $this->info("========================================");

    // Log::info('✅ Presensi reminder notification selesai', [
    //     'reminders_sent' => $reminderSent,
    //     'timestamp' => $now->format('Y-m-d H:i:s')
    // ]);

    return 0;
}

    /**
     * ✅ Check if within reminder window for Reminder 1
     * Window = dari waktu_buka_presensi sampai waktu_mulai_shift + buffer
     */
    private function isWithinReminderWindow($now, $targetTime)
    {
        $windowStart = $targetTime->copy()->subMinutes(self::NOTIFICATION_WINDOW_MINUTES);
        $windowEnd = $targetTime->copy()->addMinutes(self::NOTIFICATION_WINDOW_MINUTES);
        return $now->between($windowStart, $windowEnd);
    }

    /**
     * ✅ Check if within standard window (±2 minutes)
     */
    private function isWithinWindow($now, $targetTime)
    {
        $diffMinutes = abs($now->diffInMinutes($targetTime));
        return $diffMinutes <= self::NOTIFICATION_WINDOW_MINUTES;
    }

    /**
     * ✅ SMART CACHE: Only cache if successfully sent
     */
    private function checkNotificationSuccessfullySent($jadwalId, $karyawanId, $reminderType)
    {
        $cacheKey = "reminder_success_{$jadwalId}_{$karyawanId}_{$reminderType}";
        
        if (Cache::has($cacheKey)) {
            // Log::info("⏭️ Reminder already sent successfully", [
            //     'jadwal_id' => $jadwalId,
            //     'karyawan_id' => $karyawanId,
            //     'reminder_type' => $reminderType,
            //     'sent_at' => Cache::get($cacheKey)
            // ]);
            return true;
        }
        
        return false;
    }

    /**
     * ✅ Mark notification as successfully sent (cache sampai akhir hari)
     */
    private function markNotificationSent($jadwalId, $karyawanId, $reminderType)
    {
        $cacheKey = "reminder_success_{$jadwalId}_{$karyawanId}_{$reminderType}";
        $expiresAt = Carbon::now()->endOfDay();
        
        Cache::put($cacheKey, Carbon::now()->format('H:i:s'), $expiresAt);
        
        // Log::info("✅ Marked notification as sent", [
        //     'jadwal_id' => $jadwalId,
        //     'karyawan_id' => $karyawanId,
        //     'reminder_type' => $reminderType
        // ]);
    }

    /**
    * REMINDER 1: H-30 menit sebelum waktu mulai shift
    */
    private function checkAndSendReminder1_BukaPresensi(
    $now, $waktuReminder1, $waktuMulaiShift, $jadwal, $shift, $project, $karyawan, &$reminderSent
    ) {
        $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                 ->where('tipe', 'masuk')
                                 ->first();
        
        if ($presensiMasuk) {
            // Log::debug('Skip Reminder 1: Sudah presensi masuk', [
            //     'jadwal_id' => $jadwal->id,
            //     'karyawan' => $karyawan->nama,
            //     'status' => $presensiMasuk->status
            // ]);
            return;
        }
    
        if (!$this->isWithinReminderWindow($now, $waktuReminder1)) {
            return;
        }
    
        if ($this->checkNotificationSuccessfullySent($jadwal->id, $karyawan->id, 'reminder_1')) {
            return;
        }
        
        $title = 'Pengingat Presensi Masuk';
        $body = "Shift {$shift->kode} ({$shift->waktu_mulai}) akan dimulai dalam 30 menit. Silakan lakukan presensi masuk.";
        
        $data = [
            'type' => 'presensi_reminder_masuk',
            'shift_code' => $shift->kode,
            'shift_waktu' => "{$shift->waktu_mulai} - {$shift->waktu_selesai}",
            'project_nama' => $project->nama_project,
            'tanggal' => $jadwal->tanggal,
            'reminder_stage' => 'h-30_presensi_masuk'
        ];
    
        $success = $this->notificationService->sendReminderNotification(
            $karyawan->id, $title, $body, $data
        );
    
        if ($success) {
            $this->markNotificationSent($jadwal->id, $karyawan->id, 'reminder_1');
            $reminderSent++;
            // $this->info("✅ Reminder 1 (H-30) dikirim ke: {$karyawan->nama}");
        } else {
            $this->warn("⚠️ Reminder 1 gagal ke: {$karyawan->nama} (retry next run)");
        }
    }

    /**
     * REMINDER 2: H+30 menit setelah shift dimulai
     */
    private function checkAndSendReminder2_TidakPresensiMasuk(
        $now, $waktuMulaiShift, $presensiMasuk, $jadwal, $shift, $project, $karyawan, &$reminderSent
    ) {
        if ($presensiMasuk && $presensiMasuk->status === 'izin') {
            return;
        }

        if ($presensiMasuk !== null) {
            return;
        }

        $waktuTarget = $waktuMulaiShift->copy()->addMinutes(30);
        
        if (!$this->isWithinWindow($now, $waktuTarget)) {
            return;
        }

        if ($this->checkNotificationSuccessfullySent($jadwal->id, $karyawan->id, 'reminder_2')) {
            return;
        }

        $title = '⚠️ Belum Presensi Masuk';
        $body = "Anda belum melakukan presensi masuk untuk shift {$shift->kode}. Segera lakukan presensi!";
        
        $data = [
            'type' => 'presensi_reminder_missing',
            'shift_code' => $shift->kode,
            'shift_waktu' => "{$shift->waktu_mulai} - {$shift->waktu_selesai}",
            'project_nama' => $project->nama_project,
            'tanggal' => $jadwal->tanggal,
            'reminder_stage' => 'tidak_presensi_masuk_h30'
        ];

        $success = $this->notificationService->sendReminderNotification(
            $karyawan->id, $title, $body, $data
        );

        if ($success) {
            $this->markNotificationSent($jadwal->id, $karyawan->id, 'reminder_2');
            $reminderSent++;
            $this->info("✅ Reminder 2 dikirim ke: {$karyawan->nama}");
        }
    }

    /**
     * REMINDER 3: Shift berakhir
     */
    private function checkAndSendReminder3_PresensiPulang(
        $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, &$reminderSent
    ) {
        if ($presensiMasuk && $presensiMasuk->status === 'izin') {
            return;
        }

        if ($presensiMasuk === null || !in_array($presensiMasuk->status, ['hadir', 'terlambat'])) {
            return;
        }

        if ($presensiPulang !== null) {
            return;
        }

        if (!$this->isWithinWindow($now, $waktuSelesaiShift)) {
            return;
        }

        if ($this->checkNotificationSuccessfullySent($jadwal->id, $karyawan->id, 'reminder_3')) {
            return;
        }

        $title = 'Saatnya Presensi Pulang';
        $body = "Shift {$shift->kode} telah berakhir ({$shift->waktu_selesai}). Lakukan presensi pulang.";
        
        $data = [
            'type' => 'presensi_reminder_pulang',
            'shift_code' => $shift->kode,
            'shift_waktu' => "{$shift->waktu_mulai} - {$shift->waktu_selesai}",
            'project_nama' => $project->nama_project,
            'tanggal' => $jadwal->tanggal,
            'reminder_stage' => 'presensi_pulang'
        ];

        $success = $this->notificationService->sendReminderNotification(
            $karyawan->id, $title, $body, $data
        );

        if ($success) {
            $this->markNotificationSent($jadwal->id, $karyawan->id, 'reminder_3');
            $reminderSent++;
            $this->info("✅ Reminder 3 dikirim ke: {$karyawan->nama}");
        }
    }

    /**
     * REMINDER 4: H+30 menit setelah shift berakhir
     */
    private function checkAndSendReminder4_TidakPresensiPulang(
        $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, &$reminderSent
    ) {
        if ($presensiMasuk && $presensiMasuk->status === 'izin') {
            return;
        }

        if ($presensiMasuk === null || !in_array($presensiMasuk->status, ['hadir', 'terlambat'])) {
            return;
        }

        if ($presensiPulang !== null) {
            return;
        }

        $waktuTarget = $waktuSelesaiShift->copy()->addMinutes(30);
        
        if (!$this->isWithinWindow($now, $waktuTarget)) {
            return;
        }

        if ($this->checkNotificationSuccessfullySent($jadwal->id, $karyawan->id, 'reminder_4')) {
            return;
        }

        $title = '⚠️ Belum Presensi Pulang';
        $body = "Anda belum melakukan presensi pulang.";
        
        $data = [
            'type' => 'presensi_reminder_not_checkout',
            'shift_code' => $shift->kode,
            'shift_waktu' => "{$shift->waktu_mulai} - {$shift->waktu_selesai}",
            'project_nama' => $project->nama_project,
            'tanggal' => $jadwal->tanggal,
            'reminder_stage' => 'tidak_presensi_pulang_h10'
        ];

        $success = $this->notificationService->sendReminderNotification(
            $karyawan->id, $title, $body, $data
        );

        if ($success) {
            $this->markNotificationSent($jadwal->id, $karyawan->id, 'reminder_4');
            $reminderSent++;
            $this->info("✅ Reminder 4 dikirim ke: {$karyawan->nama}");
        }
    }

    /**
     * REMINDER 5: H+3 jam setelah shift berakhir
     */
    private function checkAndSendReminder5_LemburBelumKonfirmasi(
        $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, &$reminderSent
    ) {
        if ($presensiPulang === null || $presensiPulang->status !== 'lembur_pending') {
            return;
        }

        $waktuTarget = $waktuSelesaiShift->copy()->addHours(3);
        
        if (!$this->isWithinWindow($now, $waktuTarget)) {
            return;
        }

        if ($this->checkNotificationSuccessfullySent($jadwal->id, $karyawan->id, 'reminder_5')) {
            return;
        }

        $title = '🕐 Lembur Menunggu Konfirmasi';
        $body = "Presensi lembur Anda masih menunggu konfirmasi admin.";
        
        $data = [
            'type' => 'presensi_reminder_lembur_pending',
            'shift_code' => $shift->kode,
            'shift_waktu' => "{$shift->waktu_mulai} - {$shift->waktu_selesai}",
            'project_nama' => $project->nama_project,
            'tanggal' => $jadwal->tanggal,
            'reminder_stage' => 'lembur_pending_h3'
        ];

        $success = $this->notificationService->sendReminderNotification(
            $karyawan->id, $title, $body, $data
        );

        if ($success) {
            $this->markNotificationSent($jadwal->id, $karyawan->id, 'reminder_5');
            $reminderSent++;
            $this->info("✅ Reminder 5 dikirim ke: {$karyawan->nama}");
        }
    }
}