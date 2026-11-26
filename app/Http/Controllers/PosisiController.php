<?php

namespace App\Http\Controllers;

use App\Models\Divisi;
use App\Models\Posisi;
use Illuminate\Http\Request;

class PosisiController extends OrganizationController
{
    protected const RESOURCE = 'posisi';
    protected const LABEL = 'Posisi';
    protected const MODEL = Posisi::class;
    protected const CODE = 'kode_posisi';
    protected const NAME = 'nama_posisi';
    protected const CHILDREN = ['karyawans'];

    protected const PARENT = [
        'field' => 'divisi_uuid',
        'relation' => 'divisi',
        'model' => Divisi::class,
        'name' => 'nama_divisi',
        'label' => 'Divisi',
    ];

    public function getAllPosisi(Request $request)
    {
        return $this->dataTable($request);
    }
}
