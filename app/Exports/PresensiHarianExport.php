<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\WithMultipleSheets;

class PresensiHarianExport implements WithMultipleSheets
{
    protected $data;
    protected $project;
    protected $statistik;
    protected $tanggal;

    public function __construct($data, $project, $statistik, $tanggal)
    {
        $this->data = $data;
        $this->project = $project;
        $this->statistik = $statistik;
        $this->tanggal = $tanggal;
    }

    public function sheets(): array
    {
        return [
            new PresensiMasukSheet($this->data, $this->project, $this->statistik, $this->tanggal),
            new PresensiPulangSheet($this->data, $this->project, $this->statistik, $this->tanggal),
        ];
    }
}

// Sheet Presensi Masuk
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Carbon\Carbon;

class PresensiMasukSheet implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    protected $data;
    protected $project;
    protected $statistik;
    protected $tanggal;

    public function __construct($data, $project, $statistik, $tanggal)
    {
        $this->data = $data;
        $this->project = $project;
        $this->statistik = $statistik;
        $this->tanggal = $tanggal;
    }

    public function collection()
    {
        $collection = collect();

        // Add header info
        $collection->push(['REKAP PRESENSI MASUK']);
        $collection->push([]);
        $collection->push([$this->project['nama']]);

        $tanggalFormatted = Carbon::parse($this->tanggal)->locale('id')->isoFormat('dddd, D MMMM YYYY');
        $collection->push([$tanggalFormatted . ' - PT. QIPRAH MULTI SERVICE']);
        $collection->push([]);

        // Project info & statistics
        $collection->push(['Nama Project', $this->project['nama']]);
        $collection->push(['Lokasi', $this->project['lokasi']['nama'] ?? '-']);
        $collection->push(['Total Karyawan', $this->statistik['total']]);
        $collection->push([]);

        // Statistics
        $collection->push(['STATISTIK PRESENSI MASUK']);
        $collection->push(['Status', 'Jumlah']);
        $collection->push(['Hadir', $this->statistik['masuk']['hadir']]);
        $collection->push(['Terlambat', $this->statistik['masuk']['terlambat']]);
        $collection->push(['Izin', $this->statistik['masuk']['izin']]);
        $collection->push(['Alpa', $this->statistik['masuk']['alpa']]);
        $collection->push(['Libur', $this->statistik['masuk']['libur']]);
        $collection->push([]);

        // Table headers
        $collection->push(['NIK', 'Nama', 'Penempatan', 'Jabatan', 'Shift', 'Waktu Masuk', 'Status', 'Keterangan', 'Lokasi', 'Koordinat']);

        // Data rows
        foreach ($this->data as $item) {
            $presensi = $item['presensi_masuk'];

            // CRITICAL FIX: Gunakan string "-" literal, bukan null
            // Excel/PhpSpreadsheet akan convert null/empty ke timestamp
            $waktuMasuk = '-';

            if ($presensi !== null) {
                // Cek apakah waktu ada dan bukan null/empty
                if (
                    array_key_exists('waktu', $presensi) &&
                    $presensi['waktu'] !== null &&
                    $presensi['waktu'] !== '' &&
                    trim($presensi['waktu']) !== ''
                ) {
                    // Ambil waktu yang valid
                    $waktuMasuk = $presensi['waktu'];
                }
            }

            $collection->push([
                $item['nik'],
                $item['nama'],
                $item['divisi'],
                $item['jabatan'],
                $item['shift'],
                $waktuMasuk,  // String "-" atau waktu valid
                $presensi ? $this->getStatusText($presensi['status']) : ($item['shift_code'] === 'L' ? 'Libur' : 'Alpa'),
                $presensi && isset($presensi['keterangan']) ? $presensi['keterangan'] : '-',
                $presensi && isset($presensi['lokasi_nama']) ? $presensi['lokasi_nama'] : '-',
                ($presensi && isset($presensi['latitude']) && isset($presensi['longitude']))
                    ? ($presensi['latitude'] . ', ' . $presensi['longitude'])
                    : '-'
            ]);
        }

        return $collection;
    }

    public function headings(): array
    {
        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15,  // NIK
            'B' => 25,  // Nama
            'C' => 20,  // Penempatan
            'D' => 20,  // Jabatan
            'E' => 20,  // Shift
            'F' => 12,  // Waktu
            'G' => 15,  // Status
            'H' => 35,  // Keterangan
            'I' => 25,  // Lokasi
            'J' => 20,  // Koordinat
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Title
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EA580C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ]);
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Project name
        $sheet->mergeCells('A3:J3');
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Date
        $sheet->mergeCells('A4:J4');
        $sheet->getStyle('A4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Project info (bold first column)
        $sheet->getStyle('A6:A8')->applyFromArray([
            'font' => ['bold' => true]
        ]);

        // Statistics header
        $sheet->mergeCells('A10:B10');
        $sheet->getStyle('A10')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EA580C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Statistics table header
        $sheet->getStyle('A11:B11')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Statistics data
        $sheet->getStyle('A12:B16')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Table header
        $sheet->getStyle('A18:J18')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EA580C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Data rows - FORMAT KOLOM WAKTU SEBAGAI TEXT
        $dataStartRow = 19;
        $dataEndRow = 18 + count($this->data);

        if ($dataEndRow >= $dataStartRow) {
            $sheet->getStyle("A{$dataStartRow}:J{$dataEndRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
            ]);

            // Center align for NIK and Waktu
            $sheet->getStyle("A{$dataStartRow}:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("F{$dataStartRow}:F{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("G{$dataStartRow}:G{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // PENTING: Format kolom waktu sebagai TEXT, bukan TIME
            $sheet->getStyle("F{$dataStartRow}:F{$dataEndRow}")->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        }

        return [];
    }

    public function title(): string
    {
        return 'Presensi Masuk';
    }

    private function getStatusText($status)
    {
        $statusMap = [
            'hadir' => 'Hadir',
            'terlambat' => 'Terlambat',
            'izin' => 'Izin',
            'alpa' => 'Alpa',
            'libur' => 'Libur'
        ];

        return $statusMap[$status] ?? $status;
    }
}

// Sheet Presensi Pulang
class PresensiPulangSheet implements FromCollection, WithHeadings, WithStyles, WithColumnWidths, WithTitle
{
    protected $data;
    protected $project;
    protected $statistik;
    protected $tanggal;

    public function __construct($data, $project, $statistik, $tanggal)
    {
        $this->data = $data;
        $this->project = $project;
        $this->statistik = $statistik;
        $this->tanggal = $tanggal;
    }

    public function collection()
    {
        $collection = collect();

        // Add header info
        $collection->push(['REKAP PRESENSI PULANG']);
        $collection->push([]);
        $collection->push([$this->project['nama']]);

        $tanggalFormatted = Carbon::parse($this->tanggal)->locale('id')->isoFormat('dddd, D MMMM YYYY');
        $collection->push([$tanggalFormatted . ' - PT. QIPRAH MULTI SERVICE']);
        $collection->push([]);

        // Project info & statistics
        $collection->push(['Nama Project', $this->project['nama']]);
        $collection->push(['Lokasi', $this->project['lokasi']['nama'] ?? '-']);
        $collection->push(['Total Karyawan', $this->statistik['total']]);
        $collection->push([]);

        // Statistics
        $collection->push(['STATISTIK PRESENSI PULANG']);
        $collection->push(['Status', 'Jumlah']);
        $collection->push(['Hadir', $this->statistik['pulang']['hadir']]);
        $collection->push(['Lembur', $this->statistik['pulang']['lembur']]);
        $collection->push(['Lembur (Pending)', $this->statistik['pulang']['lembur_pending']]);
        $collection->push(['Pulang Cepat', $this->statistik['pulang']['pulang_cepat']]);
        $collection->push(['Tidak Presensi Pulang', $this->statistik['pulang']['tidak_presensi_pulang']]);
        $collection->push(['Izin', $this->statistik['pulang']['izin']]);
        $collection->push(['Alpa', $this->statistik['pulang']['alpa']]);
        $collection->push(['Libur', $this->statistik['pulang']['libur']]);
        $collection->push([]);

        // Table headers
        $collection->push(['NIK', 'Nama', 'Penempatan', 'Jabatan', 'Shift', 'Waktu Pulang', 'Status', 'Keterangan', 'Lokasi', 'Koordinat']);

        // Data rows
        foreach ($this->data as $item) {
            $presensi = $item['presensi_pulang'];

            // CRITICAL FIX: Gunakan string "-" literal, bukan null
            // Excel/PhpSpreadsheet akan convert null/empty ke timestamp
            $waktuPulang = '-';

            if ($presensi !== null) {
                // Cek apakah waktu ada dan bukan null/empty
                if (
                    array_key_exists('waktu', $presensi) &&
                    $presensi['waktu'] !== null &&
                    $presensi['waktu'] !== '' &&
                    trim($presensi['waktu']) !== ''
                ) {
                    // Ambil waktu yang valid
                    $waktuPulang = $presensi['waktu'];
                }
            }

            $collection->push([
                $item['nik'],
                $item['nama'],
                $item['divisi'],
                $item['jabatan'],
                $item['shift'],
                $waktuPulang,  // String "-" atau waktu valid
                $presensi ? $this->getStatusText($presensi['status']) : ($item['shift_code'] === 'L' ? 'Libur' : 'Alpa'),
                $presensi && isset($presensi['keterangan']) ? $presensi['keterangan'] : '-',
                $presensi && isset($presensi['lokasi_nama']) ? $presensi['lokasi_nama'] : '-',
                ($presensi && isset($presensi['latitude']) && isset($presensi['longitude']))
                    ? ($presensi['latitude'] . ', ' . $presensi['longitude'])
                    : '-'
            ]);
        }

        return $collection;
    }

    public function headings(): array
    {
        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 15,  // NIK
            'B' => 25,  // Nama
            'C' => 20,  // Penempatan
            'D' => 20,  // Jabatan
            'E' => 20,  // Shift
            'F' => 12,  // Waktu
            'G' => 20,  // Status
            'H' => 35,  // Keterangan
            'I' => 25,  // Lokasi
            'J' => 20,  // Koordinat
        ];
    }

    public function styles(Worksheet $sheet)
    {
        // Title
        $sheet->mergeCells('A1:J1');
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 14, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EA580C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER]
        ]);
        $sheet->getRowDimension(1)->setRowHeight(25);

        // Project name
        $sheet->mergeCells('A3:J3');
        $sheet->getStyle('A3')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Date
        $sheet->mergeCells('A4:J4');
        $sheet->getStyle('A4')->applyFromArray([
            'font' => ['bold' => true, 'size' => 11],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Project info (bold first column)
        $sheet->getStyle('A6:A8')->applyFromArray([
            'font' => ['bold' => true]
        ]);

        // Statistics header
        $sheet->mergeCells('A10:B10');
        $sheet->getStyle('A10')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EA580C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER]
        ]);

        // Statistics table header
        $sheet->getStyle('A11:B11')->applyFromArray([
            'font' => ['bold' => true],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'F3F4F6']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Statistics data
        $sheet->getStyle('A12:B19')->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Table header
        $sheet->getStyle('A21:J21')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => 'EA580C']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]]
        ]);

        // Data rows - FORMAT KOLOM WAKTU SEBAGAI TEXT
        $dataStartRow = 22;
        $dataEndRow = 21 + count($this->data);

        if ($dataEndRow >= $dataStartRow) {
            $sheet->getStyle("A{$dataStartRow}:J{$dataEndRow}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER]
            ]);

            // Center align for NIK and Waktu
            $sheet->getStyle("A{$dataStartRow}:A{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("F{$dataStartRow}:F{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
            $sheet->getStyle("G{$dataStartRow}:G{$dataEndRow}")->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);

            // PENTING: Format kolom waktu sebagai TEXT, bukan TIME
            $sheet->getStyle("F{$dataStartRow}:F{$dataEndRow}")->getNumberFormat()->setFormatCode(\PhpOffice\PhpSpreadsheet\Style\NumberFormat::FORMAT_TEXT);
        }

        return [];
    }

    public function title(): string
    {
        return 'Presensi Pulang';
    }

    private function getStatusText($status)
    {
        $statusMap = [
            'hadir' => 'Hadir',
            'lembur' => 'Lembur',
            'lembur_pending' => 'Lembur (Pending)',
            'pulang_cepat' => 'Pulang Cepat',
            'tidak_presensi_pulang' => 'Tidak Presensi Pulang',
            'izin' => 'Izin',
            'alpa' => 'Alpa',
            'libur' => 'Libur'
        ];

        return $statusMap[$status] ?? $status;
    }
}
