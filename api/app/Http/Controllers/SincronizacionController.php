<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SesionVigente;
use App\Models\Abono;
use App\Models\Cierre;
use App\Models\Deudor;
use App\Models\Fiado;
use App\Models\Pago;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RF-30: el celular envía lo que guardó sin internet y recibe lo que cambió en el servidor.
 *
 * Cada registro viaja con "version": la fecha de modificación en el servidor que el celular
 * conocía. Si el servidor tiene otra versión distinta, no se sobrescribe: se devuelve como
 * conflicto para que el dueño escoja (RNF-12). Lo registrado sin internet nunca se rechaza
 * por estar bloqueado el negocio ni por llegar tarde (RN-27).
 */
class SincronizacionController extends Controller
{
    private const CAMPOS_CIERRE = ['fecha', 'tipo', 'no_abrio', 'base', 'efectivo', 'salida_caja', 'total_historico', 'nequi_historico', 'modificado_en'];

    private const CAMPOS_PAGO = ['fecha', 'hora', 'medio', 'origen', 'estado', 'valor', 'nombre_pagador', 'referencia', 'foto_ruta', 'anulado_en', 'motivo_anulacion'];

    private const CAMPOS_DEUDOR = ['nombre', 'telefono', 'autorizacion_en', 'anonimizado_en'];

    private const CAMPOS_FIADO = ['fecha', 'descripcion', 'valor', 'fecha_pago', 'anulado_en', 'motivo_anulacion'];

    private const CAMPOS_ABONO = ['fecha', 'valor', 'nota', 'pago_id', 'anulado_en', 'motivo_anulacion'];

    // RF-40 / RN-36: de un fiado solo se corrigen la descripción y la fecha de pago, o se anula; de un abono, solo se anula
    private const EDITABLES_FIADO = ['descripcion', 'fecha_pago', 'anulado_en', 'motivo_anulacion'];

    private const EDITABLES_ABONO = ['anulado_en', 'motivo_anulacion'];

    private const DIAS_FOTO = 90; // RN-43

    public const MOTIVO_REPETIDO = 'Comprobante repetido: ya estaba cargado desde otro celular (RN-05)';

