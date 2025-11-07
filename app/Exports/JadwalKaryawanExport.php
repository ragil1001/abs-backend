<?php

namespace App\Exports;

use App\Models\JadwalKaryawan;
use App\Models\Project;
use App\Models\KaryawanProject;
use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;

class JadwalKaryawanExport implements WithMultipleSheets
{
    protected $projectId;
    protected $startDate;
    protected $endDate;

    public function __construct($projectId, $startDate, $endDate)
    {
        $this->projectId = $projectId;
        $this->startDate = Carbon::parse($startDate);
        $this->endDate = Carbon::parse($endDate);
    }

    public function sheets(): array
    {
        return [
            new JadwalSheet($this->projectId, $this->startDate, $this->endDate),
            new KeteranganSheet($this->projectId),
        ];
    }
}

// Sheet 1: Jadwal
class JadwalSheet implements FromCollection, WithHeadings, WithMapping, WithColumnWidths, WithStyles, WithTitle
{
    protected $projectId;
    protected $startDate;
    protected $endDate;
    protected $dates = [];

    public function __construct($projectId, $startDate, $endDate)
    {
        $this->projectId = $projectId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        
        // Generate date array
        $current = $this->startDate->copy();
        while ($current <= $this->endDate) {
            $this->dates[] = $current->copy();
            $current->addDay();
        }
    }

    public function collection()
    {
        // Get all karyawan with their jadwal
        $jadwals = JadwalKaryawan::with([
            'karyawanProject.karyawan.divisi',
            'karyawanProject.karyawan.jabatan'
        ])
        ->byProject($this->projectId)
        ->betweenDates($this->startDate, $this->endDate)
        ->get()
        ->groupBy('karyawan_project_id');

        $result = collect();
        $no = 1;

        foreach ($jadwals as $karyawanProjectId => $items) {
            $karyawanProject = $items->first()->karyawanProject;
            $karyawan = $karyawanProject->karyawan;
            
            // Build row data
            $rowData = [
                'no' => $no++,
                'nik' => $karyawan->nik,
                'nama' => $karyawan->nama,
                'divisi' => $karyawan->divisi->nama ?? '-',
            ];

            // Add shift codes for each date
            foreach ($this->dates as $date) {
                $jadwal = $items->firstWhere('tanggal', $date->format('Y-m-d'));
                $rowData['shift_' . $date->format('Ymd')] = $jadwal ? $jadwal->shift_code : '-';
            }

            $result->push($rowData);
        }

        return $result;
    }

    public function headings(): array
    {
        $headings = ['NO', 'NIK', 'NAMA', 'PENEMPATAN'];
        
        foreach ($this->dates as $date) {
            $headings[] = $date->format('d/m/Y') . "\n" . $date->locale('id')->isoFormat('ddd');
        }
        
        return $headings;
    }

    public function map($row): array
    {
        $mapped = [
            $row['no'],
            $row['nik'],
            $row['nama'],
            $row['divisi'],
        ];

        foreach ($this->dates as $date) {
            $mapped[] = $row['shift_' . $date->format('Ymd')] ?? '-';
        }

        return $mapped;
    }

    public function columnWidths(): array
    {
        $widths = [
            'A' => 8,   // NO
            'B' => 18,  // NIK
            'C' => 30,  // NAMA
            'D' => 20,  // PENEMPATAN
        ];

        // Date columns
        $column = 'E';
        foreach ($this->dates as $date) {
            $widths[$column] = 12;
            $column++;
        }

        return $widths;
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        
        // Header style
        $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 11,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EA580C'], // Orange
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
                'wrapText' => true,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // Set row height for header
        $sheet->getRowDimension(1)->setRowHeight(40);

        // Data rows style
        if ($highestRow > 1) {
            $sheet->getStyle('A2:' . $highestColumn . $highestRow)->applyFromArray([
                'alignment' => [
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
            ]);

            // Center align for NO and NIK
            $sheet->getStyle('A2:B' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Center align date columns
            $dateStartCol = 'E';
            $sheet->getStyle($dateStartCol . '2:' . $highestColumn . $highestRow)
                  ->getAlignment()
                  ->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // Highlight weekend columns
            $column = 'E';
            foreach ($this->dates as $date) {
                if ($date->isWeekend()) {
                    $sheet->getStyle($column . '1:' . $column . $highestRow)->applyFromArray([
                        'fill' => [
                            'fillType' => Fill::FILL_SOLID,
                            'startColor' => ['rgb' => 'FEE2E2'], // Light red
                        ],
                    ]);
                }
                $column++;
            }
        }

        return [];
    }

    public function title(): string
    {
        return 'Jadwal Karyawan';
    }
}

// Sheet 2: Keterangan Shift
class KeteranganSheet implements FromCollection, WithHeadings, WithMapping, WithColumnWidths, WithStyles, WithTitle
{
    protected $projectId;

    public function __construct($projectId)
    {
        $this->projectId = $projectId;
    }

    public function collection()
    {
        $project = Project::with('shiftProjects')->find($this->projectId);
        
        $data = collect([
            ['Informasi', 'Detail'],
            ['Nama Project', $project->nama],
            ['Lokasi', $project->lokasi_nama],
            ['Status', $project->status === 'aktif' ? 'Aktif' : 'Tidak Aktif'],
            ['', ''],
            ['Kode Shift', 'Nama Shift', 'Jam Kerja'],
        ]);

        // Add shift information
        foreach ($project->shiftProjects as $shift) {
            $data->push([
                $shift->kode,
                $shift->kode,
                "{$shift->waktu_mulai} - {$shift->waktu_selesai}"
            ]);
        }

        // Add Libur
        $data->push(['L', 'Libur', '-']);

        return $data;
    }

    public function headings(): array
    {
        return [];
    }

    public function map($row): array
    {
        return $row;
    }

    public function columnWidths(): array
    {
        return [
            'A' => 25,
            'B' => 25,
            'C' => 25,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Title row
        $sheet->getStyle('A1:B1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EA580C'],
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ]);

        // Project info rows (bold first column)
        $sheet->getStyle('A2:A4')->applyFromArray([
            'font' => ['bold' => true],
        ]);

        // Shift header
        $sheet->getStyle('A6:C6')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3F4F6'],
            ],
        ]);

        return [];
    }

    public function title(): string
    {
        return 'Keterangan';
    }
}