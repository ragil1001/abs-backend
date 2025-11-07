<?php
// app/Exports/KaryawanProjectExport.php

namespace App\Exports;

use App\Models\KaryawanProject;
use App\Models\Project;
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

class KaryawanProjectExport implements WithMultipleSheets
{
    protected $projectId;

    public function __construct($projectId)
    {
        $this->projectId = $projectId;
    }

    public function sheets(): array
    {
        return [
            new KaryawanAktifSheet($this->projectId),
            new KaryawanTidakAktifSheet($this->projectId),
            new ProjectInfoSheet($this->projectId),
        ];
    }
}

// Sheet 1: Karyawan Aktif
class KaryawanAktifSheet implements FromCollection, WithHeadings, WithMapping, WithColumnWidths, WithStyles, WithTitle
{
    protected $projectId;

    public function __construct($projectId)
    {
        $this->projectId = $projectId;
    }

    public function collection()
    {
        return KaryawanProject::with(['karyawan.divisi', 'karyawan.jabatan'])
            ->where('project_id', $this->projectId)
            ->where('status', 'aktif')
            ->orderBy('tanggal_assign', 'desc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'NIK',
            'Nama Lengkap',
            'Penempatan',
            'Jabatan',
            'Tanggal Assign',
            'Durasi Kerja',
            'Status',
            'Keterangan'
        ];
    }

    public function map($assignment): array
    {
        return [
            $assignment->karyawan->nik,
            $assignment->karyawan->nama,
            $assignment->karyawan->divisi->nama ?? '-',
            $assignment->karyawan->jabatan->nama ?? '-',
            $assignment->tanggal_assign ? $assignment->tanggal_assign->format('d/m/Y') : '-',
            $assignment->durasi_kerja_text,
            'Aktif',
            $assignment->keterangan ?? '-'
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 18,  // NIK
            'B' => 30,  // Nama
            'C' => 20,  // Penempatan
            'D' => 25,  // Jabatan
            'E' => 15,  // Tanggal Assign
            'F' => 20,  // Durasi Kerja
            'G' => 12,  // Status
            'H' => 30,  // Keterangan
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        
        // Header style
        $sheet->getStyle('A1:H1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '059669'], // Green for active
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // Data rows style
        if ($highestRow > 1) {
            $sheet->getStyle('A2:H' . $highestRow)->applyFromArray([
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

            // Center align for specific columns
            $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E2:E' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('G2:G' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        return [];
    }

    public function title(): string
    {
        return 'Karyawan Aktif';
    }
}

// Sheet 2: Karyawan Tidak Aktif
class KaryawanTidakAktifSheet implements FromCollection, WithHeadings, WithMapping, WithColumnWidths, WithStyles, WithTitle
{
    protected $projectId;

    public function __construct($projectId)
    {
        $this->projectId = $projectId;
    }

    public function collection()
    {
        return KaryawanProject::with(['karyawan.divisi', 'karyawan.jabatan'])
            ->where('project_id', $this->projectId)
            ->where('status', 'tidak_aktif')
            ->orderBy('tanggal_selesai', 'desc')
            ->get();
    }

    public function headings(): array
    {
        return [
            'NIK',
            'Nama Lengkap',
            'Penempatan',
            'Jabatan',
            'Tanggal Assign',
            'Tanggal Selesai',
            'Durasi Kerja',
            'Status',
            'Keterangan'
        ];
    }

    public function map($assignment): array
    {
        return [
            $assignment->karyawan->nik,
            $assignment->karyawan->nama,
            $assignment->karyawan->divisi->nama ?? '-',
            $assignment->karyawan->jabatan->nama ?? '-',
            $assignment->tanggal_assign ? $assignment->tanggal_assign->format('d/m/Y') : '-',
            $assignment->tanggal_selesai ? $assignment->tanggal_selesai->format('d/m/Y') : '-',
            $assignment->durasi_kerja_text,
            'Tidak Aktif',
            $assignment->keterangan ?? '-'
        ];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 18,  // NIK
            'B' => 30,  // Nama
            'C' => 20,  // Divisi
            'D' => 25,  // Jabatan
            'E' => 15,  // Tanggal Assign
            'F' => 15,  // Tanggal Selesai
            'G' => 20,  // Durasi Kerja
            'H' => 12,  // Status
            'I' => 30,  // Keterangan
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        
        // Header style
        $sheet->getStyle('A1:I1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'DC2626'], // Red for inactive
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                ],
            ],
        ]);

        // Data rows style
        if ($highestRow > 1) {
            $sheet->getStyle('A2:I' . $highestRow)->applyFromArray([
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

            // Center align for specific columns
            $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('E2:F' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle('H2:H' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        }

        return [];
    }

    public function title(): string
    {
        return 'Karyawan Tidak Aktif';
    }
}

// Sheet 3: Project Info
class ProjectInfoSheet implements FromCollection, WithHeadings, WithMapping, WithColumnWidths, WithStyles, WithTitle
{
    protected $projectId;

    public function __construct($projectId)
    {
        $this->projectId = $projectId;
    }

    public function collection()
    {
        $project = Project::with('shiftProjects')->find($this->projectId);
        
        $activeCount = KaryawanProject::where('project_id', $this->projectId)
                                       ->where('status', 'aktif')
                                       ->count();
        
        $inactiveCount = KaryawanProject::where('project_id', $this->projectId)
                                         ->where('status', 'tidak_aktif')
                                         ->count();

        return collect([
            ['Informasi', 'Detail'],
            ['Nama Project', $project->nama],
            ['Bagian', $project->bagian],
            ['Lokasi', $project->lokasi_nama],
            ['Koordinat', "{$project->lokasi_latitude}, {$project->lokasi_longitude}"],
            ['Radius', "{$project->radius} meter"],
            ['Tanggal Mulai', $project->tanggal_mulai->format('d/m/Y')],
            ['Waktu Toleransi', $project->waktu_toleransi ? "{$project->waktu_toleransi} menit" : '-'],
            ['Status Project', $project->status === 'aktif' ? 'Aktif' : 'Tidak Aktif'],
            ['', ''],
            ['Total Karyawan Aktif', $activeCount],
            ['Total Karyawan Tidak Aktif', $inactiveCount],
            ['Total Keseluruhan', $activeCount + $inactiveCount],
            ['', ''],
            ['Shift', 'Waktu'],
        ]);
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
            'A' => 30,
            'B' => 40,
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $project = Project::with('shiftProjects')->find($this->projectId);
        
        // Title row
        $sheet->getStyle('A1:B1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EA580C'], // Orange
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
            ],
        ]);

        // Project info rows (A column bold)
        $sheet->getStyle('A2:A9')->applyFromArray([
            'font' => ['bold' => true],
        ]);

        // Statistics section
        $sheet->getStyle('A11:A13')->applyFromArray([
            'font' => ['bold' => true],
        ]);

        // Shift header
        $sheet->getStyle('A15:B15')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'F3F4F6'],
            ],
        ]);

        // Add shift data dynamically
        $row = 16;
        foreach ($project->shiftProjects as $shift) {
            $sheet->setCellValue('A' . $row, $shift->nama);
            $sheet->setCellValue('B' . $row, "{$shift->waktu_mulai} - {$shift->waktu_selesai}");
            $row++;
        }

        return [];
    }

    public function title(): string
    {
        return 'Info Project';
    }
}