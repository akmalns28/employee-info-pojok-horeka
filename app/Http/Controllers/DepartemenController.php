<?php

namespace App\Http\Controllers;

use App\Models\Departemen;
use Illuminate\Http\Request;

class DepartemenController extends OrganizationController
{
    protected const RESOURCE = 'departemen';
    protected const LABEL = 'Departemen';
    protected const MODEL = Departemen::class;
    protected const CODE = 'kode_departemen';
    protected const NAME = 'departemen';
    protected const CHILDREN = ['divisis'];

    public function getAllDepartemen(Request $request)
    {
        return $this->dataTable($request);
    }
}
