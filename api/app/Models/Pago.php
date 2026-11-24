<?php

namespace App\Models;

use App\Models\Concerns\PerteneceANegocio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'fecha', 'hora', 'medio', 'origen', 'estado', 'valor', 'nombre_pagador', 'referencia', 'foto_ruta', 'foto_borrar_el', 'anulado_en', 'motivo_anulacion'])]
class Pago extends Model
{
    use HasUuids, PerteneceANegocio;

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'foto_borrar_el' => 'date:Y-m-d',
            'anulado_en' => 'datetime',
        ];
    }
}