    public function sincronizar(Request $request)
    {
        $peso = ['nullable', 'integer', 'min:0', 'max:999999999999'];
        $valor = ['required', 'integer', 'min:1', 'max:999999999999'];
        $fechaPasada = ['required', 'date_format:Y-m-d', 'before_or_equal:today']; // RN-07
        $datos = $request->validate([
            'desde' => ['nullable', 'date'],
            'cierres' => ['array', 'max:500'],
            'cierres.*.id' => ['required', 'uuid'],
            'cierres.*.version' => ['nullable', 'date'],
            'cierres.*.fecha' => $fechaPasada,
            'cierres.*.tipo' => ['required', 'in:normal,historico'],
            'cierres.*.no_abrio' => ['required', 'boolean'],
            'cierres.*.base' => $peso,
            'cierres.*.efectivo' => $peso,
            'cierres.*.salida_caja' => ['required', 'integer', 'min:0', 'max:999999999999'],
            'cierres.*.total_historico' => $peso,
            'cierres.*.nequi_historico' => $peso,
            'cierres.*.modificado_en' => ['nullable', 'date'],
            'pagos' => ['array', 'max:2000'],
            'pagos.*.id' => ['required', 'uuid'],
            'pagos.*.fecha' => $fechaPasada,
            'pagos.*.hora' => ['nullable', 'regex:/^\d{2}:\d{2}(:\d{2})?$/'],
            'pagos.*.medio' => ['required', 'in:nequi,otro'],
            'pagos.*.origen' => ['required', 'in:foto,pantallazo,manual'],
            'pagos.*.estado' => ['required', 'in:por_leer,por_confirmar,confirmado'],
            'pagos.*.valor' => ['nullable', 'integer', 'min:1', 'max:999999999999'],
            'pagos.*.nombre_pagador' => ['nullable', 'string', 'max:255'],
            'pagos.*.referencia' => ['nullable', 'string', 'max:40'],
            'pagos.*.foto_ruta' => ['nullable', 'string', 'max:255'],
            'pagos.*.anulado_en' => ['nullable', 'date'],
            'pagos.*.motivo_anulacion' => ['nullable', 'required_with:pagos.*.anulado_en', 'string', 'max:255'],
            'deudores' => ['array', 'max:1000'],
            'deudores.*.id' => ['required', 'uuid'],
            'deudores.*.nombre' => ['nullable', 'string', 'max:120'],
            'deudores.*.telefono' => ['nullable', 'string', 'max:20'],
            'deudores.*.autorizacion_en' => ['nullable', 'date'], // RN-41
            'deudores.*.anonimizado_en' => ['nullable', 'date'], // RN-42
            'fiados' => ['array', 'max:2000'],
            'fiados.*.id' => ['required', 'uuid'],
            'fiados.*.deudor_id' => ['required', 'uuid'],
            'fiados.*.fecha' => $fechaPasada,
            'fiados.*.descripcion' => ['required', 'string', 'max:255'],
            'fiados.*.valor' => $valor,
            'fiados.*.fecha_pago' => ['required', 'date_format:Y-m-d'],
            'fiados.*.anulado_en' => ['nullable', 'date'],
            'fiados.*.motivo_anulacion' => ['nullable', 'required_with:fiados.*.anulado_en', 'string', 'max:255'],
            'abonos' => ['array', 'max:2000'],
            'abonos.*.id' => ['required', 'uuid'],
            'abonos.*.deudor_id' => ['required', 'uuid'],
            'abonos.*.pago_id' => ['nullable', 'uuid'],
            'abonos.*.fecha' => $fechaPasada,
            'abonos.*.valor' => $valor,
            'abonos.*.nota' => ['nullable', 'string', 'max:255'],
            'abonos.*.anulado_en' => ['nullable', 'date'],
            'abonos.*.motivo_anulacion' => ['nullable', 'required_with:abonos.*.anulado_en', 'string', 'max:255'],
        ]);

        $inicio = now();
        $negocioId = $request->user()->negocio_id;
        $conflictos = [];

        // En orden: los fiados y abonos necesitan a su deudor, y un abono por Nequi a su pago (RN-39)
        DB::transaction(function () use ($datos, $negocioId, &$conflictos) {
            foreach ($datos['deudores'] ?? [] as $deudor) {
                $this->guardarDeudor($deudor, $negocioId);
            }
            foreach ($datos['cierres'] ?? [] as $cierre) {
                if ($conflicto = $this->guardarCierre($cierre, $negocioId)) {
                    $conflictos[] = $conflicto;
                }
            }
            foreach ($datos['pagos'] ?? [] as $pago) {
                $this->guardarPago($pago, $negocioId);
            }
            foreach ($datos['fiados'] ?? [] as $fiado) {
                $this->guardarMovimiento(Fiado::class, $fiado, $negocioId, self::CAMPOS_FIADO, self::EDITABLES_FIADO);
            }
            foreach ($datos['abonos'] ?? [] as $abono) {
                $this->guardarMovimiento(Abono::class, $abono, $negocioId, self::CAMPOS_ABONO, self::EDITABLES_ABONO);
            }
        });

        // Lo que cambió desde la última vez (incluye el mismo segundo de "desde": repetir un registro
        // no hace daño, perderlo sí) y, además, todo lo que el celular acaba de enviar, aunque no
        // haya cambiado nada: así el celular sabe que llegó y deja de tenerlo como pendiente.
        $desde = isset($datos['desde']) ? Carbon::parse($datos['desde'])->setTimezone(config('app.timezone')) : null;
        $cambios = fn (string $modelo, string $tabla) => $modelo::query()
            ->when($desde, fn ($q) => $q->where(fn ($q) => $q
                ->where('updated_at', '>=', $desde)
                ->orWhereIn('id', array_column($datos[$tabla] ?? [], 'id'))))
            ->get()
            ->map(fn (Model $m) => $this->paraEnviar($m));

        // RF-46 / RF-47: la contraseña cambió; lo pendiente ya quedó guardado y ahora se cierra la sesión
        if ($request->attributes->get(SesionVigente::ULTIMA_SINCRONIZACION)) {
            $request->user()->currentAccessToken()->delete();

            return SesionVigente::respuesta();
        }

        return [
            'hasta' => $inicio->toIso8601String(),
            'negocio' => $request->user()->negocio->paraDueno(), // RF-10, RF-12, RN-34
            'cierres' => $cambios(Cierre::class, 'cierres'),
            'pagos' => $cambios(Pago::class, 'pagos'),
            'deudores' => $cambios(Deudor::class, 'deudores'),
            'fiados' => $cambios(Fiado::class, 'fiados'),
            'abonos' => $cambios(Abono::class, 'abonos'),
            'conflictos' => $conflictos,
        ];
    }

