<?php

namespace App\Models;

use Database\Factories\NegocioFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Fillable(['nombre', 'telefono', 'estado', 'suscripcion_inicio_el', 'suscripcion_vence_el', 'bloqueado_en', 'datos_borrados_en'])]
class Negocio extends Model
{
    /** @use HasFactory<NegocioFactory> */
    use HasFactory, HasUuids;

    // RF-08: "por vencer" cuando faltan estos días o menos
    public const DIAS_POR_VENCER = 5;

    // RN-34: a los 5 meses sin servicio se avisa; a los 6 se borran los datos
    public const MESES_AVISO = 5;

    public const MESES_BORRADO = 6;

    protected function casts(): array
    {
        return [
            'suscripcion_inicio_el' => 'date',
            'suscripcion_vence_el' => 'date',
            'bloqueado_en' => 'datetime',
            'datos_borrados_en' => 'datetime',
        ];
    }

    public function dueno(): HasOne
    {
        return $this->hasOne(User::class)->where('rol', User::DUENO);
    }

    public function pagosSuscripcion(): HasMany
    {
        return $this->hasMany(PagoSuscripcion::class)->orderByDesc('fecha')->orderByDesc('id');
    }

    public function estaBloqueado(): bool
    {
        return $this->estado === 'bloqueado';
    }

    // RF-08: activa, por vencer, vencida o sin pago (si nunca se registró uno)
    public function estadoSuscripcion(): string
    {
        $dias = $this->diasParaVencer();

        return match (true) {
            $dias === null => 'sin_pago',
            $dias < 0 => 'vencida',
            $dias <= self::DIAS_POR_VENCER => 'por_vencer',
            default => 'activa',
        };
    }

    // Días que faltan para el vencimiento, con la fecha de Colombia (RNF-13). Negativo si ya venció.
    public function diasParaVencer(): ?int
    {
        return $this->suscripcion_vence_el ? (int) today()->diffInDays($this->suscripcion_vence_el, false) : null;
    }

    /**
     * RN-12: si está al día, el pago extiende un mes desde el vencimiento anterior; si ya venció,
     * el mes corre desde el día del pago. Registrar el pago desbloquea el negocio (RF-12).
     */
    public function registrarPago(Carbon $fecha, int $valor, ?string $nota, ?int $adminId): PagoSuscripcion
    {
        return DB::transaction(function () use ($fecha, $valor, $nota, $adminId) {
            $vencia = $this->suscripcion_vence_el?->copy();
            $desde = $vencia && $vencia->greaterThanOrEqualTo($fecha) ? $vencia : $fecha->copy();
            $vence = $desde->copy()->addMonthNoOverflow();

            $pago = $this->pagosSuscripcion()->create([
                'fecha' => $fecha->toDateString(),
                'valor' => $valor,
                'vencia_el' => $vencia?->toDateString(),
                'vence_el' => $vence->toDateString(),
                'nota' => $nota,
                'registrado_por' => $adminId,
            ]);

            $this->update([
                'suscripcion_inicio_el' => $this->suscripcion_inicio_el ?? $fecha->toDateString(),
                'suscripcion_vence_el' => $vence->toDateString(),
                'estado' => 'activo',
                'bloqueado_en' => null,
            ]);

            return $pago;
        });
    }

    /**
     * Un pago registrado por error se anula con su motivo (no se borra) y el vencimiento se vuelve
     * a calcular con los pagos que quedan. No bloquea el negocio aunque quede vencido: eso lo
     * decide el admin, como siempre (RF-11).
     */
    public function anularPago(PagoSuscripcion $pago, string $motivo, ?int $adminId): void
    {
        DB::transaction(function () use ($pago, $motivo, $adminId) {
            $pago->update(['anulado_en' => now(), 'motivo_anulacion' => $motivo, 'anulado_por' => $adminId]);
            $this->recalcularSuscripcion();
        });
    }

