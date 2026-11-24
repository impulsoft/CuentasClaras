<?php

namespace App\Models;

use App\Models\Concerns\PerteneceANegocio;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['id', 'nombre', 'telefono', 'autorizacion_en', 'anonimizado_en', 'unido_a'])]
class Deudor extends Model
{
    use HasUuids, PerteneceANegocio;

    protected $table = 'deudores';

    protected function casts(): array
    {
        return [
            'autorizacion_en' => 'datetime',
            'anonimizado_en' => 'datetime',
        ];
    }
}
