<?php

namespace App\Exports;

use App\Models\Karyawan;
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
use Carbon\Carbon;

class KaryawanExport implements FromCollection, WithHeadings, WithMapping, WithColumnWidths, WithStyles
{
    public function collection()
    {
        return Karyawan::with(['divisi', 'jabatan'])
                      ->orderBy('id', 'asc')
                      ->get();
    }

    public function headings(): array
{
    return [
        'ID Karyawan',
        'NIK',
        'Nama Lengkap',
        'No Telepon',
        'Penempatan',
        'Jabatan',
        'Jenis Kelamin',
        'Tempat Lahir',
        'Tanggal Lahir',
        'Tanggal Bergabung',
        'Tanggal Keluar',
        'Username',
        'Status',
        'Tanggal Dibuat',
        'Terakhir Diupdate'
    ];
}

public function map($karyawan): array
{
    return [
        $karyawan->id,
        $karyawan->nik,
        $karyawan->nama,
        $karyawan->no_telepon ?: '-',
        $karyawan->divisi ? $karyawan->divisi->nama : '-',
        $karyawan->jabatan ? $karyawan->jabatan->nama : '-',
        $karyawan->jenis_kelamin === 'L' ? 'Laki-laki' : 'Perempuan',
        $karyawan->tempat_lahir,
        $this->formatIndonesianDate($karyawan->tanggal_lahir),
        $this->formatIndonesianDate($karyawan->tanggal_bergabung),
        $karyawan->tanggal_keluar ? $this->formatIndonesianDate($karyawan->tanggal_keluar) : '-',
        $karyawan->username,
        $karyawan->status === 'aktif' ? 'Aktif' : 'Tidak Aktif',
        $this->formatIndonesianDateTime($karyawan->created_at),
        $this->formatIndonesianDateTime($karyawan->updated_at),
    ];
}

public function columnWidths(): array
{
    return [
        'A' => 12,  // ID Karyawan
        'B' => 20,  // NIK
        'C' => 25,  // Nama Lengkap
        'D' => 18,  // No Telepon - TAMBAHAN BARU
        'E' => 20,  // Penempatan
        'F' => 20,  // Jabatan
        'G' => 15,  // Jenis Kelamin
        'H' => 20,  // Tempat Lahir
        'I' => 18,  // Tanggal Lahir
        'J' => 18,  // Tanggal Bergabung
        'K' => 18,  // Tanggal Keluar
        'L' => 20,  // Username
        'M' => 12,  // Status
        'N' => 20,  // Tanggal Dibuat
        'O' => 20,  // Terakhir Diupdate
    ];
}

    public function styles(Worksheet $sheet)
    {
        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();
        
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

            // Center align untuk kolom tertentu
            $sheet->getStyle('A2:A' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // ID
            $sheet->getStyle('B2:B' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // NIK
            $sheet->getStyle('F2:F' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Jenis Kelamin
            $sheet->getStyle('I2:I' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Usia
            $sheet->getStyle('N2:N' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Status
            
            // Center align untuk kolom tanggal
            $sheet->getStyle('H2:K' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Tanggal
            $sheet->getStyle('O2:P' . $highestRow)->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER); // Created/Updated
        }

        // Auto-fit row heights
        for ($row = 1; $row <= $highestRow; $row++) {
            $sheet->getRowDimension($row)->setRowHeight(-1);
        }

        // Freeze first row
        $sheet->freezePane('A2');

        return [];
    }

    /**
     * Format date to Indonesian format (dd Bulan yyyy)
     */
    private function formatIndonesianDate($date)
    {
        if (!$date) return '-';
        
        try {
            $carbonDate = Carbon::parse($date);
            
            $months = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            
            $day = $carbonDate->day;
            $month = $months[$carbonDate->month];
            $year = $carbonDate->year;
            
            return "{$day} {$month} {$year}";
        } catch (\Exception $e) {
            return '-';
        }
    }

    /**
     * Format datetime to Indonesian format (dd Bulan yyyy HH:mm)
     */
    private function formatIndonesianDateTime($datetime)
    {
        if (!$datetime) return '-';
        
        try {
            $carbonDate = Carbon::parse($datetime);
            
            $months = [
                1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
                5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
                9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember'
            ];
            
            $day = $carbonDate->day;
            $month = $months[$carbonDate->month];
            $year = $carbonDate->year;
            $hour = $carbonDate->format('H:i');
            
            return "{$day} {$month} {$year} {$hour}";
        } catch (\Exception $e) {
            return '-';
        }
    }

    /**
     * Calculate age from birth date
     */
    private function calculateAge($birthDate)
    {
        if (!$birthDate) return '-';
        
        try {
            $birth = Carbon::parse($birthDate);
            $now = Carbon::now();
            return $birth->diffInYears($now) . ' tahun';
        } catch (\Exception $e) {
            return '-';
        }
    }

    /**
     * Calculate work duration
     */
    private function calculateWorkDuration($startDate, $endDate = null)
    {
        if (!$startDate) return '-';
        
        try {
            $start = Carbon::parse($startDate);
            $end = $endDate ? Carbon::parse($endDate) : Carbon::now();
            
            $diffInDays = $start->diffInDays($end);
            
            if ($diffInDays < 30) {
                return $diffInDays . ' hari';
            } elseif ($diffInDays < 365) {
                $months = floor($diffInDays / 30);
                $days = $diffInDays % 30;
                return $months . ' bulan' . ($days > 0 ? ' ' . $days . ' hari' : '');
            } else {
                $years = floor($diffInDays / 365);
                $remainingDays = $diffInDays % 365;
                $months = floor($remainingDays / 30);
                $days = $remainingDays % 30;
                
                $result = $years . ' tahun';
                if ($months > 0) $result .= ' ' . $months . ' bulan';
                if ($days > 0) $result .= ' ' . $days . ' hari';
                
                return $result;
            }
        } catch (\Exception $e) {
            return '-';
        }
    }
}