<?php



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
    
    

    $yesterday = Carbon::yesterday();
    
    $jadwals = JadwalKaryawan::with([
            'karyawanProject.karyawan.divisi',
            'karyawanProject.karyawan.jabatan',
            'karyawanProject.project.shiftProjects'
        ])
        ->whereIn('tanggal', [$yesterday->format('Y-m-d'), $today->format('Y-m-d')])
        ->get();

    if ($jadwals->isEmpty()) {
        
        return 0;
    }

    $reminderSent = 0;

    foreach ($jadwals as $jadwal) {
        try {
            $shiftCode = strtoupper(trim($jadwal->shift_code ?? ''));
            
            
            if ($shiftCode === 'L' || empty($shiftCode)) {
                
                
                
                
                continue;
            }

            $project = $jadwal->karyawanProject->project;
            $karyawan = $jadwal->karyawanProject->karyawan;

            $shift = $project->shiftProjects()
                ->whereRaw('UPPER(kode) = ?', [$shiftCode])
                ->first();

            if (!$shift) {
                
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

            
            $this->checkAndSendReminder1_BukaPresensi(
                $now, $waktuReminder1, $waktuMulaiShift, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            
            $this->checkAndSendReminder2_TidakPresensiMasuk(
                $now, $waktuMulaiShift, $presensiMasuk, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            
            $this->checkAndSendReminder3_PresensiPulang(
                $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            
            $this->checkAndSendReminder4_TidakPresensiPulang(
                $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

            
            $this->checkAndSendReminder5_LemburBelumKonfirmasi(
                $now, $waktuSelesaiShift, $presensiMasuk, $presensiPulang, $jadwal, $shift, $project, $karyawan, $reminderSent
            );

        } catch (\Exception $e) {
            throw $e;
        }
    }

    
    
    
    
    
    

    
    
    
    

    return 0;
}

    
    private function isWithinReminderWindow($now, $targetTime)
    {
        $windowStart = $targetTime->copy()->subMinutes(self::NOTIFICATION_WINDOW_MINUTES);
        $windowEnd = $targetTime->copy()->addMinutes(self::NOTIFICATION_WINDOW_MINUTES);
        return $now->between($windowStart, $windowEnd);
    }

    
    private function isWithinWindow($now, $targetTime)
    {
        $diffMinutes = abs($now->diffInMinutes($targetTime));
        return $diffMinutes <= self::NOTIFICATION_WINDOW_MINUTES;
    }

    
    private function checkNotificationSuccessfullySent($jadwalId, $karyawanId, $reminderType)
    {
        $cacheKey = "reminder_success_{$jadwalId}_{$karyawanId}_{$reminderType}";
        
        if (Cache::has($cacheKey)) {
            
            
            
            
            
            
            return true;
        }
        
        return false;
    }

    
    private function markNotificationSent($jadwalId, $karyawanId, $reminderType)
    {
        $cacheKey = "reminder_success_{$jadwalId}_{$karyawanId}_{$reminderType}";
        $expiresAt = Carbon::now()->endOfDay();
        
        Cache::put($cacheKey, Carbon::now()->format('H:i:s'), $expiresAt);
        
        
        
        
        
        
    }

    
    private function checkAndSendReminder1_BukaPresensi(
    $now, $waktuReminder1, $waktuMulaiShift, $jadwal, $shift, $project, $karyawan, &$reminderSent
    ) {
        $presensiMasuk = Presensi::where('jadwal_karyawan_id', $jadwal->id)
                                 ->where('tipe', 'masuk')
                                 ->first();
        
        if ($presensiMasuk) {
            
            
            
            
            
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
            
        } else {
            $this->warn("⚠️ Reminder 1 gagal ke: {$karyawan->nama} (retry next run)");
        }
    }

    
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