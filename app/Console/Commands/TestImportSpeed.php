<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Imports\KaryawanImport;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Facades\DB;

class TestImportSpeed extends Command
{
    protected $signature = 'import:test {file}';
    protected $description = 'Test import speed with a file';

    public function handle()
    {
        $file = $this->argument('file');
        
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $this->info('🚀 Starting import speed test...');
        $this->info('File: ' . $file);
        $this->info('Size: ' . $this->formatBytes(filesize($file)));
        $this->newLine();

        // Clear existing data
        $this->warn('⚠️  Clearing existing test data...');
        DB::table('karyawan_projects')->truncate();
        DB::table('karyawans')->truncate();
        DB::table('jabatans')->truncate();
        DB::table('divisis')->truncate();
        
        $this->newLine();
        $this->info('✅ Ready to import');
        $this->newLine();

        // Measure import time
        $startTime = microtime(true);
        $startMemory = memory_get_usage(true);

        try {
            $import = new KaryawanImport(1);
            Excel::import($import, $file);
            
            $duration = round(microtime(true) - $startTime, 2);
            $memoryUsed = memory_get_usage(true) - $startMemory;
            $peakMemory = memory_get_peak_usage(true);

            $this->newLine();
            $this->info('✅ Import completed successfully!');
            $this->newLine();
            
            $this->table(
                ['Metric', 'Value'],
                [
                    ['Duration', "{$duration} seconds"],
                    ['Rows Processed', $import->getProcessedCount()],
                    ['Total Rows', $import->getTotalRows()],
                    ['Speed', round($import->getProcessedCount() / $duration, 2) . ' rows/sec'],
                    ['Memory Used', $this->formatBytes($memoryUsed)],
                    ['Peak Memory', $this->formatBytes($peakMemory)],
                ]
            );

            $this->newLine();
            
            if ($duration < 10) {
                $this->info('🎉 EXCELLENT! Import completed in under 10 seconds!');
            } else if ($duration < 30) {
                $this->info('✅ Good performance!');
            } else {
                $this->warn('⚠️  Slow import. Consider optimization.');
            }

        } catch (\Exception $e) {
            $this->error('❌ Import failed: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }

    private function formatBytes($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, 2) . ' ' . $units[$pow];
    }
}