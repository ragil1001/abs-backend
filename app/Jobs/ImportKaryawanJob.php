<?php

namespace App\Jobs;

use App\Imports\KaryawanImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Facades\Excel;

class ImportKaryawanJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $filePath;
    protected $userId;
    protected $importId;

    public $timeout = 600; // 10 minutes
    public $tries = 3;

    /**
     * Create a new job instance.
     */
    public function __construct($filePath, $userId)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
        $this->importId = uniqid('import_', true);
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        try {
            // Log::info('Starting import job', [
            //     'import_id' => $this->importId,
            //     'file_path' => $this->filePath,
            //     'user_id' => $this->userId
            // ]);

            // Update status to processing
            $this->updateStatus('processing', 'Memulai import data...');

            // Execute import
            $import = new KaryawanImport($this->userId);
            Excel::import($import, Storage::path($this->filePath));

            // Update status to completed
            $this->updateStatus('completed', 'Import berhasil diselesaikan', [
                'processed' => $import->getProcessedCount(),
                'total' => $import->getTotalRows()
            ]);

            $this->deleteImportFile();
            // Clean up file
            Storage::delete($this->filePath);

            // Log::info('Import job completed successfully', [
            //     'import_id' => $this->importId
            // ]);

        } catch (\Exception $e) {
            $this->deleteImportFile();
            // Log::error('Import job failed', [
            //     'import_id' => $this->importId,
            //     'error' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);

            // Update status to failed
            $this->updateStatus('failed', 'Import gagal: ' . $e->getMessage(), [
                'error' => $e->getMessage()
            ]);

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    private function deleteImportFile()
    {
        try {
            if (Storage::exists($this->filePath)) {
                Storage::delete($this->filePath);
                // Log::info('Import file deleted', [
                //     'import_id' => $this->importId,
                //     'file_path' => $this->filePath
                // ]);
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }



    /**
     * Update import status in cache
     */
    private function updateStatus($status, $message, $data = [])
    {
        Cache::put("import_status_{$this->importId}", [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'updated_at' => now()->toIso8601String()
        ], 3600); // 1 hour TTL
    }

    /**
     * Get import ID
     */
    public function getImportId()
    {
        return $this->importId;
    }
}