<?php

namespace App\Models\Concerns;

use App\Models\Negocio;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RNF-08 / RN-10: un dueño solo ve y crea registros de su propio negocio.
 * El admin no tiene negocio, así que ve todos (en modo solo lectura, RN-11).
 */
trait PerteneceANegocio
{
    protected static function bootPerteneceANegocio(): void
    {
        static::addGlobalScope('negocio', function (Builder $query) {
            $negocioId = auth()->user()?->negocio_id;

            if ($negocioId) {
                $query->where($query->qualifyColumn('negocio_id'), $negocioId);
            }
        });

        static::creating(function (Model $modelo) {
            $negocioId = auth()->user()?->negocio_id;

            if ($negocioId) {
                $modelo->negocio_id = $negocioId;
            }
        });
    }

    public function negocio(): BelongsTo
    {
        return $this->belongsTo(Negocio::class);
    }
}
