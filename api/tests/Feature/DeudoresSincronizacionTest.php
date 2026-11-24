<?php

namespace Tests\Feature;

use App\Models\Abono;
use App\Models\Deudor;
use App\Models\Fiado;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RF-45 (deudores sin internet), RN-32, RN-36, RN-39, RN-42, RNF-08
class DeudoresSincronizacionTest extends TestCase
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

    private function deudor(array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'nombre' => 'Carlos Pérez',
            'telefono' => '310 456 7890',
            'autorizacion_en' => '2026-11-18T14:00:00.000Z',
            'anonimizado_en' => null,
        ], $cambios);
    }

    private function fiado(string $deudorId, array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'deudor_id' => $deudorId,
            'fecha' => '2026-11-05',
            'descripcion' => '1 arroba de arroz y 2 aceites',
            'valor' => 30000,
            'fecha_pago' => '2026-11-25',
            'anulado_en' => null,
            'motivo_anulacion' => null,
        ], $cambios);
    }

    private function abono(string $deudorId, array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'deudor_id' => $deudorId,
            'pago_id' => null,
            'fecha' => '2026-11-12',
            'valor' => 18000,
            'nota' => null,
            'anulado_en' => null,
            'motivo_anulacion' => null,
        ], $cambios);
    }

    private function sincronizar(array $cuerpo)
    {
        return $this->postJson('/api/sincronizar', $cuerpo)->assertOk();
    }

    public function test_guarda_el_deudor_con_su_fiado_y_su_abono_hechos_sin_internet(): void
    {
        $deudor = $this->deudor();
        $respuesta = $this->sincronizar([
            'deudores' => [$deudor],
            'fiados' => [$this->fiado($deudor['id'])],
            'abonos' => [$this->abono($deudor['id'])],
        ]);

        $guardado = Deudor::find($deudor['id']);
        $this->assertSame('3104567890', $guardado->telefono); // solo los números
        $this->assertNotNull($guardado->autorizacion_en); // RN-41
        $this->assertSame(30000, (int) Fiado::first()->valor);
        $this->assertSame(18000, (int) Abono::first()->valor);
        $respuesta->assertJsonCount(1, 'deudores')->assertJsonCount(1, 'fiados')->assertJsonCount(1, 'abonos');
    }

    public function test_dos_celulares_con_el_mismo_telefono_quedan_como_un_solo_deudor(): void
    {
        $primero = $this->deudor();
        $this->sincronizar(['deudores' => [$primero], 'fiados' => [$this->fiado($primero['id'])]]);

        // Otro celular, sin internet, creó a la misma persona con otro id y el teléfono escrito distinto
        $repetido = $this->deudor(['nombre' => 'Carlos', 'telefono' => '+57 310 4567890']);
        $respuesta = $this->sincronizar([
            'deudores' => [$repetido],
            'fiados' => [$this->fiado($repetido['id'], ['valor' => 12000])],
            'abonos' => [$this->abono($repetido['id'], ['valor' => 5000])],
        ]);

        $this->assertSame(1, Deudor::whereNull('unido_a')->count());
        $this->assertSame($primero['id'], Deudor::find($repetido['id'])->unido_a);
        $this->assertSame(2, Fiado::where('deudor_id', $primero['id'])->count());
        $this->assertSame(1, Abono::where('deudor_id', $primero['id'])->count());
        // El celular recibe el repetido con "unido_a" para pasar sus fiados al deudor correcto
        $this->assertContains($primero['id'], collect($respuesta->json('deudores'))->pluck('unido_a'));
    }

    public function test_un_fiado_que_llega_despues_con_el_id_del_repetido_tambien_se_pasa(): void
    {
        $primero = $this->deudor();
        $repetido = $this->deudor(['nombre' => 'Carlos']);
        $this->sincronizar(['deudores' => [$primero]]);
        $this->sincronizar(['deudores' => [$repetido]]);

        $this->sincronizar(['fiados' => [$this->fiado($repetido['id'])]]);

        $this->assertSame($primero['id'], Fiado::first()->deudor_id);
    }

    public function test_el_valor_de_un_fiado_no_se_cambia_solo_se_corrige_la_descripcion_o_se_anula(): void
    {
        $deudor = $this->deudor();
        $fiado = $this->fiado($deudor['id']);
        $this->sincronizar(['deudores' => [$deudor], 'fiados' => [$fiado]]);

        $this->sincronizar(['fiados' => [array_merge($fiado, [
            'valor' => 99000,
            'descripcion' => 'Arroz y aceite',
            'fecha_pago' => '2026-11-30',
            'anulado_en' => '2026-11-18T19:00:00.000Z',
            'motivo_anulacion' => 'Se anotó dos veces',
        ])]]);

        $guardado = Fiado::first();
        $this->assertSame(30000, (int) $guardado->valor); // RN-36
        $this->assertSame('Arroz y aceite', $guardado->descripcion); // RF-40
        $this->assertSame('2026-11-30', $guardado->fecha_pago->toDateString());
        $this->assertNotNull($guardado->anulado_en);
    }

    public function test_a_un_deudor_con_los_datos_borrados_no_se_le_vuelven_a_poner(): void
    {
        $deudor = $this->deudor();
        $this->sincronizar(['deudores' => [$deudor]]);
        $this->sincronizar(['deudores' => [array_merge($deudor, ['nombre' => null, 'telefono' => null, 'anonimizado_en' => '2026-11-18T19:00:00.000Z'])]]);

        // Un celular que no se había enterado manda un cambio viejo con el nombre
        $this->sincronizar(['deudores' => [array_merge($deudor, ['nombre' => 'Carlos P.'])]]);

        $guardado = Deudor::find($deudor['id']);
        $this->assertNull($guardado->nombre);
        $this->assertNull($guardado->telefono);
    }

    public function test_un_cambio_de_telefono_que_choca_con_otro_deudor_no_se_aplica(): void
    {
        $carlos = $this->deudor();
        $ana = $this->deudor(['nombre' => 'Ana Torres', 'telefono' => '3201112233']);
        $this->sincronizar(['deudores' => [$carlos, $ana]]);

        $this->sincronizar(['deudores' => [array_merge($ana, ['nombre' => 'Ana María Torres', 'telefono' => '3104567890'])]]);

        $guardada = Deudor::find($ana['id']);
        $this->assertSame('3201112233', $guardada->telefono);
        $this->assertSame('Ana María Torres', $guardada->nombre);
    }

    public function test_un_abono_por_nequi_queda_unido_a_su_pago(): void
    {
        $deudor = $this->deudor();
        $pagoId = (string) Str::uuid();
        $this->sincronizar([
            'deudores' => [$deudor],
            'fiados' => [$this->fiado($deudor['id'])],
            'pagos' => [[
                'id' => $pagoId, 'fecha' => '2026-11-18', 'hora' => '11:15', 'medio' => 'nequi', 'origen' => 'pantallazo',
                'estado' => 'confirmado', 'valor' => 18000, 'nombre_pagador' => 'Carlos Pérez', 'referencia' => 'M8423116',
                'anulado_en' => null, 'motivo_anulacion' => null,
            ]],
            'abonos' => [$this->abono($deudor['id'], ['pago_id' => $pagoId, 'fecha' => '2026-11-18', 'valor' => 12000])],
        ]);

        $this->assertSame($pagoId, Abono::first()->pago_id);
    }

    public function test_los_deudores_de_otro_negocio_no_se_ven_ni_se_tocan(): void
    {
        $deudor = $this->deudor();
        $this->sincronizar(['deudores' => [$deudor], 'fiados' => [$this->fiado($deudor['id'])]]);

        Sanctum::actingAs(User::factory()->create());
        $respuesta = $this->sincronizar([
            'deudores' => [array_merge($deudor, ['nombre' => 'Otro nombre'])],
            'abonos' => [$this->abono($deudor['id'])],
        ]);

        $respuesta->assertJsonCount(0, 'deudores')->assertJsonCount(0, 'fiados');
        $this->assertSame('Carlos Pérez', Deudor::withoutGlobalScopes()->find($deudor['id'])->nombre);
        $this->assertSame(0, Abono::withoutGlobalScopes()->count());
    }
}
