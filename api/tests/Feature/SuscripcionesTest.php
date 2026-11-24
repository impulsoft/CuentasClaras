<?php

namespace Tests\Feature;

use App\Models\Abono;
use App\Models\Cierre;
use App\Models\Deudor;
use App\Models\Fiado;
use App\Models\Negocio;
use App\Models\Pago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RF-07 a RF-13, RF-44, RN-12, RN-13, RN-26, RN-34
class SuscripcionesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Negocio $negocio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 10:00:00');
        $this->admin = User::factory()->admin()->create();
        $dueno = User::factory()->create();
        $this->negocio = $dueno->negocio;
        $this->negocio->update(['suscripcion_inicio_el' => '2026-10-20', 'suscripcion_vence_el' => '2026-11-20']);
        Sanctum::actingAs($this->admin);
    }

    private function pagar(array $datos = [])
    {
        return $this->postJson("/api/admin/negocios/{$this->negocio->id}/pagos", $datos + ['valor' => 30000]);
    }

    public function test_al_dia_el_pago_extiende_un_mes_desde_el_vencimiento_anterior(): void
    {
        $this->pagar()->assertOk()->assertJsonPath('suscripcion.vence_el', '2026-12-20')->assertJsonPath('pagos.0.vencia_el', '2026-11-20');
    }

    public function test_vencida_el_mes_corre_desde_el_dia_del_pago_y_se_desbloquea(): void
    {
        $this->negocio->update(['suscripcion_vence_el' => '2026-11-01', 'estado' => 'bloqueado', 'bloqueado_en' => now()]);

        $this->pagar()->assertOk()
            ->assertJsonPath('suscripcion.vence_el', '2026-12-18') // no se cobran los días vencidos (RN-12)
            ->assertJsonPath('estado', 'activo');
    }

    public function test_el_fin_de_mes_no_se_pasa_al_mes_siguiente(): void
    {
        $this->negocio->update(['suscripcion_vence_el' => '2027-01-31']);
        $this->pagar()->assertJsonPath('suscripcion.vence_el', '2027-02-28');
    }

    public function test_estados_de_la_suscripcion_y_resumen_del_mes(): void
    {
        $this->getJson("/api/admin/negocios/{$this->negocio->id}")->assertJsonPath('suscripcion.estado', 'por_vencer')->assertJsonPath('suscripcion.dias', 2);

        $vencido = Negocio::factory()->create(['suscripcion_vence_el' => '2026-11-10']);
        $this->assertSame('vencida', $vencido->estadoSuscripcion());
        $this->assertSame('activa', Negocio::factory()->create(['suscripcion_vence_el' => '2026-12-30'])->estadoSuscripcion());

        $this->pagar(['valor' => 35000]);
        $this->getJson('/api/admin/resumen')->assertOk()->assertJson([
            'total' => 3,
            'activos' => 2,
            'por_vencer' => 0,
            'vencidos' => 1,
            'recaudado_mes' => 35000,
        ]);
    }

    public function test_registrar_negocio_anota_el_primer_pago(): void
    {
        $respuesta = $this->postJson('/api/admin/negocios', [
            'nombre_negocio' => 'Tienda Nueva', 'nombre_dueno' => 'Ana', 'telefono' => '3001112233',
            'usuario' => 'tienda.nueva', 'contrasena' => 'clave-segura-1', 'valor_pago' => 30000,
        ])->assertCreated();

        $respuesta->assertJsonPath('suscripcion.inicio_el', '2026-11-18')->assertJsonPath('suscripcion.vence_el', '2026-12-18');
    }

    public function test_bloquear_deja_al_dueno_en_solo_consulta_pero_lo_registrado_sin_internet_entra(): void
    {
        $this->postJson("/api/admin/negocios/{$this->negocio->id}/bloquear")->assertOk()->assertJsonPath('estado', 'bloqueado');

        Sanctum::actingAs($this->negocio->dueno);
        $respuesta = $this->postJson('/api/sincronizar', ['cierres' => [[
            'id' => (string) Str::uuid(), 'fecha' => '2026-11-17', 'tipo' => 'normal', 'no_abrio' => false,
            'base' => 100000, 'efectivo' => 300000, 'salida_caja' => 0,
        ]]])->assertOk();

        $respuesta->assertJsonPath('negocio.estado', 'bloqueado'); // RF-12
        $this->assertSame(1, Cierre::withoutGlobalScopes()->count()); // RN-27
    }

    public function test_el_admin_ve_lo_que_le_deben_al_negocio_sin_nombres(): void
    {
        $deudor = Deudor::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $this->negocio->id, 'nombre' => 'Carlos Pérez', 'telefono' => '3104567890']);
        $fiado = fn (array $d) => Fiado::withoutGlobalScopes()->forceCreate($d + ['id' => (string) Str::uuid(), 'negocio_id' => $this->negocio->id, 'deudor_id' => $deudor->id, 'descripcion' => 'Arroz']);
        $fiado(['fecha' => '2026-11-01', 'valor' => 10000, 'fecha_pago' => '2026-11-05']); // cubierto por el abono
        $fiado(['fecha' => '2026-11-10', 'valor' => 30000, 'fecha_pago' => '2026-11-15']); // vencido con saldo
        Abono::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $this->negocio->id, 'deudor_id' => $deudor->id, 'fecha' => '2026-11-12', 'valor' => 18000]);

        $respuesta = $this->getJson("/api/admin/negocios/{$this->negocio->id}")->assertOk();

        $respuesta->assertJsonPath('deudas', ['por_cobrar' => 22000, 'deudores' => 1, 'vencidos' => 1]);
        $this->assertStringNotContainsString('Carlos', $respuesta->getContent()); // RN-26
        $this->assertStringNotContainsString('3104567890', $respuesta->getContent());
    }

    public function test_el_admin_ve_los_cierres_del_mes_sin_nombres_de_quien_pago_ni_de_quien_abono(): void
    {
        $id = $this->negocio->id;
        Cierre::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $id, 'fecha' => '2026-11-17', 'base' => 100000, 'efectivo' => 400000]);
        Pago::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $id, 'fecha' => '2026-11-17', 'hora' => '11:15', 'medio' => 'nequi', 'origen' => 'pantallazo', 'valor' => 18000, 'nombre_pagador' => 'Carlos Pérez', 'referencia' => 'M8423116']);
        $deudor = Deudor::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $id, 'nombre' => 'Carlos Pérez', 'telefono' => '3104567890']);
        Abono::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $id, 'deudor_id' => $deudor->id, 'fecha' => '2026-11-17', 'valor' => 12000]);

        $respuesta = $this->getJson("/api/admin/negocios/{$id}/mes?anio=2026&mes=11")->assertOk();

        $respuesta->assertJsonPath('cierres.0.efectivo', 400000)->assertJsonPath('pagos.0.valor', 18000)->assertJsonPath('abonos_por_dia.2026-11-17', 12000);
        $this->assertStringNotContainsString('Carlos', $respuesta->getContent());
        $this->assertStringNotContainsString('M8423116', $respuesta->getContent());
    }

    public function test_el_admin_no_puede_cambiar_cierres_y_el_dueno_no_entra_al_panel(): void
    {
        $this->postJson('/api/sincronizar', [])->assertForbidden(); // RN-11

        Sanctum::actingAs($this->negocio->dueno);
        $this->getJson('/api/admin/negocios')->assertForbidden();
        $this->postJson("/api/admin/negocios/{$this->negocio->id}/pagos", ['valor' => 1])->assertForbidden();
    }

    public function test_el_dueno_ve_su_suscripcion_por_vencer(): void
    {
        Sanctum::actingAs($this->negocio->dueno);
        $this->getJson('/api/yo')->assertJsonPath('negocio.suscripcion_estado', 'por_vencer')->assertJsonPath('negocio.suscripcion_vence_el', '2026-11-20');
    }

    public function test_a_los_5_meses_sin_servicio_avisa_y_a_los_6_borra_los_datos(): void
    {
        $id = $this->negocio->id;
        $this->negocio->update(['suscripcion_vence_el' => '2026-06-10']);
        Cierre::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $id, 'fecha' => '2026-06-01', 'base' => 1, 'efectivo' => 2]);
        $deudor = Deudor::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $id, 'nombre' => 'Ana', 'telefono' => '3201112233']);
        Fiado::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $id, 'deudor_id' => $deudor->id, 'fecha' => '2026-06-01', 'descripcion' => 'Arroz', 'valor' => 1000, 'fecha_pago' => '2026-06-05']);

        // 18 nov: 5 meses y 8 días sin servicio → aviso con la fecha del borrado
        $this->assertSame('2026-12-10', $this->negocio->refresh()->datosSeBorranEl());
        $this->artisan('negocios:borrar-datos-vencidos')->assertSuccessful();
        $this->assertSame(1, Cierre::withoutGlobalScopes()->count());

        $this->travelTo('2026-12-10 04:00:00');
        $this->artisan('negocios:borrar-datos-vencidos --simular')->assertSuccessful();
        $this->assertSame(1, Cierre::withoutGlobalScopes()->count());

        $this->artisan('negocios:borrar-datos-vencidos')->assertSuccessful();
        $this->assertSame(0, Cierre::withoutGlobalScopes()->count());
        $this->assertSame(0, Deudor::withoutGlobalScopes()->count());
        $this->assertSame(0, Fiado::withoutGlobalScopes()->count());
        $this->assertNotNull($this->negocio->refresh()->datos_borrados_en);
        $this->assertNotNull(Negocio::find($id)); // el negocio y la cuenta se conservan
    }

    public function test_un_negocio_al_dia_no_se_borra(): void
    {
        Cierre::withoutGlobalScopes()->forceCreate(['id' => (string) Str::uuid(), 'negocio_id' => $this->negocio->id, 'fecha' => '2026-06-01', 'base' => 1, 'efectivo' => 2]);
        $this->travelTo('2027-11-18 04:00:00');
        $this->negocio->update(['suscripcion_vence_el' => '2027-12-01']);

        $this->artisan('negocios:borrar-datos-vencidos')->assertSuccessful();
        $this->assertSame(1, Cierre::withoutGlobalScopes()->count());
    }

    public function test_la_lista_del_admin_no_hace_mas_consultas_con_mas_negocios(): void
    {
        $consultas = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/admin/negocios')->assertOk();

            return count(DB::getQueryLog());
        };
        $conUno = $consultas();
        User::factory()->count(5)->create();

        $this->assertSame($conUno, $consultas()); // RNF-08
        $this->getJson('/api/admin/negocios')->assertJsonCount(6);
    }
}
