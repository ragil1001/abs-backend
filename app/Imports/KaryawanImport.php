<?php

namespace App\Imports;

use App\Models\Karyawan;
use App\Models\Divisi;
use App\Models\Jabatan;
use App\Models\Project;
use App\Models\KaryawanProject;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithChunkReading;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Events\AfterImport;
use Carbon\Carbon;

class KaryawanImport implements ToCollection, WithChunkReading, WithEvents
{
    private $processedCount = 0;
    private $totalRows = 0;
    private $errors = [];
    private $cache = [];
    private $userId;
    private $importId;

    public function __construct($userId = null)
    {
        $this->userId = $userId;
        $this->importId = uniqid('import_', true);
        
        
        $this->preloadMasterData();
    }

    
    public function chunkSize(): int
    {
        return 100; 
    }

    
    private function preloadMasterData()
    {
        try {
            
            $this->cache['divisi'] = Divisi::all()->keyBy(function($item) {
                return strtolower(trim($item->nama));
            });

            
            $this->cache['jabatan'] = Jabatan::all()->keyBy(function($item) {
                return strtolower(trim($item->nama));
            });

            
            $this->cache['project'] = Project::all()->keyBy(function($item) {
                return strtolower(trim($item->nama));
            });

            
            $this->cache['existing_niks'] = Karyawan::pluck('id', 'nik')->toArray();

            
            
            
            
            
            

        } catch (\Exception $e) {
            
            throw $e;
        }
    }

    
    // PERBAIKAN 1: Method collection() - Ubah filter dan skip
public function collection(Collection $rows)
{
    try {
        // 🔍 DEBUG: Log total rows
        Log::info('📊 Total rows in Excel: ' . $rows->count());
        
        // Skip 3 rows (header + example rows), filter by NIK di kolom 23
        $validRows = $rows->skip(3)->filter(function($row) {
            $nik = $this->extractCellValue($row, 23); // Kolom 23 = NIK
            $hasNik = !empty($nik);
            
            // 🔍 DEBUG: Log setiap row
            if ($hasNik) {
                Log::info("✅ Valid row - NIK: {$nik}");
            }
            
            return $hasNik;
        });

        $this->totalRows = $validRows->count();
        
        // 🔍 DEBUG: Log valid rows
        Log::info("✅ Valid rows after filter: {$this->totalRows}");

        if ($this->totalRows === 0) {
            Log::error('❌ No valid data found. First 5 rows:', $rows->take(5)->toArray());
            throw new \Exception("Tidak ada data valid untuk diimport. Pastikan NIK tidak kosong di kolom W (kolom 23).");
        }

        $this->updateProgress(0, 'Memvalidasi data...');

        // Validate and prepare data
        $preparedData = $this->validateAndPrepareData($validRows);
        
        // 🔍 DEBUG: Log prepared data
        $dataCount = count(array_filter(array_keys($preparedData), function($key) {
            return strpos($key, '_') !== 0;
        }));
        Log::info("📦 Prepared data count: {$dataCount}");
        Log::info("⚠️ Skipped duplicate: " . ($preparedData['_skipped_duplicate'] ?? 0));
        Log::info("⚠️ Skipped error: " . ($preparedData['_skipped_error'] ?? 0));

        if (!empty($this->errors)) {
            $this->updateProgress(100, 'Validasi gagal', $this->errors);
            throw new \Exception("Validasi gagal:\n" . implode("\n", array_slice($this->errors, 0, 10)));
        }

        $this->updateProgress(30, 'Membuat master data...');

        // Create missing master data
        $this->createMissingMasterData($preparedData);

        $this->updateProgress(50, 'Menyimpan data karyawan...');

        // Bulk insert karyawan
        $this->bulkInsertKaryawan($preparedData);

        $this->updateProgress(80, 'Assign karyawan ke project...');

        // Bulk insert karyawan projects
        $this->bulkInsertKaryawanProjects($preparedData);

        $this->updateProgress(100, 'Import selesai', [
            'success' => $this->processedCount,
            'total' => $this->totalRows,
            'skipped_duplicate' => $preparedData['_skipped_duplicate'] ?? 0,
            'skipped_error' => $preparedData['_skipped_error'] ?? 0
        ]);

        Log::info('✅ Import completed', [
            'processed' => $this->processedCount,
            'total' => $this->totalRows,
            'skipped_duplicate' => $preparedData['_skipped_duplicate'] ?? 0,
            'skipped_error' => $preparedData['_skipped_error'] ?? 0
        ]);

    } catch (\Exception $e) {
        Log::error('❌ Import failed: ' . $e->getMessage(), [
            'trace' => $e->getTraceAsString()
        ]);
        
        $this->updateProgress(100, 'Import gagal', ['error' => $e->getMessage()]);
        throw $e;
    }
}

// PERBAIKAN 2: Method validateMasterData() - Sama seperti collection()
public static function validateMasterData(Collection $rows)
{
    // 🔍 DEBUG
    Log::info('📊 Validating - Total rows: ' . $rows->count());
    
    $validRows = $rows->skip(3)->filter(function($row) {
        $nik = $row[23] ?? ''; // Kolom 23 = NIK
        return !empty($nik);
    });
    
    Log::info("✅ Valid rows for validation: " . $validRows->count());

    if ($validRows->count() === 0) {
        Log::error('❌ No valid data in validation. First 5 rows:', $rows->take(5)->toArray());
        return [
            'valid' => false,
            'message' => 'Tidak ada data valid dalam file. Pastikan NIK tidak kosong di kolom W (kolom 23).'
        ];
    }

    // Collect unique values
    $divisiNames = [];
    $jabatanNames = [];
    $projectNames = [];

    foreach ($validRows as $row) {
        $divisi = trim($row[16] ?? ''); // Kolom 16: Divisi
        $jabatan = trim($row[15] ?? ''); // Kolom 15: Jabatan
        $project = trim($row[18] ?? ''); // Kolom 18: Project

        // Collect non-empty values
        if (!empty($divisi)) $divisiNames[] = $divisi;
        if (!empty($jabatan)) $jabatanNames[] = $jabatan;
        if (!empty($project)) $projectNames[] = $project;
    }

    $divisiNames = array_unique($divisiNames);
    $jabatanNames = array_unique($jabatanNames);
    $projectNames = array_unique($projectNames);

    // 🔍 DEBUG
    Log::info('📋 Master data found:', [
        'divisi' => $divisiNames,
        'jabatan' => $jabatanNames,
        'project' => $projectNames
    ]);

    // Check existing data
    $existingDivisi = Divisi::whereIn('nama', $divisiNames)->pluck('nama')->toArray();
    $existingJabatan = Jabatan::whereIn('nama', $jabatanNames)->pluck('nama')->toArray();
    $existingProject = Project::whereIn(DB::raw('LOWER(nama)'), 
                               array_map('strtolower', $projectNames))
                      ->pluck('nama')
                      ->toArray();

    $missingDivisi = array_diff($divisiNames, $existingDivisi);
    $missingJabatan = array_diff($jabatanNames, $existingJabatan);
    $missingProject = array_diff($projectNames, $existingProject);

    return [
        'valid' => true,
        'total_rows' => $validRows->count(),
        'master_data' => [
            'divisi' => [
                'total' => count($divisiNames),
                'existing' => count($existingDivisi),
                'missing' => array_values($missingDivisi),
                'will_create' => count($missingDivisi),
                'is_optional' => true 
            ],
            'jabatan' => [
                'total' => count($jabatanNames),
                'existing' => count($existingJabatan),
                'missing' => array_values($missingJabatan),
                'will_create' => count($missingJabatan)
            ],
            'project' => [
                'total' => count($projectNames),
                'existing' => count($existingProject),
                'missing' => array_values($missingProject),
                'must_exist' => true
            ]
        ],
        'can_proceed' => empty($missingProject)
    ];
}

    
private function validateAndPrepareData(Collection $rows)
{
    $preparedData = [];
    $processedNiks = [];
    $missingDivisi = [];
    $missingJabatan = [];
    $missingProject = [];
    
    
    $skippedDuplicate = 0;
    $skippedError = 0;

    foreach ($rows as $index => $row) {
        $rowNumber = $index + 4;

        try {
            $data = $this->extractRowData($row, $rowNumber);

            
            
            
            
            

            
            if (isset($this->cache['existing_niks'][$data['nik']])) {
                
                
                
                
                $skippedDuplicate++;
                continue;
            }

            
            if (isset($processedNiks[$data['nik']])) {
                
                
                
                
                $skippedDuplicate++;
                continue;
            }

            
            if (!empty($data['divisi_nama']) && !$data['divisi_found']) {
                $missingDivisi[$data['divisi_nama']] = $data['divisi_nama'];
            }
            
            if (!$data['jabatan_found']) {
                $missingJabatan[$data['jabatan_nama']] = $data['jabatan_nama'];
            }
            
            if (!empty($data['project_nama']) && !$data['project_found']) {
                $missingProject[$data['project_nama']] = $data['project_nama'];
            }

            $processedNiks[$data['nik']] = true;
            $preparedData[] = $data;

        } catch (\Exception $e) {
            
            
            
            
            $skippedError++;
            continue;
        }
    }

    
    
    
    
    
    
    
    
    
    

    
    $preparedData['_missing_divisi'] = array_values($missingDivisi);
    $preparedData['_missing_jabatan'] = array_values($missingJabatan);
    $preparedData['_missing_project'] = array_values($missingProject);
    $preparedData['_skipped_duplicate'] = $skippedDuplicate;
    $preparedData['_skipped_error'] = $skippedError;

    return $preparedData;
}

    
    private function extractRowData($row, $rowNumber)
{
    // 🔍 DEBUG: Log raw row data
    Log::info("🔍 Processing Row {$rowNumber}:");
    Log::info("  - Raw row count: " . count($row));
    Log::info("  - Row[23] (NIK): " . ($row[23] ?? 'NULL'));
    Log::info("  - Row[8] (Nama): " . ($row[8] ?? 'NULL'));
    Log::info("  - Row[37] (No Telp): " . ($row[37] ?? 'NULL'));
    Log::info("  - Row[16] (Jabatan): " . ($row[15] ?? 'NULL'));
    Log::info("  - Row[15] (Divisi): " . ($row[16] ?? 'NULL'));
    Log::info("  - Row[18] (Project): " . ($row[18] ?? 'NULL'));
    Log::info("  - Row[11] (Status): " . ($row[11] ?? 'NULL'));
    Log::info("  - Row[14] (Jenis Kelamin): " . ($row[14] ?? 'NULL'));
    Log::info("  - Row[19] (Tempat Lahir): " . ($row[19] ?? 'NULL'));
    Log::info("  - Row[20] (Tanggal Lahir): " . ($row[20] ?? 'NULL'));
    Log::info("  - Row[3] (Tanggal Bergabung): " . ($row[3] ?? 'NULL'));
    Log::info("  - Row[12] (Tanggal Keluar): " . ($row[12] ?? 'NULL'));
    
    // Extract data berdasarkan urutan kolom
    $nik = $this->extractNIK($this->extractCellValue($row, 23)); 
    $nama = trim($this->extractCellValue($row, 8)); 
    $status = trim($this->extractCellValue($row, 11)); 
    $tanggalKeluar = $this->extractCellValue($row, 12); 
    $tanggalBergabung = $this->extractCellValue($row, 3); 
    $jenisKelamin = strtoupper(trim($this->extractCellValue($row, 14))); 
    $jabatanNama = trim($this->extractCellValue($row, 16)); 
    $divisiNama = trim($this->extractCellValue($row, 15)); 
    $projectNama = trim($this->extractCellValue($row, 18)); 
    $tempatLahir = trim($this->extractCellValue($row, 19)); 
    $tanggalLahir = $this->extractCellValue($row, 20); 
    $noTelepon = trim($this->extractCellValue($row, 37)); 

    // 🔍 DEBUG: Log extracted values
    Log::info("📝 Extracted values:");
    Log::info("  - NIK: '{$nik}'");
    Log::info("  - Nama: '{$nama}'");
    Log::info("  - No Telp: '{$noTelepon}'");
    Log::info("  - Jabatan: '{$jabatanNama}'");
    Log::info("  - Divisi: '{$divisiNama}'");
    Log::info("  - Project: '{$projectNama}'");
    Log::info("  - Status: '{$status}'");
    Log::info("  - Jenis Kelamin: '{$jenisKelamin}'");
    Log::info("  - Tempat Lahir: '{$tempatLahir}'");
    Log::info("  - Tanggal Lahir: '{$tanggalLahir}'");
    Log::info("  - Tanggal Bergabung: '{$tanggalBergabung}'");

    // Validasi field wajib
    if (empty($nik)) {
        Log::error("❌ Row {$rowNumber}: NIK wajib diisi");
        throw new \Exception("NIK wajib diisi");
    }

    if (empty($nama)) {
        Log::error("❌ Row {$rowNumber}: Nama wajib diisi");
        throw new \Exception("Nama wajib diisi");
    }

    if (empty($noTelepon)) {
        Log::error("❌ Row {$rowNumber}: Nomor telepon wajib diisi");
        throw new \Exception("Nomor telepon wajib diisi");
    }

    if (empty($jabatanNama)) {
        Log::error("❌ Row {$rowNumber}: Jabatan wajib diisi");
        throw new \Exception("Jabatan wajib diisi");
    }

    if (empty($jenisKelamin) || !in_array($jenisKelamin, ['L', 'P'])) {
        Log::error("❌ Row {$rowNumber}: Jenis kelamin harus L atau P, got: '{$jenisKelamin}'");
        throw new \Exception("Jenis kelamin harus L atau P");
    }

    if (empty($tempatLahir)) {
        Log::error("❌ Row {$rowNumber}: Tempat lahir wajib diisi");
        throw new \Exception("Tempat lahir wajib diisi");
    }
    
    $tanggalLahirParsed = $this->parseDate($tanggalLahir);
    if (!$tanggalLahirParsed) {
        Log::error("❌ Row {$rowNumber}: Format tanggal lahir tidak valid: '{$tanggalLahir}'");
        throw new \Exception("Format tanggal lahir tidak valid: '{$tanggalLahir}'");
    }

    $tanggalBergabungParsed = $this->parseDate($tanggalBergabung);
    if (!$tanggalBergabungParsed) {
        Log::error("❌ Row {$rowNumber}: Format tanggal bergabung tidak valid: '{$tanggalBergabung}'");
        throw new \Exception("Format tanggal bergabung tidak valid: '{$tanggalBergabung}'");
    }

    // Parse status
    $statusParsed = strtolower($status) === 'aktif' ? 'aktif' : 'tidak_aktif';
    
    $tanggalKeluarParsed = null;
    if ($statusParsed === 'tidak_aktif' && !empty($tanggalKeluar)) {
        $tanggalKeluarParsed = $this->parseDate($tanggalKeluar);
    }

    // Check master data
    $divisiKey = !empty($divisiNama) ? strtolower(trim($divisiNama)) : null;
    $jabatanKey = strtolower(trim($jabatanNama));
    $projectKey = !empty($projectNama) ? strtolower(trim($projectNama)) : null;

    $divisiFound = $divisiKey ? $this->cache['divisi']->has($divisiKey) : true; 
    $jabatanFound = $this->cache['jabatan']->has($jabatanKey);
    $projectFound = $projectKey ? $this->cache['project']->has($projectKey) : true;

    Log::info("🔍 Master data check:");
    Log::info("  - Divisi '{$divisiNama}' found: " . ($divisiFound ? 'YES' : 'NO'));
    Log::info("  - Jabatan '{$jabatanNama}' found: " . ($jabatanFound ? 'YES' : 'NO'));
    Log::info("  - Project '{$projectNama}' found: " . ($projectFound ? 'YES' : 'NO'));

    // Generate username
    $username = $nik;
    $usernameCounter = 1;
    while (Karyawan::where('username', $username)->exists()) {
        $username = $nik . $usernameCounter;
        $usernameCounter++;
    }

    Log::info("✅ Row {$rowNumber} extracted successfully");

    return [
        'nik' => $nik,
        'nama' => $nama,
        'no_telepon' => $noTelepon,
        'divisi_nama' => $divisiNama, 
        'divisi_found' => $divisiFound,
        'jabatan_nama' => $jabatanNama,
        'jabatan_found' => $jabatanFound,
        'project_nama' => $projectNama,
        'project_found' => $projectFound,
        'jenis_kelamin' => $jenisKelamin,
        'tempat_lahir' => $tempatLahir,
        'tanggal_lahir' => $tanggalLahirParsed->format('Y-m-d'),
        'tanggal_bergabung' => $tanggalBergabungParsed->format('Y-m-d'),
        'tanggal_keluar' => $tanggalKeluarParsed ? $tanggalKeluarParsed->format('Y-m-d') : null,
        'status' => $statusParsed,
        'username' => $username,
        'password' => Hash::make($tanggalLahirParsed->format('dmY')),
        'sisa_cuti_tahunan' => 12,
        'row_number' => $rowNumber
    ];
}

    
    private function createMissingMasterData(&$preparedData)
    {
        DB::beginTransaction();
        
        try {
            
            if (!empty($preparedData['_missing_divisi'])) {
                $divisiData = array_map(function($nama) {
                    return [
                        'nama' => $nama,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }, $preparedData['_missing_divisi']);

                DB::table('divisis')->insert($divisiData);
                
                
                $this->cache['divisi'] = Divisi::all()->keyBy(function($item) {
                    return strtolower(trim($item->nama));
                });

                
            }

            
            if (!empty($preparedData['_missing_jabatan'])) {
                $jabatanData = array_map(function($nama) {
                    return [
                        'nama' => $nama,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }, $preparedData['_missing_jabatan']);

                DB::table('jabatans')->insert($jabatanData);
                
                
                $this->cache['jabatan'] = Jabatan::all()->keyBy(function($item) {
                    return strtolower(trim($item->nama));
                });

                
            }

            
            if (!empty($preparedData['_missing_project'])) {
                $missingProjects = implode(', ', $preparedData['_missing_project']);
                throw new \Exception("Project belum ada: {$missingProjects}. Buat project terlebih dahulu.");
            }

            DB::commit();

        } catch (\Exception $e) {
            DB::rollBack();
            throw $e;
        }
    }

    
private function bulkInsertKaryawan(&$preparedData)
{
    $karyawanData = [];
    $totalRows = count(array_filter(array_keys($preparedData), function($key) {
        return strpos($key, '_') !== 0;
    }));

    
    if ($totalRows === 0) {
        
        return;
    }

    

    foreach ($preparedData as $key => $data) {
        if (strpos($key, '_') === 0) continue;

        
        $divisiId = null;
        if (!empty($data['divisi_nama'])) {
            $divisiKey = strtolower(trim($data['divisi_nama']));
            $divisi = $this->cache['divisi']->get($divisiKey);
            $divisiId = $divisi ? $divisi->id : null;
        }

        $jabatanKey = strtolower(trim($data['jabatan_nama']));
        $jabatan = $this->cache['jabatan']->get($jabatanKey);

        if (!$jabatan) {
            
            
            
            
            continue;
        }

        $karyawanData[] = [
            'nik' => $data['nik'],
            'nama' => $data['nama'],
            'no_telepon' => $data['no_telepon'],
            'divisi_id' => $divisiId,
            'jabatan_id' => $jabatan->id,
            'jenis_kelamin' => $data['jenis_kelamin'],
            'tempat_lahir' => $data['tempat_lahir'],
            'tanggal_lahir' => $data['tanggal_lahir'],
            'tanggal_bergabung' => $data['tanggal_bergabung'],
            'tanggal_keluar' => $data['tanggal_keluar'],
            'username' => $data['username'],
            'password' => $data['password'],
            'status' => $data['status'],
            'sisa_cuti_tahunan' => $data['sisa_cuti_tahunan'],
            'created_at' => now(),
            'updated_at' => now()
        ];
    }

    
    

    if (empty($karyawanData)) {
        
        return;
    }

    
    try {
        $chunks = array_chunk($karyawanData, 100);
        $totalChunks = count($chunks);
        
        
        
        foreach ($chunks as $index => $chunk) {
            try {
                $insertedCount = DB::table('karyawans')->insert($chunk);
                $this->processedCount += count($chunk);
                
                
                
                
                
                
                
                $percent = 50 + (($index + 1) / $totalChunks) * 20; 
                $this->updateProgress(
                    $percent, 
                    'Menyimpan karyawan... ' . $this->processedCount . '/' . $totalRows,
                    [
                        'processed' => $this->processedCount,
                        'total' => $totalRows
                    ]
                );
            } catch (\Exception $chunkError) {
                
                
                
                
                
                
                throw $chunkError;
            }
        }

        
        
        

    } catch (\Exception $e) {
        
        
        
        
        throw $e;
    }
}

    
    private function bulkInsertKaryawanProjects(&$preparedData)
    {
        
        $karyawanByNik = Karyawan::whereIn('nik', array_column($preparedData, 'nik'))
            ->pluck('id', 'nik')
            ->toArray();

        $karyawanProjectData = [];

        foreach ($preparedData as $key => $data) {
            if (strpos($key, '_') === 0) continue;

            if (empty($data['project_nama'])) continue;

            $projectKey = strtolower(trim($data['project_nama']));
            $project = $this->cache['project']->get($projectKey);

            if (!$project) {
                
                
                
                
                continue;
            }

            $karyawanId = $karyawanByNik[$data['nik']] ?? null;

            if (!$karyawanId) {
                
                
                
                
                continue;
            }

            
            $sisaCutiTahunan = 12;

            $karyawanProjectData[] = [
                'karyawan_id' => $karyawanId,
                'project_id' => $project->id,
                'tanggal_assign' => $data['tanggal_bergabung'],
                'tanggal_selesai' => $data['tanggal_keluar'],
                'status' => $data['status'],
                'created_at' => now(),
                'updated_at' => now()
            ];

            
            DB::table('karyawans')
                ->where('id', $karyawanId)
                ->update(['sisa_cuti_tahunan' => $sisaCutiTahunan]);
        }

        if (!empty($karyawanProjectData)) {
            
            foreach (array_chunk($karyawanProjectData, 100) as $chunk) {
                DB::table('karyawan_projects')->insert($chunk);
            }

            
        }
    }

    
    private function extractCellValue($row, $index)
    {
        return $row[$index] ?? '';
    }

    
    private function extractNIK($value)
    {
        if (is_numeric($value)) {
            $nik = number_format($value, 0, '', '');
        } else {
            $nik = preg_replace('/[^0-9]/', '', trim($value));
        }

        if (strlen($nik) < 16 && strlen($nik) >= 10) {
            $nik = str_pad($nik, 16, '0', STR_PAD_LEFT);
        }

        return $nik;
    }

    
    private function parseDate($dateValue)
    {
        if (empty($dateValue)) {
            return null;
        }

        try {
            
            if (is_numeric($dateValue)) {
                try {
                    $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateValue);
                    $carbonDate = Carbon::instance($dateTime);
                    
                    if ($carbonDate->year >= 1900 && $carbonDate->year <= 2100) {
                        return $carbonDate;
                    }
                } catch (\Exception $e) {
                    throw $e;
                }
            }

            $dateValue = trim($dateValue);

            
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                return Carbon::createFromFormat('Y-m-d', $dateValue);
            }

            
            $indonesianMonths = [
                'januari' => '01', 'februari' => '02', 'maret' => '03',
                'april' => '04', 'mei' => '05', 'juni' => '06',
                'juli' => '07', 'agustus' => '08', 'september' => '09',
                'oktober' => '10', 'november' => '11', 'desember' => '12'
            ];

            foreach ($indonesianMonths as $monthName => $monthNum) {
                if (stripos($dateValue, $monthName) !== false) {
                    if (preg_match('/(\d{1,2})\s+' . $monthName . '\s+(\d{4})/i', $dateValue, $matches)) {
                        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                        $year = $matches[2];
                        return Carbon::createFromFormat('Y-m-d', "{$year}-{$monthNum}-{$day}");
                    }
                }
            }

            
            $formats = ['d/m/Y', 'd-m-Y', 'Y/m/d', 'm/d/Y', 'd/m/y', 'd-m-y'];
            foreach ($formats as $format) {
                try {
                    $date = Carbon::createFromFormat($format, $dateValue);
                    if ($date !== false && $date->year >= 1900 && $date->year <= 2100) {
                        return $date;
                    }
                } catch (\Exception $e) {
                    continue;
                }
            }

            return Carbon::parse($dateValue);

        } catch (\Exception $e) {
            return null;
        }
    }

    
    private function updateProgress($percent, $message, $data = [])
    {
        Cache::put("import_progress_{$this->importId}", [
            'percent' => $percent,
            'message' => $message,
            'data' => $data,
            'updated_at' => now()->toIso8601String()
        ], 300);
    }

    
    public function getImportId()
    {
        return $this->importId;
    }

    
    public function registerEvents(): array
    {
        return [
            AfterImport::class => function(AfterImport $event) {
                Log::info('Import completed', [
                    'import_id' => $this->importId,
                    'processed' => $this->processedCount
                ]);
            }
        ];
    }

