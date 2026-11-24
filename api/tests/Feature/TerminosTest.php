<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RF-03, RN-15, RNF-10
class TerminosTest extends TestCase
{
    use RefreshDatabase;

    public function test_dueno_sin_terminos_no_puede_usar_el_sistema(): void
    {
        Sanctum::actingAs(User::factory()->sinTerminos()->create());

        $this->getJson('/api/negocio')
            ->assertForbidden()
            ->assertJsonPath('codigo', 'terminos_pendientes');
    }

    public function test_al_aceptar_se_guarda_la_fecha_y_puede_seguir(): void
    {
        $dueno = User::factory()->sinTerminos()->create();
        Sanctum::actingAs($dueno);

        $this->postJson('/api/terminos', ['acepto' => true])
            ->assertOk()
            ->assertJsonPath('debe_aceptar_terminos', false);

        $this->assertNotNull($dueno->fresh()->terminos_aceptados_en);
        $this->getJson('/api/negocio')->assertOk();
    }

    public function test_hay_que_marcar_que_acepta(): void
    {
        Sanctum::actingAs(User::factory()->sinTerminos()->create());

        $this->postJson('/api/terminos', ['acepto' => false])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('acepto');
    }

    public function test_aceptar_otra_vez_conserva_la_primera_fecha(): void
    {
        $dueno = User::factory()->create(['terminos_aceptados_en' => '2026-01-10 08:00:00']);
        Sanctum::actingAs($dueno);

        $this->postJson('/api/terminos', ['acepto' => true])->assertOk();

        $this->assertSame('2026-01-10 08:00:00', $dueno->fresh()->terminos_aceptados_en->toDateTimeString());
    }
}
