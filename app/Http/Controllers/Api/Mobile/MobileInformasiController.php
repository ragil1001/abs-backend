<?php

namespace App\Http\Controllers\Api\Mobile;

use App\Http\Controllers\Controller;
use App\Models\InformasiKaryawan;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class MobileInformasiController extends Controller
{
    
    public function index(Request $request)
    {
        try {
            $karyawan = $request->user();

            $query = InformasiKaryawan::with([
                    'informasi.user:id,username',
                    'informasi' => function($q) {
                        $q->select('id', 'user_id', 'judul', 'konten', 'file_path', 'file_name', 'file_type', 'file_size', 'dikirim_at');
                    }
                ])
                ->where('karyawan_id', $karyawan->id);

            
            if ($request->filled('is_read') && $request->is_read !== 'all') {
                $isRead = $request->is_read === 'true' || $request->is_read === '1';
                $query->where('is_read', $isRead);
            }

            
            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('informasi', function($q) use ($search) {
                    $q->where('judul', 'ILIKE', '%' . $search . '%')
                      ->orWhere('konten', 'ILIKE', '%' . $search . '%');
                });
            }

            $perPage = min((int)$request->input('per_page', 20), 100);
            $result = $query->latest()->paginate($perPage);

            $data = $result->map(function($infKaryawan) {
                return [
                    'id' => $infKaryawan->id,
                    'informasi_id' => $infKaryawan->informasi->id,
                    'judul' => $infKaryawan->informasi->judul,
                    'konten' => $infKaryawan->informasi->konten,
                    'konten_preview' => \Str::limit($infKaryawan->informasi->konten, 100),
                    'has_file' => !empty($infKaryawan->informasi->file_path),
                    'file_name' => $infKaryawan->informasi->file_name,
                    'file_type' => $infKaryawan->informasi->file_type,
                    'file_url' => $infKaryawan->informasi->file_url,
                    'file_size_formatted' => $infKaryawan->informasi->file_size_formatted,
                    'is_read' => $infKaryawan->is_read,
                    'read_at' => $infKaryawan->read_at?->format('Y-m-d H:i:s'),
                    'dikirim_at' => $infKaryawan->informasi->dikirim_at?->format('Y-m-d H:i:s'),
                    'time_ago' => $infKaryawan->informasi->time_ago,
                    'created_by' => $infKaryawan->informasi->user->username ?? 'System',
                    'created_at' => $infKaryawan->created_at->format('Y-m-d H:i:s')
                ];
            });

            
            $unreadCount = InformasiKaryawan::where('karyawan_id', $karyawan->id)
                                            ->where('is_read', false)
                                            ->count();

            return response()->json([
                'success' => true,
                'data' => $data,
                'unread_count' => $unreadCount,
                'pagination' => [
                    'current_page' => $result->currentPage(),
                    'per_page' => $result->perPage(),
                    'total' => $result->total(),
                    'last_page' => $result->lastPage(),
                ]
            ]);

        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat data informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function show(Request $request, $informasiKaryawanId)
    {
        try {
            $karyawan = $request->user();

            $infKaryawan = InformasiKaryawan::with([
                    'informasi.user:id,username',
                    'informasi'
                ])
                ->where('karyawan_id', $karyawan->id)
                ->findOrFail($informasiKaryawanId);

            
            $infKaryawan->markAsRead();

            $data = [
                'id' => $infKaryawan->id,
                'informasi_id' => $infKaryawan->informasi->id,
                'judul' => $infKaryawan->informasi->judul,
                'konten' => $infKaryawan->informasi->konten,
                'file_url' => $infKaryawan->informasi->file_url,
                'file_name' => $infKaryawan->informasi->file_name,
                'file_type' => $infKaryawan->informasi->file_type,
                'file_size_formatted' => $infKaryawan->informasi->file_size_formatted,
                'is_read' => $infKaryawan->is_read,
                'read_at' => $infKaryawan->read_at?->format('Y-m-d H:i:s'),
                'dikirim_at' => $infKaryawan->informasi->dikirim_at?->format('Y-m-d H:i:s'),
                'time_ago' => $infKaryawan->informasi->time_ago,
                'created_by' => $infKaryawan->informasi->user->username ?? 'System',
                'created_at' => $infKaryawan->created_at->format('Y-m-d H:i:s')
            ];

            return response()->json([
                'success' => true,
                'data' => $data
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Informasi tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat detail informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function markAsRead(Request $request, $informasiKaryawanId)
    {
        try {
            $karyawan = $request->user();

            $infKaryawan = InformasiKaryawan::where('karyawan_id', $karyawan->id)
                                            ->findOrFail($informasiKaryawanId);

            $infKaryawan->markAsRead();

            return response()->json([
                'success' => true,
                'message' => 'Informasi ditandai sebagai dibaca'
            ]);

        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Informasi tidak ditemukan'
            ], 404);
        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function markAllAsRead(Request $request)
    {
        try {
            $karyawan = $request->user();

            $count = InformasiKaryawan::where('karyawan_id', $karyawan->id)
                                      ->where('is_read', false)
                                      ->update([
                                          'is_read' => true,
                                          'read_at' => now()
                                      ]);

            return response()->json([
                'success' => true,
                'message' => 'Semua informasi ditandai sebagai dibaca',
                'count' => $count
            ]);

        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal menandai semua informasi: ' . $e->getMessage()
            ], 500);
        }
    }

    
    public function getUnreadCount(Request $request)
    {
        try {
            $karyawan = $request->user();

            $count = InformasiKaryawan::where('karyawan_id', $karyawan->id)
                                      ->where('is_read', false)
                                      ->count();

            return response()->json([
                'success' => true,
                'unread_count' => $count
            ]);

        } catch (\Exception $e) {
            
            
            return response()->json([
                'success' => false,
                'message' => 'Gagal memuat jumlah informasi: ' . $e->getMessage()
            ], 500);
        }
    }
}