<?php

namespace App\Models;

use App\Models\Concerns\PerteneceANegocio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'deudor_id', 'fecha', 'descripcion', 'valor', 'fecha_pago', 'anulado_en', 'motivo_anulacion'])]
class Fiado extends Model
{
    use HasUuids, PerteneceANegocio;

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'fecha_pago' => 'date:Y-m-d',
            'anulado_en' => 'datetime',
        ];
    }
}
