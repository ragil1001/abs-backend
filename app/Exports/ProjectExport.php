<?php

namespace App\Exports;

use App\Models\Project;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Font;
use PhpOffice\PhpSpreadsheet\Style\Border;

class ProjectExport implements FromCollection, WithHeadings, WithMapping, WithColumnWidths, WithStyles
{
    public function collection()
    {
        return Project::with('shiftProjects')->orderBy('id', 'asc')->get();
    }

    public function headings(): array
    {
        return [
            'ID Project',
            'Nama Project', 
            'Tanggal Mulai',
            'Bagian/Jabatan',
            'Nama Lokasi',
            'Koordinat (Lat,Lng)',
            'Latitude',
            'Longitude',
            'Radius Absensi (m)',
            'Waktu Toleransi (menit)',
            'Status',
            'Shifts',
            'Tanggal Dibuat',
            'Terakhir Diupdate'
        ];
    }

    // ProjectExport.php - Method map()
public function map($project): array
{
    // Format shifts dengan kode
    $shifts = $project->shiftProjects->map(function ($shift) {
        return $shift->kode . ' (' . // UBAH DARI nama
               $shift->waktu_mulai . '-' . 
               $shift->waktu_selesai . ')';
    })->implode(', ');

    $projectId = 'PRJ' . str_pad($project->id, 3, '0', STR_PAD_LEFT);

    return [
        $projectId,
        $project->nama,
        $project->tanggal_mulai ? $project->tanggal_mulai->format('d/m/Y') : '-',
        $project->bagian,
        $project->lokasi_nama,
        $project->lokasi_latitude . ',' . $project->lokasi_longitude,
        $project->lokasi_latitude,
        $project->lokasi_longitude,
        $project->radius ? $project->radius . 'm' : '-',
        $project->waktu_toleransi ? $project->waktu_toleransi . ' menit' : '-',
        ucfirst($project->status),
        $shifts ?: 'Tidak ada shift',
        $project->created_at ? $project->created_at->format('d/m/Y H:i:s') : '-',
        $project->updated_at ? $project->updated_at->format('d/m/Y H:i:s') : '-',
    ];
}

    public function columnWidths(): array
    {
        return [
            'A' => 15,  // ID Project
            'B' => 25,  // Nama Project
            'C' => 15,  // Tanggal Mulai
            'D' => 20,  // Bagian/Jabatan
            'E' => 25,  // Nama Lokasi
            'F' => 20,  // Koordinat
            'G' => 12,  // Latitude
            'H' => 12,  // Longitude
            'I' => 18,  // Radius Absensi
            'J' => 20,  // Waktu Toleransi
            'K' => 12,  // Status
            'L' => 40,  // Shifts
            'M' => 20,  // Tanggal Dibuat
            'N' => 20,  // Terakhir Diupdate
        ];
    }

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = 'N'; // Last column
        
        // Style untuk header
        $sheet->getStyle('A1:' . $highestColumn . '1')->applyFromArray([
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF'],
                'size' => 12,
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => 'EA580C'], // Orange color
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER,
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000'],
                ],
            ],
        ]);

        // Style untuk data rows
        if ($highestRow > 1) {
            $sheet->getStyle('A2:' . $highestColumn . $highestRow)->applyFromArray([
                'alignment' => [
                    'horizontal' => Alignment::HORIZONTAL_LEFT,
                    'vertical' => Alignment::VERTICAL_CENTER,
                ],
                'borders' => [
                    'allBorders' => [
                        'borderStyle' => Border::BORDER_THIN,
                        'color' => ['rgb' => 'CCCCCC'],
                    ],
                ],
            ]);

            // Center align untuk kolom ID, Tanggal, Status, dan numerik
            $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // ID
            $sheet->getStyle('C2:C' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Tanggal
            $sheet->getStyle('F2:I' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Koordinat dan radius
            $sheet->getStyle('K2:K' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Status
            $sheet->getStyle('M2:N' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Tanggal created/updated
        }

        // Auto-fit row heights
        for ($row = 1; $row <= $highestRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(-1);
        }

        return [];
    }
}