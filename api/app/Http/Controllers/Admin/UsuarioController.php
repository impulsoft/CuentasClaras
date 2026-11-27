<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Negocio;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Usuarios del sistema, vistos por el admin: los administradores y las cuentas de cada negocio.
 * Un negocio puede tener varias cuentas y todas pueden lo mismo que el dueño; solo el admin las crea.
 * El admin nunca ve una contraseña: el sistema crea una temporal que se cambia al entrar (RF-46).
 */
class UsuarioController extends Controller
{
    private const MENSAJES = [
        'required' => 'Este dato es obligatorio.',
        'telefono.regex' => 'Escriba solo números, sin espacios ni guiones.',
        'usuario.regex' => 'Use solo letras, números, puntos, guiones o guiones bajos, sin espacios.',
        'usuario.min' => 'El usuario debe tener al menos 4 caracteres.',
        'usuario.unique' => 'Ese usuario ya existe. Escoja otro.',
        'max' => 'Este dato es demasiado largo.',
    ];

    public function index(Request $request)
    {
        $usuarios = User::with('negocio:id,nombre')->orderBy('nombre')->get();

        return [
            'administradores' => self::filas($usuarios->where('rol', User::ADMIN)->values(), $request->user()),
            'cuentas' => self::filas($usuarios->where('rol', User::DUENO)->values(), $request->user()),
        ];
    }

    // Otro administrador del sistema (por ejemplo, el analista). Entra con una contraseña temporal.
    public function crearAdmin(Request $request)
    {
        $datos = $request->validate(self::reglas(), self::MENSAJES);
        $temporal = NegocioController::contrasenaTemporal();
        $admin = User::create($datos + ['rol' => User::ADMIN, 'password' => $temporal, 'debe_cambiar_contrasena' => true]);

        return response()->json(['contrasena_temporal' => $temporal, 'usuario' => self::filas(collect([$admin]), $request->user())[0]], 201);
    }

    // Otra cuenta para un negocio (la esposa, un hijo, un empleado): puede lo mismo que el dueño
    public function crearCuenta(Request $request, Negocio $negocio)
    {
        abort_if($negocio->datos_borrados_en !== null, 422, 'Este negocio fue retirado: no se le pueden crear cuentas.');
        $datos = $request->validate(self::reglas(), self::MENSAJES);
        $temporal = NegocioController::contrasenaTemporal();
        $cuenta = User::create($datos + [
            'negocio_id' => $negocio->id,
            'rol' => User::DUENO,
            'password' => $temporal,
            'debe_cambiar_contrasena' => true,
        ]);

        return response()->json(['contrasena_temporal' => $temporal, 'usuario' => self::filas(collect([$cuenta->load('negocio')]), $request->user())[0]], 201);
    }

    // RF-05: corregir el nombre, el usuario o el teléfono de una cuenta
    public function update(Request $request, User $usuario)
    {
        $datos = $request->validate(self::reglas($usuario), self::MENSAJES);
        $usuario->update($datos);

        return self::filas(collect([$usuario->load('negocio')]), $request->user())[0];
    }

    // RF-46: contraseña temporal para otra persona. La propia se cambia en Cuenta.
    public function restablecerContrasena(Request $request, User $usuario)
    {
        abort_if($usuario->is($request->user()), 422, 'Su propia contraseña se cambia en Cuenta → Cambiar contraseña.');
        $temporal = NegocioController::contrasenaTemporal();
        $usuario->update(['password' => $temporal, 'debe_cambiar_contrasena' => true, 'sesiones_validas_desde' => now()]);

        return ['contrasena_temporal' => $temporal];
    }

    // RF-05 / RN-16: no puede entrar; lo que registró se conserva
    public function desactivar(Request $request, User $usuario)
    {
        abort_if($usuario->is($request->user()), 422, 'No puede desactivar su propia cuenta.');
        $usuario->desactivar();

        return self::filas(collect([$usuario->load('negocio')]), $request->user())[0];
    }

    public function activar(Request $request, User $usuario)
    {
        $usuario->update(['activo' => true, 'desactivado_en' => null]);

        return self::filas(collect([$usuario->load('negocio')]), $request->user())[0];
    }

    private static function reglas(?User $usuario = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:100'],
            'usuario' => ['required', 'regex:/^[a-z0-9._-]+$/i', 'min:4', 'max:50', Rule::unique('users', 'usuario')->ignore($usuario?->id)],
            'telefono' => ['nullable', 'regex:/^\d{7,15}$/'],
        ];
    }

    /**
     * Lo que el admin ve de cada cuenta. La última conexión sale de los tokens (una consulta para
     * todas). "principal" es la cuenta con la que se creó el negocio.
     *
     * @param  Collection<int, User>  $usuarios
     */
    public static function filas(Collection $usuarios, ?User $yo = null): array
    {
        $ids = $usuarios->pluck('id')->all();
        $conexiones = DB::table('personal_access_tokens')
            ->where('tokenable_type', User::class)->whereIn('tokenable_id', $ids)
            ->groupBy('tokenable_id')->selectRaw('tokenable_id, MAX(last_used_at) AS ultima')
            ->pluck('ultima', 'tokenable_id');
        $principales = User::where('rol', User::DUENO)
            ->whereIn('negocio_id', $usuarios->pluck('negocio_id')->filter()->unique()->values())
            ->groupBy('negocio_id')->selectRaw('MIN(id) AS id')->pluck('id')->flip();

        return $usuarios->map(fn (User $u) => [
            'id' => $u->id,
            'nombre' => $u->nombre,
            'usuario' => $u->usuario,
            'telefono' => $u->telefono,
            'rol' => $u->rol,
            'activo' => $u->activo,
            'debe_cambiar_contrasena' => $u->debe_cambiar_contrasena,
            'ultima_conexion' => isset($conexiones[$u->id]) ? Carbon::parse($conexiones[$u->id])->toIso8601String() : null,
            'negocio' => $u->negocio ? ['id' => $u->negocio->id, 'nombre' => $u->negocio->nombre] : null,
            'principal' => $principales->has($u->id),
            'es_yo' => $yo !== null && $u->is($yo),
            'tiene_codigo_recuperacion' => $u->rol === User::ADMIN && $u->codigo_recuperacion_en !== null,
        ])->all();
    }
}
