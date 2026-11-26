<?php

namespace App\Http\Controllers;

use App\Models\Departemen;
use App\Models\Divisi;

class DivisiController extends OrganizationController
{
    protected const RESOURCE = 'divisi';
    protected const LABEL = 'Divisi';
    protected const MODEL = Divisi::class;
    protected const CODE = 'kode_divisi';
    protected const NAME = 'nama_divisi';
    protected const CHILDREN = ['posisis', 'karyawans'];

    protected const PARENT = [
        'field' => 'uuid_departemen',
        'relation' => 'departemen',
        'model' => Departemen::class,
        'name' => 'departemen',
        'label' => 'Departemen',
    ];
}
