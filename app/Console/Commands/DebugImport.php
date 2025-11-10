<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Maatwebsite\Excel\Facades\Excel;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;

class DebugImport extends Command
{
    protected $signature = 'import:debug {file}';
    protected $description = 'Debug Excel file structure';

    public function handle()
    {
        $file = $this->argument('file');
        
        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            return 1;
        }

        $this->info('📋 Analyzing Excel file structure...');
        $this->newLine();

        try {
            $debugImport = new class implements ToCollection {
                public $rows;
                
                public function collection(Collection $rows)
                {
                    $this->rows = $rows;
                }
            };

            Excel::import($debugImport, $file);
            $rows = $debugImport->rows;

            $this->info("Total rows in Excel: " . $rows->count());
            $this->newLine();

            
            $this->info('First 10 rows:');
            foreach ($rows->take(10) as $index => $row) {
                $this->line("Row {$index}: " . json_encode($row->toArray(), JSON_UNESCAPED_UNICODE));
            }
            
            $this->newLine();
            
            
            if ($rows->count() >= 4) {
                $this->info('=== HEADER ROW (Index 2) ===');
                $headerRow = $rows->get(2);
                if ($headerRow) {
                    foreach ($headerRow as $colIndex => $value) {
                        $this->line("Column {$colIndex}: [{$value}]");
                    }
                }
                
                $this->newLine();
                $this->info('=== FIRST DATA ROW (Index 3) ===');
                $dataRow = $rows->get(3);
                if ($dataRow) {
                    foreach ($dataRow as $colIndex => $value) {
                        $type = gettype($value);
                        $display = is_numeric($value) ? $value : "'{$value}'";
                        $this->line("Column {$colIndex}: {$display} (Type: {$type})");
                    }
                }
                
                $this->newLine();
                
                
                $this->info('=== NIK ANALYSIS (Column B/Index 1) ===');
                $nikSamples = [];
                foreach ($rows->skip(3)->take(5) as $index => $row) {
                    $nik = $row[23] ?? null;
                    $nikSamples[] = [
                        'Row' => $index + 4,
                        'Raw Value' => var_export($nik, true),
                        'Type' => gettype($nik),
                        'Empty?' => empty($nik) ? 'YES' : 'NO',
                        'Length' => is_string($nik) ? strlen($nik) : 'N/A'
                    ];
                }
                $this->table(
                    ['Row', 'Raw Value', 'Type', 'Empty?', 'Length'],
                    $nikSamples
                );
                
                $this->newLine();
                
                
                $validRows = $rows->skip(3)->filter(function($row) {
                    return !empty($row[1] ?? null);
                });
                
                $this->info("Valid rows (with NIK in column B): " . $validRows->count());
                
                if ($validRows->count() === 0) {
                    $this->error('❌ NO VALID ROWS FOUND!');
                    $this->warn('Possible issues:');
                    $this->line('1. NIK is not in column B (index 1)');
                    $this->line('2. Data starts from a different row');
                    $this->line('3. NIK column is empty or formatted differently');
                    
                    
                    $this->newLine();
                    $this->info('Searching for NIK in first data row...');
                    $firstDataRow = $rows->get(3);
                    if ($firstDataRow) {
                        foreach ($firstDataRow as $colIndex => $value) {
                            if (is_numeric($value) && strlen((string)$value) >= 10) {
                                $this->line("→ Possible NIK found at Column {$colIndex}: {$value}");
                            }
                        }
                    }
                }
            }

        } catch (\Exception $e) {
            $this->error('❌ Error: ' . $e->getMessage());
            $this->error($e->getTraceAsString());
            return 1;
        }

        return 0;
    }
}