<?php

namespace App\Imports;

use App\Models\Jabatan;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class JabatanImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new Jabatan([
            'nama' => $row['nama_jabatan'],
        ]);
    }
}