    private function guardarCierre(array $datos, string $negocioId): ?array
    {
        $existente = Cierre::withoutGlobalScope('negocio')->find($datos['id']);

        if ($existente && $existente->negocio_id !== $negocioId) {
            return null; // un id de otro negocio nunca se toca (RNF-08)
        }

        $valores = $this->horaLocal(array_intersect_key($datos, array_flip(self::CAMPOS_CIERRE)), 'modificado_en');

        if (! $existente) {
            // RN-01: ese día ya tiene un cierre hecho en otro dispositivo
            $mismoDia = Cierre::where('fecha', $datos['fecha'])->first();
            if ($mismoDia) {
                return $this->conflicto('cierres', $datos['id'], $mismoDia);
            }
            Cierre::create(['id' => $datos['id']] + $valores);

            return null;
        }

        $existente->fill($valores);
        if (! $existente->isDirty()) {
            return null;
        }

        $conocida = isset($datos['version']) ? Carbon::parse($datos['version']) : null;
        if (! $conocida || ! $existente->updated_at->equalTo($conocida)) {
            return $this->conflicto('cierres', $datos['id'], $existente->refresh());
        }

        $existente->save();

        return null;
    }

    // Los pagos casi no se editan (solo se anulan, RN-36): gana el último cambio
    private function guardarPago(array $datos, string $negocioId): void
    {
        $existente = Pago::withoutGlobalScope('negocio')->find($datos['id']);

        if ($existente && $existente->negocio_id !== $negocioId) {
            return;
        }

        $valores = $this->horaLocal(array_intersect_key($datos, array_flip(self::CAMPOS_PAGO)), 'anulado_en');

        // Solo fotos subidas a la carpeta de este negocio (ComprobanteController)
        if (! empty($valores['foto_ruta']) && ! str_starts_with($valores['foto_ruta'], "comprobantes/{$negocioId}/")) {
            $valores['foto_ruta'] = null;
        }

        if ($existente) {
            // RN-36: una anulación no se deshace (un celular con una copia vieja no la puede quitar)
            if ($existente->anulado_en) {
                unset($valores['anulado_en'], $valores['motivo_anulacion']);
            }
            $existente->update($valores);
        } else {
            // RN-43: la foto se borra a los 90 días; la fecha la pone el servidor
            if (! empty($valores['foto_ruta'])) {
                $valores['foto_borrar_el'] = now()->addDays(self::DIAS_FOTO)->toDateString();
            }
            // RN-05: dos celulares sin internet cargaron el mismo comprobante. No se rechaza (RN-27):
            // el segundo queda anulado con el motivo, a la vista del dueño, y no cuenta en el total.
            if (! empty($valores['referencia']) && empty($valores['anulado_en'])
                && Pago::where('referencia', $valores['referencia'])->whereNull('anulado_en')->exists()) {
                $valores['anulado_en'] = now();
                $valores['motivo_anulacion'] = self::MOTIVO_REPETIDO;
            }
            Pago::create(['id' => $datos['id']] + $valores);
        }
    }