    public function getProcessedCount()
    {
        return $this->processedCount;
    }

    public function getTotalRows()
    {
        return $this->totalRows;
    }

    public function getErrors()
    {
        return $this->errors;
    }

    
    // public static function validateMasterData(Collection $rows)
    // {
    //     $validRows = $rows->skip(3)->filter(function($row) {
    //         return !empty($row[1]); 
    //     });

    //     if ($validRows->count() === 0) {
    //         return [
    //             'valid' => false,
    //             'message' => 'Tidak ada data valid dalam file'
    //         ];
    //     }

        
    //     $divisiNames = [];
    //     $jabatanNames = [];
    //     $projectNames = [];

    //     foreach ($validRows as $row) {
    //         $divisi = trim($row[16] ?? '');
    //         $jabatan = trim($row[15] ?? '');
    //         $project = trim($row[18] ?? '');

            
    //         if (!empty($divisi)) $divisiNames[] = $divisi;
    //         if (!empty($jabatan)) $jabatanNames[] = $jabatan;
    //         if (!empty($project)) $projectNames[] = $project;
    //     }

    //     $divisiNames = array_unique($divisiNames);
    //     $jabatanNames = array_unique($jabatanNames);
    //     $projectNames = array_unique($projectNames);

        
    //     $existingDivisi = Divisi::whereIn('nama', $divisiNames)->pluck('nama')->toArray();
    //     $existingJabatan = Jabatan::whereIn('nama', $jabatanNames)->pluck('nama')->toArray();
    //     $existingProject = Project::whereIn(DB::raw('LOWER(nama)'), 
    //                                array_map('strtolower', $projectNames))
    //                       ->pluck('nama')
    //                       ->toArray();

    //     $missingDivisi = array_diff($divisiNames, $existingDivisi);
    //     $missingJabatan = array_diff($jabatanNames, $existingJabatan);
    //     $missingProject = array_diff($projectNames, $existingProject);

    //     return [
    //         'valid' => true,
    //         'total_rows' => $validRows->count(),
    //         'master_data' => [
    //             'divisi' => [
    //                 'total' => count($divisiNames),
    //                 'existing' => count($existingDivisi),
    //                 'missing' => array_values($missingDivisi),
    //                 'will_create' => count($missingDivisi),
    //                 'is_optional' => true 
    //             ],
    //             'jabatan' => [
    //                 'total' => count($jabatanNames),
    //                 'existing' => count($existingJabatan),
    //                 'missing' => array_values($missingJabatan),
    //                 'will_create' => count($missingJabatan)
    //             ],
    //             'project' => [
    //                 'total' => count($projectNames),
    //                 'existing' => count($existingProject),
    //                 'missing' => array_values($missingProject),
    //                 'must_exist' => true
    //             ]
    //         ],
    //         'can_proceed' => empty($missingProject)
    //     ];
    // }
}