<?php

namespace Tests\Feature;

use App\Http\Controllers\SincronizacionController;
use App\Models\Abono;
use App\Models\Cierre;
use App\Models\Pago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RF-30, RNF-12, RN-01, RN-07, RN-27, RNF-08
class SincronizacionTest extends TestCase
{
    use RefreshDatabase;

    private User $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 20:00:00');
        $this->dueno = User::factory()->create();
        Sanctum::actingAs($this->dueno);
    }

    private function cierre(array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'version' => null,
            'fecha' => '2026-11-18',
            'tipo' => 'normal',
            'no_abrio' => false,
            'base' => 100000,
            'efectivo' => 450000,
            'salida_caja' => 0,
            'total_historico' => null,
            'nequi_historico' => null,
            'modificado_en' => null,
        ], $cambios);
    }

    private function pago(array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'fecha' => '2026-11-18',
            'hora' => '08:42',
            'medio' => 'nequi',
            'origen' => 'manual',
            'estado' => 'confirmado',
            'valor' => 25000,
            'nombre_pagador' => null,
            'referencia' => null,
            'anulado_en' => null,
            'motivo_anulacion' => null,
        ], $cambios);
    }

    private function sincronizar(array $cuerpo)
    {
        return $this->postJson('/api/sincronizar', $cuerpo + ['desde' => null, 'cierres' => [], 'pagos' => []]);
    }

    public function test_sube_un_cierre_hecho_sin_internet_y_lo_devuelve_con_su_version(): void
    {
        $cierre = $this->cierre();

        $this->sincronizar(['cierres' => [$cierre]])
            ->assertOk()
            ->assertJsonPath('cierres.0.id', $cierre['id'])
            ->assertJsonPath('cierres.0.fecha', '2026-11-18')
            ->assertJsonPath('cierres.0.efectivo', 450000)
            ->assertJsonPath('conflictos', [])
            ->assertJsonStructure(['hasta', 'negocio' => ['estado'], 'cierres' => [['version']]]);

        $this->assertSame($this->dueno->negocio_id, Cierre::find($cierre['id'])->negocio_id);
    }

    public function test_corrige_un_cierre_si_conoce_la_ultima_version(): void
    {
        $cierre = $this->cierre();
        $version = $this->sincronizar(['cierres' => [$cierre]])->json('cierres.0.version');

        $this->travel(5)->minutes();
        $this->sincronizar(['cierres' => [$this->cierre(['id' => $cierre['id'], 'version' => $version, 'efectivo' => 470000])]])
            ->assertOk()
            ->assertJsonPath('conflictos', []);

        $this->assertSame(470000, Cierre::find($cierre['id'])->efectivo);
    }

    public function test_si_otro_dispositivo_cambio_el_cierre_no_lo_sobrescribe(): void
    {
        $cierre = $this->cierre();
        $versionVieja = $this->sincronizar(['cierres' => [$cierre]])->json('cierres.0.version');

        // Otro celular lo corrige primero
        $this->travel(5)->minutes();
        Cierre::find($cierre['id'])->update(['efectivo' => 480000]);

        $this->travel(5)->minutes();
        $this->sincronizar(['cierres' => [$this->cierre(['id' => $cierre['id'], 'version' => $versionVieja, 'efectivo' => 470000])]])
            ->assertOk()
            ->assertJsonPath('conflictos.0.tabla', 'cierres')
            ->assertJsonPath('conflictos.0.id_local', $cierre['id'])
            ->assertJsonPath('conflictos.0.servidor.efectivo', 480000);

        $this->assertSame(480000, Cierre::find($cierre['id'])->efectivo);
    }

    public function test_dos_dispositivos_cierran_el_mismo_dia(): void
    {
        $primero = $this->cierre();
        $this->sincronizar(['cierres' => [$primero]]);

        $segundo = $this->cierre(['efectivo' => 300000]);
        $this->sincronizar(['cierres' => [$segundo]])
            ->assertJsonPath('conflictos.0.id_local', $segundo['id'])
            ->assertJsonPath('conflictos.0.servidor.id', $primero['id']);

        $this->assertSame(1, Cierre::count());
    }

    public function test_reenviar_lo_mismo_no_genera_conflicto(): void
    {
        $cierre = $this->cierre();
        $this->sincronizar(['cierres' => [$cierre]]);

        $this->sincronizar(['cierres' => [$cierre]])->assertJsonPath('conflictos', []);
    }

    public function test_no_toca_registros_de_otro_negocio_aunque_conozca_el_id(): void
    {
        $otro = User::factory()->create();
        Sanctum::actingAs($otro);
        $ajeno = $this->cierre();
        $this->sincronizar(['cierres' => [$ajeno]]);

        Sanctum::actingAs($this->dueno);
        $this->sincronizar(['cierres' => [$this->cierre(['id' => $ajeno['id'], 'efectivo' => 1])]])
            ->assertOk()
            ->assertJsonCount(0, 'cierres');

        $this->assertSame(450000, Cierre::withoutGlobalScopes()->find($ajeno['id'])->efectivo);
    }

    public function test_solo_devuelve_lo_que_cambio_desde_la_ultima_vez(): void
    {
        $this->sincronizar(['cierres' => [$this->cierre(['fecha' => '2026-11-17'])]]);
        $this->travel(1)->hours();
        $hasta = $this->sincronizar([])->json('hasta');

        $this->travel(1)->hours();
        $this->sincronizar(['desde' => $hasta, 'cierres' => [$this->cierre()]])
            ->assertJsonCount(1, 'cierres')
            ->assertJsonPath('cierres.0.fecha', '2026-11-18');
    }

    public function test_no_acepta_cierres_con_fecha_futura(): void
    {
        $this->sincronizar(['cierres' => [$this->cierre(['fecha' => '2026-11-19'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cierres.0.fecha');
    }

    public function test_guarda_pagos_y_los_anula_sin_borrarlos(): void
    {
        $pago = $this->pago();
        $this->sincronizar(['pagos' => [$pago]])->assertJsonPath('pagos.0.valor', 25000);

        $this->sincronizar(['pagos' => [$this->pago([
            'id' => $pago['id'],
            'hora' => '08:42:00',
            'anulado_en' => '2026-11-19T01:00:00Z',
            'motivo_anulacion' => 'Valor equivocado',
        ])]])->assertOk();

        $guardado = Pago::find($pago['id']);
        $this->assertSame('Valor equivocado', $guardado->motivo_anulacion);
        $this->assertSame('2026-11-18 20:00:00', $guardado->anulado_en->toDateTimeString()); // 01:00 UTC = 20:00 en Colombia
    }

    public function test_anular_un_pago_exige_el_motivo(): void
    {
        $this->sincronizar(['pagos' => [$this->pago(['anulado_en' => '2026-11-18T20:00:00-05:00'])]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('pagos.0.motivo_anulacion');
    }

    public function test_negocio_bloqueado_igual_recibe_lo_hecho_sin_internet(): void
    {
        $this->dueno->negocio->update(['estado' => 'bloqueado']);

        $this->sincronizar(['cierres' => [$this->cierre()]])
            ->assertOk()
            ->assertJsonPath('negocio.estado', 'bloqueado');

        $this->assertSame(1, Cierre::count());
    }

    public function test_el_admin_no_sincroniza(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->sincronizar([])->assertForbidden();
    }

    public function test_lo_que_se_envia_sin_cambios_vuelve_para_que_el_celular_deje_de_tenerlo_pendiente(): void
    {
        $cierre = $this->cierre();
        $primera = $this->sincronizar(['cierres' => [$cierre]])->assertOk();

        $this->travel(5)->minutes();
        $repetido = $cierre + ['version' => $primera->json('cierres.0.version')];
        $this->sincronizar(['desde' => $primera->json('hasta'), 'cierres' => [$repetido]])
            ->assertOk()
            ->assertJsonCount(1, 'cierres')
            ->assertJsonPath('cierres.0.id', $cierre['id'])
            ->assertJsonCount(0, 'conflictos');
    }

    public function test_el_mismo_comprobante_desde_dos_celulares_queda_una_vez_y_el_otro_anulado(): void
    {
        $primero = $this->pago(['referencia' => 'M84231167', 'origen' => 'pantallazo', 'valor' => 18000]);
        $this->sincronizar(['pagos' => [$primero]])->assertOk();

        // El otro celular lo cargó sin internet, con otro id, y además lo marcó como abono de un fiado
        $repetido = $this->pago(['referencia' => 'M84231167', 'origen' => 'foto', 'valor' => 18000]);
        $deudor = ['id' => (string) Str::uuid(), 'nombre' => 'Carlos', 'telefono' => '3104567890', 'autorizacion_en' => null, 'anonimizado_en' => null];
        $abono = ['id' => (string) Str::uuid(), 'deudor_id' => $deudor['id'], 'pago_id' => $repetido['id'], 'fecha' => '2026-11-18', 'valor' => 12000, 'nota' => null, 'anulado_en' => null, 'motivo_anulacion' => null];
        $respuesta = $this->sincronizar(['pagos' => [$repetido], 'deudores' => [$deudor], 'abonos' => [$abono]])->assertOk();

        $this->assertNull(Pago::find($primero['id'])->anulado_en);
        $this->assertSame(SincronizacionController::MOTIVO_REPETIDO, Pago::find($repetido['id'])->motivo_anulacion);
        $this->assertSame(SincronizacionController::MOTIVO_REPETIDO, Abono::find($abono['id'])->motivo_anulacion);
        // El celular recibe el pago anulado y deja de sumarlo
        $this->assertNotNull(collect($respuesta->json('pagos'))->firstWhere('id', $repetido['id'])['anulado_en']);

        // Una copia vieja sin la anulación no la deshace (RN-36)
        $this->sincronizar(['pagos' => [$repetido]])->assertOk();
        $this->assertNotNull(Pago::find($repetido['id'])->anulado_en);
    }
}
