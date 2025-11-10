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

    public $timeout = 600; 
    public $tries = 3;

    
    public function __construct($filePath, $userId)
    {
        $this->filePath = $filePath;
        $this->userId = $userId;
        $this->importId = uniqid('import_', true);
    }

    
    public function handle()
    {
        try {
            
            
            
            
            

            
            $this->updateStatus('processing', 'Memulai import data...');

            
            $import = new KaryawanImport($this->userId);
            Excel::import($import, Storage::path($this->filePath));

            
            $this->updateStatus('completed', 'Import berhasil diselesaikan', [
                'processed' => $import->getProcessedCount(),
                'total' => $import->getTotalRows()
            ]);

            $this->deleteImportFile();
            
            Storage::delete($this->filePath);

            
            
            

        } catch (\Exception $e) {
            $this->deleteImportFile();
            
            
            
            
            

            
            $this->updateStatus('failed', 'Import gagal: ' . $e->getMessage(), [
                'error' => $e->getMessage()
            ]);

            
            throw $e;
        }
    }

    private function deleteImportFile()
    {
        try {
            if (Storage::exists($this->filePath)) {
                Storage::delete($this->filePath);
                
                
                
                
            }
        } catch (\Exception $e) {
            throw $e;
        }
    }



    
    private function updateStatus($status, $message, $data = [])
    {
        Cache::put("import_status_{$this->importId}", [
            'status' => $status,
            'message' => $message,
            'data' => $data,
            'updated_at' => now()->toIso8601String()
        ], 3600); 
    }

    
    public function getImportId()
    {
        return $this->importId;
    }
}