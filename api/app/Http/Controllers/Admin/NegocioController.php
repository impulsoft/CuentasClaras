<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Abono;
use App\Models\Cierre;
use App\Models\Negocio;
use App\Models\Pago;
use App\Models\PagoSuscripcion;
use App\Models\User;
use App\Support\ResumenDeudas;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Panel del admin (sección 4.1): negocios, dueños, suscripciones y consulta de cierres en solo lectura.
 * El admin nunca ve nombres ni teléfonos de los deudores (RN-26) ni la contraseña de un dueño (RF-46).
 */
class NegocioController extends Controller
{
    private const MENSAJES = [
        'required' => 'Este dato es obligatorio.',
        'telefono.regex' => 'Escriba solo números, sin espacios ni guiones.',
        'usuario.regex' => 'Use solo letras, números, puntos, guiones o guiones bajos, sin espacios.',
        'usuario.min' => 'El usuario debe tener al menos 4 caracteres.',
        'usuario.unique' => 'Ese usuario ya existe. Escoja otro.',
        'contrasena.min' => 'La contraseña debe tener al menos 8 caracteres.',
        'valor_pago.min' => 'Escriba el valor que pagó.',
        'valor.min' => 'Escriba el valor que pagó.',
        'fecha_pago.before_or_equal' => 'La fecha del pago no puede ser un día que no ha llegado.',
        'fecha.before_or_equal' => 'La fecha del pago no puede ser un día que no ha llegado.',
        'max' => 'Este dato es demasiado largo.',
    ];

    // RF-06 / RF-08 / RF-44: todos los negocios con su suscripción y lo que les deben
    // La misma cantidad de consultas con 3 negocios o con 3.000 (RNF-08)
    public function index()
    {
        $negocios = Negocio::with('dueno')->orderBy('nombre')->get();
        $ids = $negocios->modelKeys();
        $deudas = ResumenDeudas::deVarios($ids);
        $ultimosValores = PagoSuscripcion::whereIn('negocio_id', $ids)
            ->orderByDesc('fecha')->orderByDesc('id')
            ->get(['negocio_id', 'valor'])
            ->unique('negocio_id')
            ->pluck('valor', 'negocio_id');
        $actividad = $this->actividadDe($ids);

        return $negocios->map(fn (Negocio $negocio) => $this->datos($negocio, $deudas[$negocio->id], (int) ($ultimosValores[$negocio->id] ?? 0), $actividad[$negocio->id]));
    }

    /*
     * Actividad de cada negocio, para que el admin vea a quién llamar antes de que deje de usar la app:
     * - ultimo_cierre: el último día registrado (cierre o "No abrió"); no cuentan los días cargados
     *   del cuaderno o de Excel, que son viejos.
     * - ultima_conexion: la última vez que la app del dueño habló con el servidor (sincronizó).
     * Dos consultas para todos los negocios juntos (RNF-08).
     */
    private function actividadDe(array $ids): array
    {
        $cierres = Cierre::whereIn('negocio_id', $ids)->where('tipo', 'normal')
            ->groupBy('negocio_id')->selectRaw('negocio_id, MAX(fecha) AS ultimo')
            ->pluck('ultimo', 'negocio_id');
        $conexiones = DB::table('personal_access_tokens')
            ->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->where('personal_access_tokens.tokenable_type', User::class)
            ->whereIn('users.negocio_id', $ids)
            ->groupBy('users.negocio_id')
            ->selectRaw('users.negocio_id AS negocio_id, MAX(personal_access_tokens.last_used_at) AS ultima')
            ->pluck('ultima', 'negocio_id');

        $actividad = [];
        foreach ($ids as $id) {
            $actividad[$id] = [
                'ultimo_cierre' => isset($cierres[$id]) ? substr((string) $cierres[$id], 0, 10) : null,
                'ultima_conexion' => isset($conexiones[$id]) ? Carbon::parse($conexiones[$id])->toIso8601String() : null,
            ];
        }

        return $actividad;
    }

    // RF-13: cuántos negocios hay activos, por vencer y vencidos, y lo recaudado este mes
    public function resumen()
    {
        $negocios = Negocio::with('dueno')->get();
        $cuenta = fn (string $estado) => $negocios->filter(fn (Negocio $n) => $n->estadoSuscripcion() === $estado)->count();

        return [
            'total' => $negocios->count(),
            'activos' => $cuenta('activa'),
            'por_vencer' => $cuenta('por_vencer'),
            'vencidos' => $cuenta('vencida') + $cuenta('sin_pago'),
            'bloqueados' => $negocios->filter->estaBloqueado()->count(),
            'recaudado_mes' => (int) PagoSuscripcion::whereBetween('fecha', [today()->startOfMonth()->toDateString(), today()->endOfMonth()->toDateString()])->sum('valor'),
        ];
    }

