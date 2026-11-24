<?php

namespace Tests\Feature;

use App\Models\Cierre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

// RF-05, RF-46, RF-47, RN-16 (con tokens reales: aquí importa qué sesión sigue viva)
class ContrasenasTest extends TestCase
{
    use RefreshDatabase;

    private User $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dueno = User::factory()->create(['usuario' => 'tienda.jose', 'password' => 'clave-vieja-1']);
    }

    private function entrar(string $contrasena, string $usuario = 'tienda.jose'): string
    {
        return $this->postJson('/api/sesion', ['usuario' => $usuario, 'contrasena' => $contrasena])->assertOk()->json('token');
    }

    private function con(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function cierre(): array
    {
        return ['id' => (string) Str::uuid(), 'fecha' => '2026-11-17', 'tipo' => 'normal', 'no_abrio' => false, 'base' => 1, 'efectivo' => 2, 'salida_caja' => 0];
    }

    public function test_el_admin_restablece_y_el_dueno_debe_cambiar_la_contrasena_temporal(): void
    {
        $admin = User::factory()->admin()->create(['usuario' => 'admin', 'password' => 'clave-admin-1']);
        $tokenAdmin = $this->entrar('clave-admin-1', 'admin');

        $temporal = $this->con($tokenAdmin)->postJson("/api/admin/negocios/{$this->dueno->negocio_id}/contrasena")->assertOk()->json('contrasena_temporal');
        $this->assertMatchesRegularExpression('/^[a-z]{6}\d{4}$/', $temporal);

        $token = $this->entrar($temporal);
        $this->con($token)->getJson('/api/yo')->assertOk()->assertJsonPath('debe_cambiar_contrasena', true);
        $this->con($token)->postJson('/api/sincronizar', [])->assertForbidden()->assertJsonPath('codigo', 'debe_cambiar_contrasena');

        // Con la temporal no se pide la actual
        $nuevo = $this->con($token)->postJson('/api/contrasena', ['nueva' => 'mi-clave-nueva'])->assertOk()->json('token');
        $this->con($nuevo)->getJson('/api/yo')->assertOk()->assertJsonPath('debe_cambiar_contrasena', false);
        $this->entrar('mi-clave-nueva');
        $this->assertNotNull($admin);
    }

    public function test_cambiar_la_contrasena_pide_la_actual(): void
    {
        $token = $this->entrar('clave-vieja-1');

        $this->con($token)->postJson('/api/contrasena', ['actual' => 'otra', 'nueva' => 'clave-nueva-1'])->assertUnprocessable()->assertJsonValidationErrors('actual');
        $this->con($token)->postJson('/api/contrasena', ['actual' => 'clave-vieja-1', 'nueva' => 'corta'])->assertUnprocessable()->assertJsonValidationErrors('nueva');
        $this->con($token)->postJson('/api/contrasena', ['actual' => 'clave-vieja-1', 'nueva' => 'clave-vieja-1'])->assertUnprocessable()->assertJsonValidationErrors('nueva');
        $this->con($token)->postJson('/api/contrasena', ['actual' => 'clave-vieja-1', 'nueva' => 'clave-nueva-1'])->assertOk();
    }

    public function test_las_otras_sesiones_envian_lo_pendiente_y_despues_se_cierran(): void
    {
        $this->dueno->update(['terminos_aceptados_en' => now()]);
        $this->travel(-1)->minutes();
        $otroCelular = $this->entrar('clave-vieja-1');
        $this->travelBack();
        $esteCelular = $this->entrar('clave-vieja-1');

        $nuevo = $this->con($esteCelular)->postJson('/api/contrasena', ['actual' => 'clave-vieja-1', 'nueva' => 'clave-nueva-1'])->json('token');

        // El otro celular sincroniza: lo pendiente se guarda y la sesión se cierra
        $this->con($otroCelular)->postJson('/api/sincronizar', ['cierres' => [$this->cierre()]])->assertUnauthorized()->assertJsonPath('codigo', 'sesion_cerrada');
        $this->assertSame(1, Cierre::withoutGlobalScopes()->count());
        $this->con($otroCelular)->getJson('/api/yo')->assertUnauthorized();

        // Este celular sigue con su token nuevo
        $this->con($nuevo)->getJson('/api/yo')->assertOk();
    }

    public function test_un_dueno_desactivado_no_puede_entrar_y_al_activarlo_si(): void
    {
        $admin = User::factory()->admin()->create(['usuario' => 'admin', 'password' => 'clave-admin-1']);
        $tokenAdmin = $this->entrar('clave-admin-1', 'admin');
        $tokenDueno = $this->entrar('clave-vieja-1');

        $this->con($tokenAdmin)->postJson("/api/admin/negocios/{$this->dueno->negocio_id}/desactivar")->assertOk()->assertJsonPath('dueno.activo', false);
        $this->con($tokenDueno)->getJson('/api/yo')->assertUnauthorized();
        $this->postJson('/api/sesion', ['usuario' => 'tienda.jose', 'contrasena' => 'clave-vieja-1'])->assertForbidden();

        $this->con($tokenAdmin)->postJson("/api/admin/negocios/{$this->dueno->negocio_id}/activar")->assertOk()->assertJsonPath('dueno.activo', true);
        $this->entrar('clave-vieja-1');
        $this->assertNotNull($admin);
    }

    public function test_el_admin_corrige_los_datos_del_negocio_y_del_dueno(): void
    {
        User::factory()->admin()->create(['usuario' => 'admin', 'password' => 'clave-admin-1']);
        $tokenAdmin = $this->entrar('clave-admin-1', 'admin');

        $this->con($tokenAdmin)->putJson("/api/admin/negocios/{$this->dueno->negocio_id}", [
            'nombre_negocio' => 'Tienda Don José', 'nombre_dueno' => 'José Martínez', 'telefono' => '3101234567', 'usuario' => 'jose.martinez',
        ])->assertOk()->assertJsonPath('nombre', 'Tienda Don José')->assertJsonPath('dueno.usuario', 'jose.martinez');

        $this->con($tokenAdmin)->putJson("/api/admin/negocios/{$this->dueno->negocio_id}", [
            'nombre_negocio' => 'X', 'nombre_dueno' => 'Y', 'telefono' => '3101234567', 'usuario' => 'admin',
        ])->assertUnprocessable()->assertJsonValidationErrors('usuario');
    }
}
