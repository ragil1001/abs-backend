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
        
        // Pre-cache master data
        $this->preloadMasterData();
    }

    /**
     * Chunk reading untuk file besar
     */
    public function chunkSize(): int
    {
        return 100; // Process 100 rows at a time
    }

    /**
     * Pre-load semua master data ke memory untuk performa
     */
    private function preloadMasterData()
    {
        try {
            // Cache divisi
            $this->cache['divisi'] = Divisi::all()->keyBy(function($item) {
                return strtolower(trim($item->nama));
            });

            // Cache jabatan
            $this->cache['jabatan'] = Jabatan::all()->keyBy(function($item) {
                return strtolower(trim($item->nama));
            });

            // Cache project
            $this->cache['project'] = Project::all()->keyBy(function($item) {
                return strtolower(trim($item->nama));
            });

            // Cache existing NIKs untuk validasi duplikasi
            $this->cache['existing_niks'] = Karyawan::pluck('id', 'nik')->toArray();

            // Log::info('Master data cached', [
            //     'divisi_count' => $this->cache['divisi']->count(),
            //     'jabatan_count' => $this->cache['jabatan']->count(),
            //     'project_count' => $this->cache['project']->count(),
            //     'existing_niks' => count($this->cache['existing_niks'])
            // ]);

        } catch (\Exception $e) {
            // Log::error('Failed to preload master data: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Main collection handler
     */
    public function collection(Collection $rows)
    {
        try {
            // Filter valid rows (skip header rows 0-2, start from row 3)
            $validRows = $rows->skip(3)->filter(function($row) {
                // Check if NIK exists (column B)
                return !empty($this->extractCellValue($row, 1)); // Index 1 = Column B
            });

            $this->totalRows = $validRows->count();

            if ($this->totalRows === 0) {
                throw new \Exception("Tidak ada data valid untuk diimport. Pastikan data dimulai dari baris 4.");
            }

            $this->updateProgress(0, 'Memvalidasi data...');

            // Phase 1: Validate and prepare data
            $preparedData = $this->validateAndPrepareData($validRows);

            if (!empty($this->errors)) {
                $this->updateProgress(100, 'Validasi gagal', $this->errors);
                throw new \Exception("Validasi gagal:\n" . implode("\n", array_slice($this->errors, 0, 10)));
            }

            $this->updateProgress(30, 'Membuat master data...');

            // Phase 2: Create missing master data (divisi, jabatan, project)
            $this->createMissingMasterData($preparedData);

            $this->updateProgress(50, 'Menyimpan data karyawan...');

            // Phase 3: Bulk insert karyawan
            $this->bulkInsertKaryawan($preparedData);

            $this->updateProgress(80, 'Assign karyawan ke project...');

            // Phase 4: Bulk insert karyawan_projects
            $this->bulkInsertKaryawanProjects($preparedData);

            $this->updateProgress(100, 'Import selesai', [
                'success' => $this->processedCount,
                'total' => $this->totalRows
            ]);

            // Log::info('Import completed successfully', [
            //     'processed' => $this->processedCount,
            //     'total' => $this->totalRows
            // ]);

        } catch (\Exception $e) {
            // Log::error('Import failed: ' . $e->getMessage(), [
            //     'trace' => $e->getTraceAsString()
            // ]);
            
            $this->updateProgress(100, 'Import gagal', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
 * Validate and prepare all data
 */
private function validateAndPrepareData(Collection $rows)
{
    $preparedData = [];
    $processedNiks = [];
    $missingDivisi = [];
    $missingJabatan = [];
    $missingProject = [];
    
    // ✅ ADD: Track skipped rows
    $skippedDuplicate = 0;
    $skippedError = 0;

    foreach ($rows as $index => $row) {
        $rowNumber = $index + 4;

        try {
            $data = $this->extractRowData($row, $rowNumber);

            // ✅ ADD: Log NIK being processed
            // Log::info("Processing row {$rowNumber}", [
            //     'nik' => $data['nik'],
            //     'nama' => $data['nama']
            // ]);

            // Validate NIK uniqueness in database
            if (isset($this->cache['existing_niks'][$data['nik']])) {
                // Log::warning("NIK already exists in database", [
                //     'row' => $rowNumber,
                //     'nik' => $data['nik']
                // ]);
                $skippedDuplicate++;
                continue;
            }

            // Validate NIK uniqueness in current batch
            if (isset($processedNiks[$data['nik']])) {
                // Log::warning("NIK duplicate in current batch", [
                //     'row' => $rowNumber,
                //     'nik' => $data['nik']
                // ]);
                $skippedDuplicate++;
                continue;
            }

            // Track missing master data (divisi is optional now)
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
            // Log::error("Row validation failed", [
            //     'row' => $rowNumber,
            //     'error' => $e->getMessage()
            // ]);
            $skippedError++;
            continue;
        }
    }

    // ✅ ADD: Log summary
    // Log::info('Validation summary', [
    //     'total_rows' => count($rows),
    //     'prepared' => count($preparedData),
    //     'skipped_duplicate' => $skippedDuplicate,
    //     'skipped_error' => $skippedError,
    //     'missing_divisi' => count($missingDivisi),
    //     'missing_jabatan' => count($missingJabatan),
    //     'missing_project' => count($missingProject)
    // ]);

    // Store missing master data for creation
    $preparedData['_missing_divisi'] = array_values($missingDivisi);
    $preparedData['_missing_jabatan'] = array_values($missingJabatan);
    $preparedData['_missing_project'] = array_values($missingProject);
    $preparedData['_skipped_duplicate'] = $skippedDuplicate;
    $preparedData['_skipped_error'] = $skippedError;

    return $preparedData;
}

    /**
     * Extract data from single row
     */
    private function extractRowData($row, $rowNumber)
    {
        // Extract values from specific columns
        $nik = $this->extractNIK($this->extractCellValue($row, 23)); // Column B
        $nama = trim($this->extractCellValue($row, 8)); // Column C
        $status = trim($this->extractCellValue($row, 11)); // Column J
        $tanggalKeluar = $this->extractCellValue($row, 12); // Column K
        $tanggalBergabung = $this->extractCellValue($row, 3); // Column L
        $jenisKelamin = strtoupper(trim($this->extractCellValue($row, 14))); // Column M
        $jabatanNama = trim($this->extractCellValue($row, 15)); // Column N
        $divisiNama = trim($this->extractCellValue($row, 16)); // Column O - ✅ Can be empty now
        $projectNama = trim($this->extractCellValue($row, 18)); // Column Q
        $tempatLahir = trim($this->extractCellValue($row, 19)); // Column R
        $tanggalLahir = $this->extractCellValue($row, 20); // Column S
        $noTelepon = trim($this->extractCellValue($row, 37)); // Column AJ

        // Validate required fields
        if (empty($nik)) {
            throw new \Exception("NIK wajib diisi");
        }

        // if (!preg_match('/^\d{1}$/', $nik)) {
        //     throw new \Exception("NIK harus 16 digit angka. NIK: '{$nik}'");
        // }

        if (empty($nama)) {
            throw new \Exception("Nama wajib diisi");
        }

        if (empty($noTelepon)) {
            throw new \Exception("Nomor telepon wajib diisi");
        }

        // ✅ CHANGED: Divisi is now optional
        // if (empty($divisiNama)) {
        //     throw new \Exception("Divisi/Penempatan wajib diisi");
        // }

        if (empty($jabatanNama)) {
            throw new \Exception("Jabatan wajib diisi");
        }

        if (empty($jenisKelamin) || !in_array($jenisKelamin, ['L', 'P'])) {
            throw new \Exception("Jenis kelamin harus L atau P");
        }

        if (empty($tempatLahir)) {
            throw new \Exception("Tempat lahir wajib diisi");
        }
        
        $tanggalLahirParsed = $this->parseDate($tanggalLahir);
        if (!$tanggalLahirParsed) {
            throw new \Exception("Format tanggal lahir tidak valid: '{$tanggalLahir}'");
        }

        $tanggalBergabungParsed = $this->parseDate($tanggalBergabung);
        if (!$tanggalBergabungParsed) {
            throw new \Exception("Format tanggal bergabung tidak valid: '{$tanggalBergabung}'");
        }

        // Parse status
        $statusParsed = strtolower($status) === 'aktif' ? 'aktif' : 'tidak_aktif';
        
        $tanggalKeluarParsed = null;
        if ($statusParsed === 'tidak_aktif' && !empty($tanggalKeluar)) {
            $tanggalKeluarParsed = $this->parseDate($tanggalKeluar);
        }

        // ✅ Find or prepare master data references (divisi is optional)
        $divisiKey = !empty($divisiNama) ? strtolower(trim($divisiNama)) : null;
        $jabatanKey = strtolower(trim($jabatanNama));
        $projectKey = !empty($projectNama) ? strtolower(trim($projectNama)) : null;

        $divisiFound = $divisiKey ? $this->cache['divisi']->has($divisiKey) : true; // ✅ true if empty
        $jabatanFound = $this->cache['jabatan']->has($jabatanKey);
        $projectFound = $projectKey ? $this->cache['project']->has($projectKey) : true;

        // Generate username
        $username = $nik;
        $usernameCounter = 1;
        while (Karyawan::where('username', $username)->exists()) {
            $username = $nik . $usernameCounter;
            $usernameCounter++;
        }

        return [
            'nik' => $nik,
            'nama' => $nama,
            'no_telepon' => $noTelepon,
            'divisi_nama' => $divisiNama, // ✅ Can be empty
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

    /**
     * Create missing master data in bulk
     */
    private function createMissingMasterData(&$preparedData)
    {
        DB::beginTransaction();
        
        try {
            // ✅ Create missing Divisi (if any)
            if (!empty($preparedData['_missing_divisi'])) {
                $divisiData = array_map(function($nama) {
                    return [
                        'nama' => $nama,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }, $preparedData['_missing_divisi']);

                DB::table('divisis')->insert($divisiData);
                
                // Reload cache
                $this->cache['divisi'] = Divisi::all()->keyBy(function($item) {
                    return strtolower(trim($item->nama));
                });

                // Log::info('Created missing divisi', ['count' => count($divisiData)]);
            }

            // Create missing Jabatan
            if (!empty($preparedData['_missing_jabatan'])) {
                $jabatanData = array_map(function($nama) {
                    return [
                        'nama' => $nama,
                        'created_at' => now(),
                        'updated_at' => now()
                    ];
                }, $preparedData['_missing_jabatan']);

                DB::table('jabatans')->insert($jabatanData);
                
                // Reload cache
                $this->cache['jabatan'] = Jabatan::all()->keyBy(function($item) {
                    return strtolower(trim($item->nama));
                });

                // Log::info('Created missing jabatan', ['count' => count($jabatanData)]);
            }

            // Note: Projects should exist before import
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

    /**
 * Bulk insert karyawan with progress updates
 */
private function bulkInsertKaryawan(&$preparedData)
{
    $karyawanData = [];
    $totalRows = count(array_filter(array_keys($preparedData), function($key) {
        return strpos($key, '_') !== 0;
    }));

    // ✅ ADD: Check if there's data to insert
    if ($totalRows === 0) {
        // Log::warning('No data to insert - all rows were skipped');
        return;
    }

    // Log::info("Preparing to insert {$totalRows} karyawan");

    foreach ($preparedData as $key => $data) {
        if (strpos($key, '_') === 0) continue;

        // Get divisi ID (can be null now)
        $divisiId = null;
        if (!empty($data['divisi_nama'])) {
            $divisiKey = strtolower(trim($data['divisi_nama']));
            $divisi = $this->cache['divisi']->get($divisiKey);
            $divisiId = $divisi ? $divisi->id : null;
        }

        $jabatanKey = strtolower(trim($data['jabatan_nama']));
        $jabatan = $this->cache['jabatan']->get($jabatanKey);

        if (!$jabatan) {
            // Log::error('Missing jabatan during insert', [
            //     'row' => $data['row_number'],
            //     'jabatan' => $data['jabatan_nama']
            // ]);
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

    // ✅ ADD: Verify prepared data
    // Log::info("Prepared {$totalRows} rows, ready to insert: " . count($karyawanData));

    if (empty($karyawanData)) {
        // Log::error('No valid karyawan data prepared for insert');
        return;
    }

    // ✅ ADD: Try-catch for bulk insert
    try {
        $chunks = array_chunk($karyawanData, 100);
        $totalChunks = count($chunks);
        
        // Log::info("Inserting in {$totalChunks} chunks");
        
        foreach ($chunks as $index => $chunk) {
            try {
                $insertedCount = DB::table('karyawans')->insert($chunk);
                $this->processedCount += count($chunk);
                
                // Log::info("Chunk {$index} inserted", [
                //     'chunk_size' => count($chunk),
                //     'total_processed' => $this->processedCount
                // ]);
                
                // Update progress after each chunk
                $percent = 50 + (($index + 1) / $totalChunks) * 20; // 50-70%
                $this->updateProgress(
                    $percent, 
                    'Menyimpan karyawan... ' . $this->processedCount . '/' . $totalRows,
                    [
                        'processed' => $this->processedCount,
                        'total' => $totalRows
                    ]
                );
            } catch (\Exception $chunkError) {
                // Log::error("Chunk insert failed", [
                //     'chunk_index' => $index,
                //     'chunk_size' => count($chunk),
                //     'error' => $chunkError->getMessage(),
                //     'first_nik' => $chunk[0]['nik'] ?? 'unknown'
                // ]);
                throw $chunkError;
            }
        }

        // Log::info('Bulk insert completed', [
        //     'total_inserted' => $this->processedCount
        // ]);

    } catch (\Exception $e) {
        // Log::error('Bulk insert karyawan failed', [
        //     'error' => $e->getMessage(),
        //     'trace' => $e->getTraceAsString()
        // ]);
        throw $e;
    }
}

    /**
     * Bulk insert karyawan_projects
     */
    private function bulkInsertKaryawanProjects(&$preparedData)
    {
        // Reload karyawan cache with IDs
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
                // Log::warning('Project not found for assignment', [
                //     'row' => $data['row_number'],
                //     'project' => $data['project_nama']
                // ]);
                continue;
            }

            $karyawanId = $karyawanByNik[$data['nik']] ?? null;

            if (!$karyawanId) {
                // Log::warning('Karyawan not found for project assignment', [
                //     'row' => $data['row_number'],
                //     'nik' => $data['nik']
                // ]);
                continue;
            }

            // Check if cuti tahunan enabled for this project
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

            // Update sisa_cuti_tahunan based on project
            DB::table('karyawans')
                ->where('id', $karyawanId)
                ->update(['sisa_cuti_tahunan' => $sisaCutiTahunan]);
        }

        if (!empty($karyawanProjectData)) {
            // Insert in chunks
            foreach (array_chunk($karyawanProjectData, 100) as $chunk) {
                DB::table('karyawan_projects')->insert($chunk);
            }

            // Log::info('Bulk inserted karyawan_projects', ['count' => count($karyawanProjectData)]);
        }
    }

    /**
     * Helper: Extract cell value
     */
    private function extractCellValue($row, $index)
    {
        return $row[$index] ?? '';
    }

    /**
     * Helper: Extract and clean NIK
     */
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

    /**
     * Helper: Parse date
     */
    private function parseDate($dateValue)
    {
        if (empty($dateValue)) {
            return null;
        }

        try {
            // 1. Handle Excel serial number (numeric)
            if (is_numeric($dateValue)) {
                try {
                    $dateTime = \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject($dateValue);
                    $carbonDate = Carbon::instance($dateTime);
                    
                    if ($carbonDate->year >= 1900 && $carbonDate->year <= 2100) {
                        return $carbonDate;
                    }
                } catch (\Exception $e) {
                    Log::warning("Failed PhpSpreadsheet parse for {$dateValue}");
                }
            }

            $dateValue = trim($dateValue);

            // 2. Handle standard ISO format
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                return Carbon::createFromFormat('Y-m-d', $dateValue);
            }

            // 3. Handle Indonesian format
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

            // 4. Try common formats
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
            Log::error('Failed to parse date', ['value' => $dateValue, 'error' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Update progress in cache
     */
    private function updateProgress($percent, $message, $data = [])
    {
        Cache::put("import_progress_{$this->importId}", [
            'percent' => $percent,
            'message' => $message,
            'data' => $data,
            'updated_at' => now()->toIso8601String()
        ], 300);
    }

    /**
     * Get import ID
     */
    public function getImportId()
    {
        return $this->importId;
    }

    /**
     * Register events
     */
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

    /**
     * Validate master data exists before import
     */
    public static function validateMasterData(Collection $rows)
    {
        $validRows = $rows->skip(3)->filter(function($row) {
            return !empty($row[1]); // Column B (NIK)
        });

        if ($validRows->count() === 0) {
            return [
                'valid' => false,
                'message' => 'Tidak ada data valid dalam file'
            ];
        }

        // Extract unique divisi, jabatan, project
        $divisiNames = [];
        $jabatanNames = [];
        $projectNames = [];

        foreach ($validRows as $row) {
            $divisi = trim($row[16] ?? '');
            $jabatan = trim($row[15] ?? '');
            $project = trim($row[18] ?? '');

            // ✅ Only add non-empty divisi
            if (!empty($divisi)) $divisiNames[] = $divisi;
            if (!empty($jabatan)) $jabatanNames[] = $jabatan;
            if (!empty($project)) $projectNames[] = $project;
        }

        $divisiNames = array_unique($divisiNames);
        $jabatanNames = array_unique($jabatanNames);
        $projectNames = array_unique($projectNames);

        // Check existing master data
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
                    'is_optional' => true // ✅ NEW: Indicate divisi is optional
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
}