    public function show(Negocio $negocio)
    {
        $negocio->load('dueno', 'pagosSuscripcion');

        return $this->datos($negocio) + [
            'pagos' => $negocio->pagosSuscripcion->map(fn (PagoSuscripcion $p) => [
                'id' => $p->id,
                'fecha' => $p->fecha->toDateString(),
                'valor' => $p->valor,
                'vencia_el' => $p->vencia_el?->toDateString(),
                'vence_el' => $p->vence_el->toDateString(),
                'nota' => $p->nota,
            ]),
        ];
    }

    // RF-04: el admin registra al dueño, su negocio y el primer pago (una cuenta y un negocio, RN-09)
    public function store(Request $request)
    {
        $datos = $request->validate([
            'nombre_negocio' => ['required', 'string', 'max:100'],
            'nombre_dueno' => ['required', 'string', 'max:100'],
            'telefono' => ['required', 'regex:/^\d{7,15}$/'],
            'usuario' => ['required', 'regex:/^[a-z0-9._-]+$/i', 'min:4', 'max:50', 'unique:users,usuario'],
            'contrasena' => ['required', 'string', 'min:8', 'max:100'],
            'valor_pago' => ['required', 'integer', 'min:1', 'max:999999999'],
            'fecha_pago' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
        ], self::MENSAJES);

        $negocio = DB::transaction(function () use ($datos, $request) {
            $negocio = Negocio::create([
                'nombre' => $datos['nombre_negocio'],
                'telefono' => $datos['telefono'],
                'estado' => 'activo',
            ]);

            User::create([
                'negocio_id' => $negocio->id,
                'rol' => User::DUENO,
                'nombre' => $datos['nombre_dueno'],
                'usuario' => $datos['usuario'],
                'telefono' => $datos['telefono'],
                'password' => $datos['contrasena'],
            ]);

            $negocio->registrarPago($this->fecha($datos['fecha_pago'] ?? null), $datos['valor_pago'], 'Primer mes', $request->user()->id);

            return $negocio;
        });

        return response()->json($this->datos($negocio->load('dueno')), 201);
    }

    // RF-05: corregir los datos del negocio y del dueño
    public function update(Request $request, Negocio $negocio)
    {
        $dueno = $negocio->dueno;
        $datos = $request->validate([
            'nombre_negocio' => ['required', 'string', 'max:100'],
            'nombre_dueno' => ['required', 'string', 'max:100'],
            'telefono' => ['required', 'regex:/^\d{7,15}$/'],
            'usuario' => ['required', 'regex:/^[a-z0-9._-]+$/i', 'min:4', 'max:50', Rule::unique('users', 'usuario')->ignore($dueno?->id)],
        ], self::MENSAJES);

        DB::transaction(function () use ($negocio, $dueno, $datos) {
            $negocio->update(['nombre' => $datos['nombre_negocio'], 'telefono' => $datos['telefono']]);
            $dueno?->update(['nombre' => $datos['nombre_dueno'], 'usuario' => $datos['usuario'], 'telefono' => $datos['telefono']]);
        });

        return $this->show($negocio->refresh());
    }

    // RF-09 / RF-11 "Ya pagó": extiende el vencimiento (RN-12) y desbloquea (RF-12)
    public function registrarPago(Request $request, Negocio $negocio)
    {
        $datos = $request->validate([
            'valor' => ['required', 'integer', 'min:1', 'max:999999999'],
            'fecha' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'],
            'nota' => ['nullable', 'string', 'max:255'],
        ], self::MENSAJES);

        $negocio->registrarPago($this->fecha($datos['fecha'] ?? null), $datos['valor'], $datos['nota'] ?? null, $request->user()->id);

        return $this->show($negocio->refresh());
    }

    // RF-11 / RF-12: el dueño solo puede consultar hasta que pague (RN-13)
    public function bloquear(Negocio $negocio)
    {
        $negocio->update(['estado' => 'bloqueado', 'bloqueado_en' => $negocio->bloqueado_en ?? now()]);

        return $this->show($negocio->refresh());
    }

    public function desbloquear(Negocio $negocio)
    {
        $negocio->update(['estado' => 'activo', 'bloqueado_en' => null]);

        return $this->show($negocio->refresh());
    }

    // RF-05 / RN-16: un dueño desactivado no puede entrar; su historial se conserva (RN-34)
    public function desactivar(Negocio $negocio)
    {
        $negocio->dueno?->update(['activo' => false, 'desactivado_en' => now()]);
        $negocio->dueno?->tokens()->delete();

        return $this->show($negocio->refresh());
    }

    public function activar(Negocio $negocio)
    {
        $negocio->dueno?->update(['activo' => true, 'desactivado_en' => null]);

        return $this->show($negocio->refresh());
    }

    /**
     * RF-46: el sistema crea una contraseña temporal que el admin le dicta al dueño; el dueño
     * debe cambiarla al entrar. Las sesiones abiertas se cierran cuando sincronicen (SesionVigente).
     */
    public function restablecerContrasena(Negocio $negocio)
    {
        $dueno = $negocio->dueno;
        abort_unless($dueno, 404);

        $temporal = self::contrasenaTemporal();
        $dueno->update([
            'password' => $temporal,
            'debe_cambiar_contrasena' => true,
            'sesiones_validas_desde' => now(),
        ]);

        return ['contrasena_temporal' => $temporal];
    }

