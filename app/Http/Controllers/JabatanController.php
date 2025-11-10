<?php

namespace App\Http\Controllers;

use App\Models\Jabatan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\JabatanImport;
use App\Exports\JabatanExport;

class JabatanController extends Controller
{
    public function index(Request $request)
    {
        $jabatans = Jabatan::query();

        if ($request->has('search')) {
            $jabatans->where('nama', 'like', '%' . $request->search . '%');
        }

        $sortField = $request->input('sort_field', 'id');
        $sortDirection = $request->input('sort_direction', 'asc');
        $jabatans->orderBy($sortField, $sortDirection);

        $perPage = $request->input('per_page', 10);
        $jabatans = $jabatans->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $jabatans->items(),
            'pagination' => [
                'current_page' => $jabatans->currentPage(),
                'per_page' => $jabatans->perPage(),
                'total' => $jabatans->total(),
                'last_page' => $jabatans->lastPage(),
            ],
        ]);
    }

    
    public function getAll()
    {
        $jabatans = Jabatan::orderBy('nama', 'asc')->get(['id', 'nama']);

        return response()->json([
            'success' => true,
            'data' => $jabatans,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'nama' => 'required|string|max:255|unique:jabatans,nama',
        ], [
            'nama.required' => 'Nama jabatan wajib diisi',
            'nama.unique' => 'Nama jabatan sudah ada, gunakan nama lain',
            'nama.max' => 'Nama jabatan maksimal 255 karakter',
        ]);

        try {
            DB::beginTransaction();

            $jabatan = new Jabatan();
            $jabatan->nama = $request->nama;
            $jabatan->save();

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Jabatan berhasil dibuat',
                'data' => $jabatan,
            ], 201);

        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menyimpan jabatan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function show(Jabatan $jabatan)
    {
        return response()->json([
            'success' => true,
            'data' => $jabatan,
        ]);
    }

    public function update(Request $request, Jabatan $jabatan)
    {
        $request->validate([
            'nama' => 'required|string|max:255|unique:jabatans,nama,' . $jabatan->id,
        ], [
            'nama.required' => 'Nama jabatan wajib diisi',
            'nama.unique' => 'Nama jabatan sudah ada, gunakan nama lain',
            'nama.max' => 'Nama jabatan maksimal 255 karakter',
        ]);

        try {
            $jabatan->update([
                'nama' => $request->nama
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Jabatan berhasil diperbarui',
                'data' => $jabatan,
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memperbarui jabatan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function destroy(Jabatan $jabatan)
    {
        try {
            $isUsed = DB::table('karyawans')
                ->where('jabatan_id', $jabatan->id)
                ->exists();

            if ($isUsed) {
                return response()->json([
                    'success' => false,
                    'message' => 'Jabatan tidak dapat dihapus karena masih digunakan oleh karyawan',
                ], 422);
            }

            $jabatan->delete();

            return response()->json([
                'success' => true,
                'message' => 'Jabatan berhasil dihapus',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus jabatan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function export()
    {
        try {
            $count = Jabatan::count();
            if ($count === 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Tidak ada data jabatan untuk diekspor',
                ], 404);
            }

            $fileName = 'data-jabatan-' . date('Y-m-d-His') . '.xlsx';
            
            return Excel::download(new JabatanExport, $fileName, \Maatwebsite\Excel\Excel::XLSX, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="' . $fileName . '"',
            ]);

        } catch (\Exception $e) {
            
            
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

            Excel::import(new JabatanImport, $request->file('file'));

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Data jabatan berhasil diimport',
            ], 200);

        } catch (\Maatwebsite\Excel\Validators\ValidationException $e) {
            DB::rollback();
            
            $failures = $e->failures();
            $errors = [];
            foreach ($failures as $failure) {
                $errors[] = 'Baris ' . $failure->row() . ': ' . implode(', ', $failure->errors());
            }
            
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal saat import',
                'errors' => $errors,
            ], 422);
            
        } catch (\Exception $e) {
            DB::rollback();
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengimport data: ' . $e->getMessage(),
            ], 422);
        }
    }
}