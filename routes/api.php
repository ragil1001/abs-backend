<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Mobile\MobileAuthController;
use App\Http\Controllers\DivisiController;
use App\Http\Controllers\JabatanController;
use App\Http\Controllers\KaryawanController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ShiftProjectController;
use App\Http\Controllers\KaryawanProjectController;
use App\Http\Controllers\JadwalKaryawanController;
use App\Http\Controllers\PresensiController;
use App\Http\Controllers\PengajuanIzinController;
use App\Http\Controllers\TukarShiftController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\PengajuanLemburController;



// ============================================
// MOBILE APP ROUTES
// ============================================
Route::prefix('mobile')->group(function () {
    // Auth routes (public)
    Route::post('/login', [MobileAuthController::class, 'login']);

    // Protected routes (require authentication)
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [MobileAuthController::class, 'me']);
        Route::post('/logout', [MobileAuthController::class, 'logout']);
        Route::post('/change-password', [MobileAuthController::class, 'changePassword']);

        // Presensi routes
        Route::get('/presensi/cek', [PresensiController::class, 'cekPresensi']);
        Route::post('/presensi/validasi-lokasi', [PresensiController::class, 'validasiLokasi']);
        Route::post('/presensi/submit', [PresensiController::class, 'submitPresensi']);
        Route::get('/presensi/history', [PresensiController::class, 'getHistory']);

        // Pengajuan Izin routes
        Route::get('/pengajuan-izin/kategori-list', [PengajuanIzinController::class, 'getKategoriIzinList']);
        Route::get('/pengajuan-izin/sub-kategori-list', [PengajuanIzinController::class, 'getSubKategoriCutiKhususList']);
        Route::post('/pengajuan-izin/hitung-tanggal', [PengajuanIzinController::class, 'hitungTanggalSelesai']);
        Route::get('/pengajuan-izin', [PengajuanIzinController::class, 'getMyPengajuan']);
        Route::post('/pengajuan-izin', [PengajuanIzinController::class, 'ajukanIzin']);
        Route::get('/pengajuan-izin/{pengajuanId}', [PengajuanIzinController::class, 'show']);
        Route::patch('/pengajuan-izin/{pengajuanId}/batalkan', [PengajuanIzinController::class, 'batalkanPengajuan']);
        Route::delete('/pengajuan-izin/{pengajuanId}', [PengajuanIzinController::class, 'hapusPengajuan']);

        Route::get('/pengajuan-lembur', [PengajuanLemburController::class, 'getMyPengajuan']);
        Route::post('/pengajuan-lembur', [PengajuanLemburController::class, 'ajukanLembur']);
        Route::get('/pengajuan-lembur/{pengajuanId}', [PengajuanLemburController::class, 'show']);
        Route::patch('/pengajuan-lembur/{pengajuanId}/batalkan', [PengajuanLemburController::class, 'batalkanPengajuan']);
        Route::delete('/pengajuan-lembur/{pengajuanId}', [PengajuanLemburController::class, 'hapusPengajuan']);

        Route::get('/presensi/data', [PresensiController::class, 'getPresensiData']);
        Route::get('/presensi/statistik-periode', [PresensiController::class, 'getStatistikPeriode']);
        Route::get('/jadwal/bulan', [PresensiController::class, 'getJadwalBulan']);

        // Tukar Shift routes
        Route::prefix('tukar-shift')->group(function () {
            Route::get('/', [TukarShiftController::class, 'index']);
            Route::get('/{tukarShiftId}', [TukarShiftController::class, 'show']);
            Route::get('/jadwal/available', [TukarShiftController::class, 'getMyAvailableShifts']);
            Route::get('/karyawan/with-shift', [TukarShiftController::class, 'getKaryawanWithShift']);
            Route::post('/', [TukarShiftController::class, 'store']);
            Route::post('/{tukarShiftId}/proses', [TukarShiftController::class, 'proses']);
            Route::post('/{tukarShiftId}/cancel', [TukarShiftController::class, 'cancel']);
        });

        // ============================================
        // NOTIFICATION ROUTES FOR MOBILE (KARYAWAN)
        // ============================================
        Route::prefix('notifications')->group(function () {
            Route::get('/', [NotificationController::class, 'getKaryawanNotifications']);
            Route::get('/unread-count', [NotificationController::class, 'getKaryawanUnreadCount']);
            Route::post('/{notificationId}/read', [NotificationController::class, 'markAsRead']);
            Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
            Route::delete('/{notificationId}', [NotificationController::class, 'delete']);
            Route::post('/fcm-token', [NotificationController::class, 'storeKaryawanToken']);
            Route::delete('/fcm-token', [NotificationController::class, 'deleteToken']);
        });

        Route::prefix('informasi')->group(function () {
            Route::get('/', [\App\Http\Controllers\Api\Mobile\MobileInformasiController::class, 'index']);
            Route::get('/unread-count', [\App\Http\Controllers\Api\Mobile\MobileInformasiController::class, 'getUnreadCount']);
            Route::get('/{informasiKaryawanId}', [\App\Http\Controllers\Api\Mobile\MobileInformasiController::class, 'show']);
            Route::post('/{informasiKaryawanId}/read', [\App\Http\Controllers\Api\Mobile\MobileInformasiController::class, 'markAsRead']);
            Route::post('/read-all', [\App\Http\Controllers\Api\Mobile\MobileInformasiController::class, 'markAllAsRead']);
        });
    });
});