    /**
     * RF-07 / RF-22 a RF-25: cierres y pagos de un mes (anio y mes) o de un rango (desde y hasta),
     * en solo lectura, para el calendario y los análisis. Sin nombres de quien pagó ni de
     * quien abonó: de los abonos solo va el total de cada día (RN-26).
     */
    public function mes(Request $request, Negocio $negocio)
    {
        $datos = $request->validate([
            'anio' => ['required_without:desde', 'integer', 'min:2000', 'max:2100'],
            'mes' => ['required_with:anio', 'integer', 'min:1', 'max:12'],
            'desde' => ['required_without:anio', 'date_format:Y-m-d'],
            'hasta' => ['required_with:desde', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ]);
        if (isset($datos['desde'])) {
            [$desde, $hasta] = [$datos['desde'], $datos['hasta']];
        } else {
            $desde = Carbon::create($datos['anio'], $datos['mes'], 1)->toDateString();
            $hasta = Carbon::create($datos['anio'], $datos['mes'], 1)->endOfMonth()->toDateString();
        }
        $delNegocio = fn (string $modelo) => $modelo::withoutGlobalScopes()->where('negocio_id', $negocio->id)->whereBetween('fecha', [$desde, $hasta]);

        return [
            'cierres' => $delNegocio(Cierre::class)->get()->map(fn (Cierre $c) => [
                'fecha' => $c->fecha->toDateString(),
                'tipo' => $c->tipo,
                'no_abrio' => (bool) $c->no_abrio,
                'base' => $c->base,
                'efectivo' => $c->efectivo,
                'salida_caja' => (int) $c->salida_caja,
                'total_historico' => $c->total_historico,
                'nequi_historico' => $c->nequi_historico,
                'modificado_en' => $c->modificado_en?->toIso8601String(),
            ]),
            'pagos' => $delNegocio(Pago::class)->where('estado', 'confirmado')->get()->map(fn (Pago $p) => [
                'fecha' => $p->fecha->toDateString(),
                'hora' => $p->hora ? substr($p->hora, 0, 5) : null,
                'medio' => $p->medio,
                'valor' => $p->valor,
                'anulado_en' => $p->anulado_en?->toIso8601String(),
            ]),
            'abonos_por_dia' => $delNegocio(Abono::class)->whereNull('anulado_en')->groupBy('fecha')
                ->selectRaw('fecha, SUM(valor) AS total')->get()
                ->mapWithKeys(fn ($fila) => [substr((string) $fila->getRawOriginal('fecha'), 0, 10) => (int) $fila->total]),
        ];
    }

    private function datos(Negocio $negocio, ?array $deudas = null, ?int $ultimoValor = null, ?array $actividad = null): array
    {
        return [
            'id' => $negocio->id,
            'nombre' => $negocio->nombre,
            'telefono' => $negocio->telefono,
            'estado' => $negocio->estado,
            'suscripcion' => [
                'inicio_el' => $negocio->suscripcion_inicio_el?->toDateString(),
                'vence_el' => $negocio->suscripcion_vence_el?->toDateString(),
                'estado' => $negocio->estadoSuscripcion(),
                'dias' => $negocio->diasParaVencer(),
            ],
            'datos_se_borran_el' => $negocio->datosSeBorranEl(),
            'datos_borrados_en' => $negocio->datos_borrados_en?->toIso8601String(),
            'ultimo_valor' => $ultimoValor ?? (int) ($negocio->pagosSuscripcion()->value('valor') ?? 0),
            'deudas' => $deudas ?? ResumenDeudas::deNegocio($negocio->id),
            'actividad' => $actividad ?? $this->actividadDe([$negocio->id])[$negocio->id],
            'dueno' => $negocio->dueno ? [
                'nombre' => $negocio->dueno->nombre,
                'usuario' => $negocio->dueno->usuario,
                'telefono' => $negocio->dueno->telefono,
                'activo' => $negocio->dueno->activo,
                'debe_cambiar_contrasena' => $negocio->dueno->debe_cambiar_contrasena,
            ] : null,
        ];
    }

    private function fecha(?string $texto): Carbon
    {
        return $texto ? Carbon::parse($texto)->startOfDay() : today();
    }

    // Fácil de dictar por teléfono: sílabas y números, sin letras que se confundan (RF-46)
    public static function contrasenaTemporal(): string
    {
        $consonantes = 'bcdfgjkmnprstvz';
        $vocales = 'aeiou';
        $texto = '';
        for ($i = 0; $i < 3; $i++) {
            $texto .= $consonantes[random_int(0, strlen($consonantes) - 1)].$vocales[random_int(0, 4)];
        }

        return $texto.random_int(1000, 9999);
    }
}
