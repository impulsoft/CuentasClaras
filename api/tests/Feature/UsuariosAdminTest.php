<?php

namespace Tests\Feature;

use App\Models\Cierre;
use App\Models\Negocio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// Varios administradores, varias cuentas por negocio y el código de recuperación del admin
class UsuariosAdminTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create(['usuario' => 'admin', 'nombre' => 'Fabián']);
        $this->dueno = User::factory()->create(['usuario' => 'tienda.rosa', 'nombre' => 'Rosa']);
        Sanctum::actingAs($this->admin);
    }

    private function entrar(string $usuario, string $contrasena)
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson('/api/sesion', ['usuario' => $usuario, 'contrasena' => $contrasena]);
    }

    public function test_el_admin_ve_los_administradores_y_las_cuentas_de_los_negocios(): void
    {
        $this->getJson('/api/admin/usuarios')->assertOk()
            ->assertJsonPath('administradores.0.usuario', 'admin')
            ->assertJsonPath('administradores.0.es_yo', true)
            ->assertJsonPath('cuentas.0.usuario', 'tienda.rosa')
            ->assertJsonPath('cuentas.0.negocio.id', $this->dueno->negocio_id)
            ->assertJsonPath('cuentas.0.principal', true);
    }

    public function test_un_negocio_puede_tener_varias_cuentas_y_todas_pueden_lo_mismo(): void
    {
        $id = $this->dueno->negocio_id;
        $respuesta = $this->postJson("/api/admin/negocios/{$id}/cuentas", ['nombre' => 'Carlos', 'usuario' => 'tienda.carlos', 'telefono' => '3001112233'])
            ->assertCreated()->assertJsonPath('usuario.principal', false);
        $temporal = $respuesta->json('contrasena_temporal');

        $this->getJson("/api/admin/negocios/{$id}")->assertJsonCount(2, 'usuarios')->assertJsonPath('cuentas', ['total' => 2, 'activas' => 2])
            ->assertJsonPath('dueno.usuario', 'tienda.rosa'); // la principal sigue siendo la del dueño

        // Entra con la temporal, la cambia, acepta los términos y registra un cierre del mismo negocio
        $token = $this->entrar('tienda.carlos', $temporal)->assertOk()->assertJsonPath('usuario.debe_cambiar_contrasena', true)->json('token');
        $this->app['auth']->forgetGuards();
        $token = $this->withToken($token)->postJson('/api/contrasena', ['nueva' => 'clave-de-carlos-1'])->assertOk()->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/terminos', ['acepto' => true])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/sincronizar', ['cierres' => [[
            'id' => (string) Str::uuid(), 'fecha' => today()->toDateString(), 'tipo' => 'normal', 'no_abrio' => false,
            'base' => 100000, 'efectivo' => 300000, 'salida_caja' => 0,
        ]]])->assertOk();

        $this->assertSame($id, Cierre::withoutGlobalScopes()->first()->negocio_id);
    }

    public function test_el_negocio_queda_desactivado_solo_si_ninguna_cuenta_puede_entrar(): void
    {
        $id = $this->dueno->negocio_id;
        $otra = $this->postJson("/api/admin/negocios/{$id}/cuentas", ['nombre' => 'Carlos', 'usuario' => 'tienda.carlos'])->json('usuario.id');

        $this->postJson("/api/admin/usuarios/{$this->dueno->id}/desactivar")->assertOk()->assertJsonPath('activo', false);
        $this->getJson("/api/admin/negocios/{$id}")->assertJsonPath('cuentas', ['total' => 2, 'activas' => 1]);
        $this->assertNull(Negocio::find($id)->sinServicioDesde());

        $this->postJson("/api/admin/usuarios/{$otra}/desactivar")->assertOk();
        $this->assertNotNull(Negocio::find($id)->sinServicioDesde());

        // Desactivar o activar el negocio entero afecta a todas sus cuentas
        $this->postJson("/api/admin/negocios/{$id}/activar")->assertJsonPath('cuentas.activas', 2);
        $this->postJson("/api/admin/negocios/{$id}/desactivar")->assertJsonPath('cuentas.activas', 0);
    }

    public function test_retirar_desactiva_todas_las_cuentas_del_negocio(): void
    {
        $id = $this->dueno->negocio_id;
        $otra = User::factory()->create(['negocio_id' => $id]);
        $otra->createToken('celular');
        $nombre = Negocio::find($id)->nombre;

        $this->postJson("/api/admin/negocios/{$id}/retirar", ['confirmacion' => $nombre])->assertOk()->assertJsonPath('cuentas.activas', 0);
        $this->assertSame(0, $otra->tokens()->count());
        $this->postJson("/api/admin/negocios/{$id}/cuentas", ['nombre' => 'X', 'usuario' => 'nueva.cuenta'])->assertUnprocessable();
    }

    public function test_otro_administrador_entra_con_temporal_y_el_admin_no_se_desactiva_a_si_mismo(): void
    {
        $respuesta = $this->postJson('/api/administradores', [])->assertNotFound(); // la ruta va con /admin
        $respuesta = $this->postJson('/api/admin/administradores', ['nombre' => 'Brayan', 'usuario' => 'brayan'])->assertCreated();
        $this->entrar('brayan', $respuesta->json('contrasena_temporal'))->assertOk()->assertJsonPath('usuario.rol', 'admin');

        Sanctum::actingAs($this->admin);
        $this->postJson("/api/admin/usuarios/{$this->admin->id}/desactivar")->assertUnprocessable();
        $this->postJson("/api/admin/usuarios/{$this->admin->id}/contrasena")->assertUnprocessable();
        $this->postJson('/api/admin/administradores', ['nombre' => 'Otro', 'usuario' => 'tienda.rosa'])->assertJsonValidationErrors('usuario');

        // Un admin le da una temporal al otro
        $brayan = User::where('usuario', 'brayan')->first();
        $temporal = $this->postJson("/api/admin/usuarios/{$brayan->id}/contrasena")->assertOk()->json('contrasena_temporal');
        $this->entrar('brayan', $temporal)->assertOk();
    }

    public function test_corregir_los_datos_de_una_cuenta(): void
    {
        $this->putJson("/api/admin/usuarios/{$this->dueno->id}", ['nombre' => 'Rosa Martínez', 'usuario' => 'rosa.m', 'telefono' => '3109998877'])
            ->assertOk()->assertJsonPath('usuario', 'rosa.m')->assertJsonPath('telefono', '3109998877');
        $this->putJson("/api/admin/usuarios/{$this->dueno->id}", ['nombre' => 'Rosa', 'usuario' => 'admin'])->assertJsonValidationErrors('usuario');
    }

    public function test_un_dueno_no_entra_a_las_rutas_de_usuarios(): void
    {
        Sanctum::actingAs($this->dueno);
        $this->getJson('/api/admin/usuarios')->assertForbidden();
        $this->postJson('/api/admin/codigo-recuperacion')->assertForbidden();
    }

    public function test_el_admin_recupera_su_contrasena_con_el_codigo_una_sola_vez(): void
    {
        $codigo = $this->postJson('/api/admin/codigo-recuperacion')->assertOk()->json('codigo');
        $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}-[A-Z2-9]{4}$/', $codigo);
        $this->getJson('/api/yo')->assertJsonPath('codigo_recuperacion_en', fn ($fecha) => $fecha !== null);
        $this->admin->createToken('pc');
        $this->app['auth']->forgetGuards();

        // Se puede escribir en minúsculas y con espacios
        $escrito = strtolower(str_replace('-', ' ', $codigo));
        $this->postJson('/api/recuperar', ['usuario' => 'admin', 'codigo' => 'AAAA-BBBB-CCCC', 'nueva' => 'nueva-clave-123'])
            ->assertUnprocessable()->assertJsonPath('errors.codigo.0', 'El usuario o el código no son correctos.');
        $this->postJson('/api/recuperar', ['usuario' => 'admin', 'codigo' => $escrito, 'nueva' => 'nueva-clave-123'])->assertOk();

        $this->assertSame(0, $this->admin->tokens()->count()); // se cierran sus sesiones
        $this->entrar('admin', 'nueva-clave-123')->assertOk()->assertJsonPath('usuario.codigo_recuperacion_en', null);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/recuperar', ['usuario' => 'admin', 'codigo' => $codigo, 'nueva' => 'otra-clave-456'])->assertUnprocessable(); // ya se usó
    }

    public function test_despues_de_10_intentos_fallidos_el_usuario_se_bloquea_una_hora(): void
    {
        $codigo = $this->postJson('/api/admin/codigo-recuperacion')->json('codigo');
        $this->app['auth']->forgetGuards();
        $intento = fn (string $usuario, string $c) => $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.'.random_int(1, 250)]) // equipos distintos
            ->postJson('/api/recuperar', ['usuario' => $usuario, 'codigo' => $c, 'nueva' => 'nueva-clave-123']);

        for ($i = 0; $i < 10; $i++) {
            $intento('ADMIN', 'AAAA-BBBB-CCCC')->assertUnprocessable();
        }
        // Ni con el código correcto: está bloqueado ("ADMIN" y "admin" cuentan como el mismo usuario)
        $intento('admin', $codigo)->assertStatus(429)->assertJsonPath('errors.codigo.0', 'Demasiados intentos fallidos con este usuario. Espere una hora y vuelva a intentar.');

        $this->travel(61)->minutes();
        $intento('admin', $codigo)->assertOk();
    }

    public function test_un_dueno_no_tiene_codigo_de_recuperacion(): void
    {
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/recuperar', ['usuario' => 'tienda.rosa', 'codigo' => 'AAAA-BBBB-CCCC', 'nueva' => 'nueva-clave-123'])
            ->assertUnprocessable()->assertJsonPath('errors.codigo.0', 'El usuario o el código no son correctos.');
        $this->entrar('tienda.rosa', 'password')->assertOk();
    }
}
