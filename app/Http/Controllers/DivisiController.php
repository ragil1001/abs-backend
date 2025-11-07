<?php

namespace App\Http\Controllers;

use App\Models\Divisi;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\DivisiImport;
use App\Exports\DivisiExport;

class DivisiController extends Controller
{
    /**
     * 🚀 FIXED: Get all divisi - NO CACHING for master data that changes frequently
     * Cache can cause stale data issues, especially after label changes
     */
    public function index(Request $request)
    {
        try {
            // 🔥 CRITICAL: Always fetch fresh data, no caching
            // This ensures data is always up-to-date after any changes
            $divisis = Divisi::select('id', 'nama', 'created_at', 'updated_at')
                ->orderBy('id', 'asc')
                ->get();

            // Clear any existing cache to prevent stale data
            $this->clearAllDivisiCache();

            return response()->json([
                'success' => true,
                'data' => $divisis,
            ]);

        } catch (\Exception $e) {
            // \Log::error('Divisi index error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data divisi: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama' => 'required|string|max:255|unique:divisis,nama',
        ], [
            'nama.required' => 'Nama penempatan wajib diisi',
            'nama.unique' => 'Nama penempatan sudah ada, gunakan nama lain',
            'nama.max' => 'Nama penempatan maksimal 255 karakter',
        ]);

        try {
            DB::beginTransaction();

            $divisi = new Divisi();
            $divisi->nama = trim($request->nama);
            $divisi->save();

            DB::commit();

            // 🔥 CRITICAL: Clear all caches after create
            $this->clearAllDivisiCache();

            // \Log::info('Divisi created: ' . $divisi->nama);

            return response()->json([
                'success' => true,
                'message' => 'Penempatan berhasil dibuat',
                'data' => $divisi,
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            // \Log::error('Divisi store error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan penempatan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(Divisi $divisi)
    {
        return response()->json([
            'success' => true,
            'data' => $divisi,
        ]);
    }

    public function update(Request $request, Divisi $divisi)
    {
        $request->validate([
            'nama' => 'required|string|max:255|unique:divisis,nama,' . $divisi->id,
        ], [
            'nama.required' => 'Nama penempatan wajib diisi',
            'nama.unique' => 'Nama penempatan sudah ada, gunakan nama lain',
            'nama.max' => 'Nama penempatan maksimal 255 karakter',
        ]);

        try {
            $oldNama = $divisi->nama;
            
            $divisi->update([
                'nama' => trim($request->nama)
            ]);

            // 🔥 CRITICAL: Clear all caches after update
            $this->clearAllDivisiCache();

            // \Log::info('Divisi updated: ' . $oldNama . ' -> ' . $divisi->nama);

            return response()->json([
                'success' => true,
                'message' => 'Penempatan berhasil diperbarui',
                'data' => $divisi,
            ]);

        } catch (\Exception $e) {
            // \Log::error('Divisi update error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui penempatan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Divisi $divisi)
    {
        try {
            // Check if divisi is being used by karyawan
            $isUsed = DB::table('karyawans')
                ->where('divisi_id', $divisi->id)
                ->exists();

            if ($isUsed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Penempatan tidak dapat dihapus karena masih digunakan oleh karyawan',
                ], 422);
            }

            $namaDeleted = $divisi->nama;
            $divisi->delete();

            // 🔥 CRITICAL: Clear all caches after delete
            $this->clearAllDivisiCache();

            // \Log::info('Divisi deleted: ' . $namaDeleted);

            return response()->json([
                'success' => true,
                'message' => 'Penempatan berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            // \Log::error('Divisi destroy error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus penempatan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function export()
    {
        try {
            // Check if there's data to export
            $count = Divisi::count();
            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data penempatan untuk diekspor',
                ], 404);
            }

            $fileName = 'data-penempatan-' . date('Y-m-d-His') . '.xlsx';
            
            // \Log::info('Exporting divisi: ' . $count . ' records');
            
            return Excel::download(new DivisiExport, $fileName, \Maatwebsite\Excel\Excel::XLSX, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]);

        } catch (\Exception $e) {
            // \Log::error('Divisi export error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengekspor data: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => 'required|mimes:xlsx,xls|max:2048',
        ], [
            'file.required' => 'File wajib dipilih',
            'file.mimes' => 'File harus berformat Excel (.xlsx atau .xls)',
            'file.max' => 'Ukuran file maksimal 2MB',
        ]);

        try {
            DB::beginTransaction();

            $import = new DivisiImport;
            Excel::import($import, $request->file('file'));

            DB::commit();

            // 🔥 CRITICAL: Clear all caches after import
            $this->clearAllDivisiCache();

            $importedCount = $import->getRowCount();
            // \Log::info('Divisi imported: ' . $importedCount . ' records');

            return response()->json([
                'success' => true,
                'message' => 'Data penempatan berhasil diimport (' . $importedCount . ' data)',
            ], 200);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollback();
            
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = 'Baris ' . $failure->row() . ': ' . implode(', ', $failure->errors());
            }
            
            // \Log::error('Divisi import validation error: ' . json_encode($errors));
            
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal saat import',
                'errors' => $errors,
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollback();
            // \Log::error('Divisi import error: ' . $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengimport data: ' . $e->getMessage(),
            ], 422);
        }
    }

    /**
     * 🔥 CRITICAL: Clear all divisi-related caches
     * This ensures no stale data remains after any changes
     */
    private function clearAllDivisiCache()
    {
        // Clear all possible divisi cache keys
        $cacheKeys = [
            'divisi_list_all',
            'divisis_list',
            'divisi_list',
            'master_divisi',
            'penempatan_list',
        ];

        foreach ($cacheKeys as $key) {
            Cache::forget($key);
        }

        // Clear Laravel's query cache if using it
        if (method_exists(Cache::getStore(), 'flush')) {
            // Only flush divisi-related cache patterns
            try {
                $allKeys = Cache::get('all_cache_keys', []);
                foreach ($allKeys as $key) {
                    if (strpos($key, 'divisi') !== false || strpos($key, 'penempatan') !== false) {
                        Cache::forget($key);
                    }
                }
            } catch (\Exception $e) {
                // Silent fail, not critical
            }
        }

        // Also clear karyawan cache since it depends on divisi data
        $karyawanCacheKeys = [
            'karyawan_cache_keys',
            'karyawans_list',
        ];

        foreach ($karyawanCacheKeys as $key) {
            Cache::forget($key);
        }

        // Clear paginated karyawan cache
        try {
            $keys = Cache::get('karyawan_cache_keys', []);
            foreach ($keys as $key) {
                if (strpos($key, 'karyawan_page_') === 0) {
                    Cache::forget($key);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // \Log::info('All divisi caches cleared');
    }
}