    /**
     * Deudores: gana el último cambio (RF-40), con tres cuidados:
     * - RN-32: si llega un deudor nuevo con un teléfono que ya existe, se une al que ya está.
     * - RN-42: a un deudor con los datos borrados nunca se le vuelven a poner el nombre ni el teléfono.
     * - Un cambio de teléfono que choca con otro deudor no se aplica.
     * Cuando el servidor no aplica algo, "toca" el registro para que vuelva al celular con lo correcto.
     */
    private function guardarDeudor(array $datos, string $negocioId): void
    {
        $existente = Deudor::withoutGlobalScope('negocio')->find($datos['id']);

        if ($existente && $existente->negocio_id !== $negocioId) {
            return;
        }

        $valores = array_intersect_key($datos, array_flip(self::CAMPOS_DEUDOR));
        $valores = $this->horaLocal($this->horaLocal($valores, 'autorizacion_en'), 'anonimizado_en');
        $valores['telefono'] = self::telefono($valores['telefono'] ?? null);
        $otroConTelefono = fn (?string $ignorar) => $valores['telefono'] === null ? null : Deudor::where('telefono', $valores['telefono'])
            ->whereNull('unido_a')
            ->when($ignorar, fn ($q) => $q->where('id', '!=', $ignorar))
            ->first();

        if (! $existente) {
            if ($mismo = $otroConTelefono(null)) {
                Deudor::create(['id' => $datos['id'], 'unido_a' => $mismo->id]);
                $mismo->touch();

                return;
            }
            Deudor::create(['id' => $datos['id']] + $valores);

            return;
        }

        if ($existente->unido_a) {
            return;
        }

        if ($existente->anonimizado_en) {
            $existente->touch();

            return;
        }

        if ($valores['telefono'] !== $existente->telefono && $otroConTelefono($existente->id)) {
            unset($valores['telefono']);
            $existente->touch();
        }

        $existente->update($valores);
    }

    // Fiados y abonos: al crearse se guardan completos; después solo cambian los campos editables
    private function guardarMovimiento(string $modelo, array $datos, string $negocioId, array $campos, array $editables): void
    {
        $existente = $modelo::withoutGlobalScope('negocio')->find($datos['id']);

        if ($existente && $existente->negocio_id !== $negocioId) {
            return;
        }

        $valores = $this->horaLocal(array_intersect_key($datos, array_flip($campos)), 'anulado_en');

        if ($existente) {
            $cambios = array_intersect_key($valores, array_flip($editables));
            if ($existente->anulado_en) {
                unset($cambios['anulado_en'], $cambios['motivo_anulacion']); // RN-36: no se deshace
            }
            $existente->update($cambios);

            return;
        }

        $deudorId = $this->deudorReal($datos['deudor_id']);
        if (! $deudorId) {
            // No debería pasar: el celular siempre envía el deudor junto con sus fiados y abonos
            Log::warning('Sincronización: movimiento sin deudor', ['tabla' => $modelo, 'id' => $datos['id']]);

            return;
        }

        if (! empty($valores['pago_id'])) {
            $pago = Pago::find($valores['pago_id']);
            if (! $pago) {
                $valores['pago_id'] = null;
            } elseif ($pago->anulado_en && empty($valores['anulado_en'])) {
                // Si el pago por Nequi quedó anulado (por ejemplo, comprobante repetido), su abono tampoco cuenta
                $valores['anulado_en'] = now();
                $valores['motivo_anulacion'] = $pago->motivo_anulacion;
            }
        }

        $modelo::create(['id' => $datos['id'], 'deudor_id' => $deudorId] + $valores);
    }

    // El deudor de este negocio, o aquel al que se unió (RN-32)
    private function deudorReal(string $id): ?string
    {
        $deudor = Deudor::find($id);

        return $deudor ? ($deudor->unido_a ?? $deudor->id) : null;
    }

    // Solo los números: "310 456-7890" y "+57 3104567890" son el mismo teléfono (RN-32)
    public static function telefono(?string $texto): ?string
    {
        $numeros = preg_replace('/\D/', '', (string) $texto);
        if (strlen($numeros) === 12 && str_starts_with($numeros, '57')) {
            $numeros = substr($numeros, 2);
        }

        return $numeros === '' ? null : $numeros;
    }

    // El celular manda fechas ISO con zona (p. ej. "...Z"); la base guarda la hora de Colombia (RNF-13)
    private function horaLocal(array $valores, string $campo): array
    {
        if (! empty($valores[$campo])) {
            $valores[$campo] = Carbon::parse($valores[$campo])->setTimezone(config('app.timezone'));
        }

        return $valores;
    }

    private function conflicto(string $tabla, string $idLocal, Model $servidor): array
    {
        return ['tabla' => $tabla, 'id_local' => $idLocal, 'servidor' => $this->paraEnviar($servidor)];
    }

    private function paraEnviar(Model $modelo): array
    {
        return $modelo->toArray() + ['version' => $modelo->updated_at?->toIso8601String()];
    }
}
