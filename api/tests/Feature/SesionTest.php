<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RF-01, RF-02, RN-16
class SesionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inicia_sesion_con_usuario_y_contrasena(): void
    {
        $dueno = User::factory()->create(['usuario' => 'tienda.jose']);

        $respuesta = $this->postJson('/api/sesion', [
            'usuario' => 'tienda.jose',
            'contrasena' => 'password',
            'dispositivo' => 'Celular de prueba',
        ]);

        $respuesta->assertOk()
            ->assertJsonStructure(['token', 'usuario' => ['id', 'nombre', 'rol', 'negocio' => ['id', 'nombre']]])
            ->assertJsonPath('usuario.rol', 'dueno')
            ->assertJsonPath('usuario.negocio.id', $dueno->negocio_id);
    }

    public function test_rechaza_contrasena_incorrecta(): void
    {
        User::factory()->create(['usuario' => 'tienda.jose']);

        $this->postJson('/api/sesion', ['usuario' => 'tienda.jose', 'contrasena' => 'otra-clave'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('usuario');
    }

    public function test_dueno_desactivado_no_puede_iniciar_sesion(): void
    {
        User::factory()->desactivado()->create(['usuario' => 'tienda.cerrada']);

        $this->postJson('/api/sesion', ['usuario' => 'tienda.cerrada', 'contrasena' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('codigo', 'cuenta_desactivada');
    }

    public function test_dueno_desactivado_pierde_la_sesion_abierta(): void
    {
        $dueno = User::factory()->create();
        $token = $dueno->createToken('Celular')->plainTextToken;
        $dueno->update(['activo' => false]);

        $this->withToken($token)->getJson('/api/yo')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'cuenta_desactivada');

        $this->assertSame(0, $dueno->tokens()->count());
    }

    public function test_sin_sesion_no_puede_consultar_sus_datos(): void
    {
        $this->getJson('/api/yo')->assertUnauthorized();
    }

    public function test_cerrar_sesion_borra_solo_el_token_del_dispositivo(): void
    {
        $dueno = User::factory()->create();
        $token = $dueno->createToken('Celular')->plainTextToken;
        $dueno->createToken('PC');

        $this->withToken($token)->deleteJson('/api/sesion')->assertNoContent();

        $this->assertSame(['PC'], $dueno->tokens()->pluck('name')->all());
    }

    public function test_yo_devuelve_al_usuario_con_su_negocio(): void
    {
        $dueno = User::factory()->create();
        Sanctum::actingAs($dueno);

        $this->getJson('/api/yo')
            ->assertOk()
            ->assertJsonPath('usuario', $dueno->usuario)
            ->assertJsonPath('debe_aceptar_terminos', false)
            ->assertJsonPath('negocio.nombre', $dueno->negocio->nombre);
    }
}