    /*
     * Vuelve a aplicar RN-12 pago por pago, del más antiguo al más nuevo, solo con los vigentes:
     * si estaba al día, el mes corre desde el vencimiento anterior; si no, desde el día del pago.
     */
    public function recalcularSuscripcion(): void
    {
        $orden = fn () => $this->pagosSuscripcion()->getQuery()->reorder()->orderBy('fecha')->orderBy('id');
        // Se parte de cómo estaba antes del primer pago registrado (lo normal es "sin vencimiento")
        $inicial = $orden()->first()?->vencia_el;
        $vence = $inicial?->copy();
        $pagos = $orden()->vigentes()->get();
        foreach ($pagos as $pago) {
            $desde = $vence && $vence->greaterThanOrEqualTo($pago->fecha) ? $vence->copy() : $pago->fecha->copy();
            $nuevo = $desde->copy()->addMonthNoOverflow();
            $pago->update(['vencia_el' => $vence?->toDateString(), 'vence_el' => $nuevo->toDateString()]);
            $vence = $nuevo;
        }

        $this->update([
            'suscripcion_inicio_el' => $inicial ? $this->suscripcion_inicio_el : ($pagos->first()?->fecha ?? $this->suscripcion_inicio_el),
            'suscripcion_vence_el' => $vence?->toDateString(),
        ]);
    }

    /**
     * RN-34 / RN-16: borra de forma definitiva los datos del negocio: cierres, pagos del día,
     * fotos de comprobantes, fiados, abonos y deudores. Se conservan el negocio, la cuenta del
     * dueño y el historial de pagos de la suscripción (contabilidad del servicio).
     * Lo usan el borrado automático (routes/console.php) y el retiro a pedido del dueño.
     */
    public function borrarDatos(): void
    {
        DB::transaction(function () {
            $delNegocio = fn (string $modelo) => $modelo::withoutGlobalScopes()->where('negocio_id', $this->id);
            $fotos = $delNegocio(Pago::class)->whereNotNull('foto_ruta')->pluck('foto_ruta');

            $delNegocio(Abono::class)->delete();
            $delNegocio(Fiado::class)->delete();
            $delNegocio(Deudor::class)->delete();
            $delNegocio(Pago::class)->delete();
            $delNegocio(Cierre::class)->delete();
            $this->update(['datos_borrados_en' => now()]);

            Storage::disk('local')->delete($fotos->all());
            Storage::disk('local')->deleteDirectory("comprobantes/{$this->id}");
        });
    }

    /*
     * El dueño pidió cancelar el servicio y borrar su información (Ley 1581, habeas data): se
     * borran los datos ya, sin esperar los 6 meses, y la cuenta queda desactivada con las
     * sesiones cerradas en todos sus celulares.
     */
    public function retirar(): void
    {
        DB::transaction(function () {
            $this->borrarDatos();
            $this->dueno?->update(['activo' => false, 'desactivado_en' => now()]);
            $this->dueno?->tokens()->delete();
        });
    }

    /**
     * RN-34 / RN-16: desde cuándo el negocio está sin servicio (suscripción vencida, bloqueado
     * o dueño desactivado). Null si está funcionando normalmente.
     */
    public function sinServicioDesde(): ?Carbon
    {
        $fechas = array_filter([
            $this->estadoSuscripcion() === 'vencida' ? $this->suscripcion_vence_el : null,
            $this->bloqueado_en,
            $this->dueno?->activo === false ? $this->dueno->desactivado_en : null,
        ]);
        if (! $fechas) {
            return null;
        }

        return Carbon::parse(min(array_map(fn ($f) => $f->toDateString(), $fechas)));
    }

    // RN-34: fecha en que se borrarán los datos, solo cuando ya toca avisar (5 meses sin servicio)
    public function datosSeBorranEl(): ?string
    {
        $desde = $this->sinServicioDesde();
        if (! $desde || $this->datos_borrados_en || today()->lessThan($desde->copy()->addMonthsNoOverflow(self::MESES_AVISO))) {
            return null;
        }

        return $desde->copy()->addMonthsNoOverflow(self::MESES_BORRADO)->toDateString();
    }

    // Lo que el dueño ve de su negocio: estado y suscripción (RF-10, RF-12, RN-34)
    public function paraDueno(): array
    {
        return [
            'id' => $this->id,
            'nombre' => $this->nombre,
            'estado' => $this->estado,
            'suscripcion_vence_el' => $this->suscripcion_vence_el?->toDateString(),
            'suscripcion_estado' => $this->estadoSuscripcion(),
            'datos_se_borran_el' => $this->datosSeBorranEl(),
        ];
    }
}
