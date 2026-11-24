<?php

namespace App\Models;

use App\Models\Concerns\PerteneceANegocio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'deudor_id', 'pago_id', 'fecha', 'valor', 'nota', 'anulado_en', 'motivo_anulacion'])]
class Abono extends Model
{
    use HasUuids, PerteneceANegocio;

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'anulado_en' => 'datetime',
        ];
    }
}
