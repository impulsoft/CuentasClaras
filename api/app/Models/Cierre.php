<?php

namespace App\Models;

use App\Models\Concerns\PerteneceANegocio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'fecha', 'tipo', 'no_abrio', 'base', 'efectivo', 'salida_caja', 'total_historico', 'nequi_historico', 'modificado_en'])]
class Cierre extends Model
{
    use HasUuids, PerteneceANegocio;

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'no_abrio' => 'boolean',
            'modificado_en' => 'datetime',
        ];
    }
}