// ============================================
// WEB ADMIN ROUTES
// ============================================

// Admin Auth routes
// Route::post('/admin/register', [AuthController::class, 'registerAdmin']);
Route::post('/admin/login', [AuthController::class, 'loginAdmin']);
Route::post('/karyawan/login', [AuthController::class, 'loginKaryawan']);

// Protected routes untuk Admin
Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);
    Route::post('/admin/change-password', [AuthController::class, 'changePassword']);
    Route::get('/dashboard/data', [DashboardController::class, 'getDashboardData']);
    Route::post('/dashboard/clear-cache', [DashboardController::class, 'clearCache']);
    // IMPORTANT: Export/Import routes MUST be defined BEFORE apiResource

    // Divisi export/import routes
    Route::get('/divisis/export', [DivisiController::class, 'export']);
    Route::post('/divisis/import', [DivisiController::class, 'import']);

    // Jabatan export/import routes
    Route::get('/jabatans/export', [JabatanController::class, 'export']);
    Route::post('/jabatans/import', [JabatanController::class, 'import']);

    // Karyawan export/import routes
    Route::get('/karyawans/export', [KaryawanController::class, 'export']);
    Route::post('/karyawans/validate-import', [KaryawanController::class, 'validateImport']); // NEW
    Route::post('/karyawans/import', [KaryawanController::class, 'import']);
    Route::post('/karyawans/import-status', [KaryawanController::class, 'checkImportStatus']);
    Route::post('/karyawans/import-progress', [KaryawanController::class, 'getImportProgress']);

    // Admin routes - apiResource must come AFTER custom routes
    Route::apiResource('/divisis', DivisiController::class);
    Route::get('/jabatans/all', [JabatanController::class, 'getAll']);
    Route::apiResource('/jabatans', JabatanController::class);
    Route::apiResource('/karyawans', KaryawanController::class);
    Route::patch('/karyawans/{karyawan}/reset-password', [KaryawanController::class, 'resetPassword']);

    // Project export route - MUST be before apiResource
    Route::get('/projects/export', [ProjectController::class, 'export']);

    // Project routes
    Route::apiResource('/projects', ProjectController::class);
    Route::get('/projects/{projectId}/izin-configuration', [ProjectController::class, 'getIzinConfiguration']);
    Route::apiResource('/shift-projects', ShiftProjectController::class);
    Route::get('/projects/{project}/shifts', [ShiftProjectController::class, 'getByProject']);

    Route::prefix('karyawan-projects')->group(function () {
        Route::get('/', [KaryawanProjectController::class, 'index']);
        Route::get('/project/{projectId}', [KaryawanProjectController::class, 'getByProject']);
        Route::get('/available', [KaryawanProjectController::class, 'getAvailableKaryawan']);
        Route::post('/', [KaryawanProjectController::class, 'store']);
        Route::get('/{karyawanProject}', [KaryawanProjectController::class, 'show']);
        Route::patch('/{karyawanProject}/deactivate', [KaryawanProjectController::class, 'deactivate']);
        Route::patch('/{karyawanProject}/reactivate', [KaryawanProjectController::class, 'reactivate']);
        Route::delete('/{karyawanProject}', [KaryawanProjectController::class, 'destroy']);
        Route::get('/project/{projectId}/export', [KaryawanProjectController::class, 'export']);
        Route::post('/project/{projectId}/import', [KaryawanProjectController::class, 'import']);
    });

    Route::prefix('informasi')->group(function () {
        Route::get('/', [\App\Http\Controllers\InformasiController::class, 'index']);
        Route::get('/target-options', [\App\Http\Controllers\InformasiController::class, 'getTargetOptions']);
        Route::post('/', [\App\Http\Controllers\InformasiController::class, 'store']);
        Route::get('/{informasiId}', [\App\Http\Controllers\InformasiController::class, 'show']);
        Route::post('/{informasiId}', [\App\Http\Controllers\InformasiController::class, 'update']);
        Route::post('/{informasiId}/send', [\App\Http\Controllers\InformasiController::class, 'send']);
        Route::delete('/{informasiId}', [\App\Http\Controllers\InformasiController::class, 'destroy']);
        Route::get('/{informasiId}/penerima', [\App\Http\Controllers\InformasiController::class, 'getPenerima']);
    });

    // Jadwal Karyawan routes
    Route::prefix('jadwal-karyawan')->group(function () {
        Route::get('/project/{projectId}', [JadwalKaryawanController::class, 'getByProject']);
        Route::post('/project/{projectId}/import', [JadwalKaryawanController::class, 'import']);
        Route::get('/project/{projectId}/export', [JadwalKaryawanController::class, 'export']);
        Route::delete('/project/{projectId}/periode', [JadwalKaryawanController::class, 'deleteByPeriode']);
        Route::get('/project/{projectId}/summary', [JadwalKaryawanController::class, 'getSummary']);
    });

    // Pengajuan Izin routes untuk Admin
    Route::prefix('pengajuan-izin')->group(function () {
        // Get pengajuan izin by project
        Route::get('/project/{projectId}', [PengajuanIzinController::class, 'index']);

        // Get summary
        Route::get('/project/{projectId}/summary', [PengajuanIzinController::class, 'getSummary']);

        // Proses pengajuan (setujui/tolak)
        Route::post('/{pengajuanId}/proses', [PengajuanIzinController::class, 'prosesPengajuan']);

        // Get detail
        Route::get('/{pengajuanId}', [PengajuanIzinController::class, 'show']);

        // Hapus pengajuan (untuk admin)
        Route::delete('/{pengajuanId}', [PengajuanIzinController::class, 'hapusPengajuan']);
    });

    Route::prefix('presensi-harian')->group(function () {
        Route::get('/', [PresensiController::class, 'getRekapHarian']);
        Route::patch('/{presensiId}/status', [PresensiController::class, 'updateStatus']);
        Route::post('/{presensiId}/konfirmasi-lembur', [PresensiController::class, 'konfirmasiLembur']);
    });

    Route::get('/rekap-bulanan', [PresensiController::class, 'getRekapBulanan']);
    Route::get('/rekap-per-karyawan', [PresensiController::class, 'getRekapPerKaryawan']);

    Route::prefix('tukar-shift')->group(function () {
        // ADMIN WEB ENDPOINTS
        Route::get('/project/{projectId}', [TukarShiftController::class, 'indexAdmin']);
        Route::get('/project/{projectId}/summary', [TukarShiftController::class, 'getSummary']);

        // SHARED ENDPOINT (digunakan mobile & web)
        Route::get('/{tukarShiftId}', [TukarShiftController::class, 'show']);
    });

    // ============================================
    // NOTIFICATION ROUTES FOR WEB (ADMIN)
    // ============================================
    Route::prefix('notifications')->group(function () {
        Route::get('/', [NotificationController::class, 'getAdminNotifications']);
        Route::get('/unread-count', [NotificationController::class, 'getAdminUnreadCount']);
        Route::post('/{notificationId}/read', [NotificationController::class, 'markAsRead']);
        Route::post('/read-all', [NotificationController::class, 'markAllAsRead']);
        Route::delete('/{notificationId}', [NotificationController::class, 'delete']);
        Route::post('/fcm-token', [NotificationController::class, 'storeAdminToken']);
        Route::delete('/fcm-token', [NotificationController::class, 'deleteToken']);
    });

    // Pengajuan Lembur routes untuk Admin
    Route::prefix('pengajuan-lembur')->group(function () {
        // Get pengajuan lembur by project
        Route::get('/project/{projectId}', [PengajuanLemburController::class, 'index']);

        // Get summary
        Route::get('/project/{projectId}/summary', [PengajuanLemburController::class, 'getSummary']);

        // Proses pengajuan (setujui/tolak)
        Route::post('/{pengajuanId}/proses', [PengajuanLemburController::class, 'prosesPengajuan']);

        // Get detail
        Route::get('/{pengajuanId}', [PengajuanLemburController::class, 'show']);

        // Hapus pengajuan (untuk admin)
        Route::delete('/{pengajuanId}', [PengajuanLemburController::class, 'hapusPengajuan']);
    });
});
