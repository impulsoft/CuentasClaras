<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Un pago mensual de la suscripción de un negocio (RF-09)
#[Fillable(['negocio_id', 'fecha', 'valor', 'vencia_el', 'vence_el', 'nota', 'registrado_por'])]
class PagoSuscripcion extends Model
{
    protected $table = 'pagos_suscripcion';

    protected function casts(): array
    {
        return [
            'fecha' => 'date:Y-m-d',
            'vencia_el' => 'date:Y-m-d',
            'vence_el' => 'date:Y-m-d',
            'valor' => 'integer',
        ];
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }
}
