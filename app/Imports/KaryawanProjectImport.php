<?php


namespace App\Imports;

use App\Models\KaryawanProject;
use App\Models\Karyawan;
use App\Models\Divisi;
use App\Models\Jabatan;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithValidation;

class KaryawanProjectImport implements ToModel, WithHeadingRow, WithValidation
{
    protected $projectId;
    protected $successCount = 0;
    protected $errors = [];

    public function __construct($projectId)
    {
        $this->projectId = $projectId;
    }

    public function model(array $row)
    {
        try {
            
            if (empty($row['nik'])) {
                $this->errors[] = "Baris dengan NIK kosong diabaikan";
                return null;
            }

            
            $karyawan = Karyawan::where('nik', $row['nik'])->first();

            if (!$karyawan) {
                $this->errors[] = "NIK {$row['nik']} tidak ditemukan dalam database";
                return null;
            }

            
            if (!empty($row['divisi'])) {
                $divisi = Divisi::where('nama', $row['divisi'])->first();
                if (!$divisi || $divisi->id != $karyawan->divisi_id) {
                    $this->errors[] = "NIK {$row['nik']}: Divisi tidak sesuai. Expected: {$karyawan->divisi->nama}, Got: {$row['divisi']}";
                }
            }

            
            if (!empty($row['jabatan'])) {
                $jabatan = Jabatan::where('nama', $row['jabatan'])->first();
                if (!$jabatan || $jabatan->id != $karyawan->jabatan_id) {
                    $this->errors[] = "NIK {$row['nik']}: Jabatan tidak sesuai. Expected: {$karyawan->jabatan->nama}, Got: {$row['jabatan']}";
                }
            }

            
            $hasActiveProject = KaryawanProject::where('karyawan_id', $karyawan->id)
                                               ->where('status', 'aktif')
                                               ->exists();

            if ($hasActiveProject) {
                $activeProject = KaryawanProject::with('project')
                                                ->where('karyawan_id', $karyawan->id)
                                                ->where('status', 'aktif')
                                                ->first();
                $this->errors[] = "NIK {$row['nik']} ({$karyawan->nama}) sudah aktif di project: {$activeProject->project->nama}";
                return null;
            }

            
            $existing = KaryawanProject::where('karyawan_id', $karyawan->id)
                                       ->where('project_id', $this->projectId)
                                       ->first();

            if ($existing) {
                if ($existing->status === 'aktif') {
                    $this->errors[] = "NIK {$row['nik']} ({$karyawan->nama}) sudah aktif di project ini";
                    return null;
                } else {
                    
                    $existing->aktifkanKembali();
                    $this->successCount++;
                    return null; 
                }
            }

            
            $this->successCount++;
            
            return new KaryawanProject([
                'karyawan_id' => $karyawan->id,
                'project_id' => $this->projectId,
                'tanggal_assign' => now()->format('Y-m-d'),
                'status' => 'aktif'
            ]);

        } catch (\Exception $e) {
            $nik = $row['nik'] ?? 'unknown';
            $this->errors[] = "NIK {$nik}: " . $e->getMessage();
            return null;
        }
    }

    public function rules(): array
    {
        return [
            'nik' => 'required|string',
            'nama' => 'nullable|string', 
            'divisi' => 'nullable|string', 
            'jabatan' => 'nullable|string', 
        ];
    }

    public function customValidationMessages()
    {
        return [
            'nik.required' => 'NIK wajib diisi',
        ];
    }

    public function getSuccessCount()
    {
        return $this->successCount;
    }

    public function getErrors()
    {
        return $this->errors;
    }
}