<?php

namespace App\Imports;

use App\Models\Divisi;
use Maatwebsite\Excel\Concerns\ToModel;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class DivisiImport implements ToModel, WithHeadingRow
{
    public function model(array $row)
    {
        return new Divisi([
            'nama' => $row['nama_divisi'],
        ]);
    }
}