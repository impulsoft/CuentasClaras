<?php

namespace Tests\Feature;

use App\Models\Cierre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RF-04, RF-06, RN-09
class AdminNegociosTest extends TestCase
{
    use RefreshDatabase;

    private function datosNuevoNegocio(array $cambios = []): array
    {
        return array_merge([
            'nombre_negocio' => 'Tienda Don José',
            'nombre_dueno' => 'José Martínez',
            'telefono' => '3101234567',
            'usuario' => 'tienda.jose',
            'contrasena' => 'clave-segura-1',
            'valor_pago' => 30000,
        ], $cambios);
    }

    public function test_admin_registra_negocio_y_dueno(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/negocios', $this->datosNuevoNegocio())
            ->assertCreated()
            ->assertJsonPath('nombre', 'Tienda Don José')
            ->assertJsonPath('dueno.usuario', 'tienda.jose');

        $dueno = User::where('usuario', 'tienda.jose')->first();
        $this->assertSame(User::DUENO, $dueno->rol);
        $this->assertNotNull($dueno->negocio_id);
        $this->assertTrue($dueno->debeAceptarTerminos());
    }

    public function test_el_nuevo_dueno_puede_iniciar_sesion(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        $this->postJson('/api/admin/negocios', $this->datosNuevoNegocio())->assertCreated();

        $this->postJson('/api/sesion', ['usuario' => 'tienda.jose', 'contrasena' => 'clave-segura-1'])
            ->assertOk()
            ->assertJsonPath('usuario.debe_aceptar_terminos', true);
    }

    public function test_el_admin_ve_la_actividad_de_cada_negocio(): void
    {
        $admin = User::factory()->admin()->create();
        Sanctum::actingAs($admin);
        $negocioId = $this->postJson('/api/admin/negocios', $this->datosNuevoNegocio())->assertCreated()->json('id');

        // Recién creado: sin cierres y sin haber usado la app
        $this->getJson('/api/admin/negocios')->assertOk()
            ->assertJsonPath('0.actividad', ['ultimo_cierre' => null, 'ultima_conexion' => null]);

        // Los días del cuaderno o de Excel no cuentan como actividad; "No abrió" sí
        $cierre = fn (array $datos) => Cierre::withoutGlobalScopes()->forceCreate($datos + ['id' => (string) Str::uuid(), 'negocio_id' => $negocioId, 'salida_caja' => 0]);
        $cierre(['fecha' => '2026-11-18', 'tipo' => 'normal', 'no_abrio' => false, 'base' => 100000, 'efectivo' => 300000]);
        $cierre(['fecha' => '2026-11-19', 'tipo' => 'normal', 'no_abrio' => true]);
        $cierre(['fecha' => '2026-11-21', 'tipo' => 'historico', 'no_abrio' => false, 'total_historico' => 500000]);

        // El dueño entra y la app habla con el servidor (con su propio token, no como el admin)
        $this->app['auth']->forgetGuards();
        $token = $this->postJson('/api/sesion', ['usuario' => 'tienda.jose', 'contrasena' => 'clave-segura-1'])->json('token');
        $this->withToken($token)->getJson('/api/yo')->assertOk();
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        Sanctum::actingAs($admin);
        $actividad = $this->getJson('/api/admin/negocios')->assertOk()->json('0.actividad');
        $this->assertSame('2026-11-19', $actividad['ultimo_cierre']);
        $this->assertNotNull($actividad['ultima_conexion']);
        $this->getJson("/api/admin/negocios/{$negocioId}")->assertJsonPath('actividad.ultimo_cierre', '2026-11-19');
    }

    public function test_el_usuario_no_se_puede_repetir(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());
        User::factory()->create(['usuario' => 'tienda.jose']);

        $this->postJson('/api/admin/negocios', $this->datosNuevoNegocio())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usuario');
    }

    public function test_valida_telefono_y_contrasena(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->postJson('/api/admin/negocios', $this->datosNuevoNegocio(['telefono' => '31-abc', 'contrasena' => 'corta']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['telefono', 'contrasena']);
    }

    public function test_admin_ve_la_lista_de_negocios(): void
    {
        User::factory()->count(2)->create();
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/admin/negocios')
            ->assertOk()
            ->assertJsonCount(2)
            ->assertJsonStructure([['id', 'nombre', 'estado', 'dueno' => ['nombre', 'usuario', 'activo']]]);
    }

    public function test_un_dueno_no_puede_entrar_a_las_rutas_del_admin(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/negocios')->assertForbidden()->assertJsonPath('codigo', 'sin_permiso');
        $this->postJson('/api/admin/negocios', $this->datosNuevoNegocio())->assertForbidden();
    }

    public function test_el_admin_no_usa_las_rutas_del_dueno(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->getJson('/api/negocio')->assertForbidden();
    }
}
