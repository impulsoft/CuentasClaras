<?php

namespace Tests\Feature;

use App\Models\Pago;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RF-17, RF-18, RN-05, RN-43
class ComprobanteTest extends TestCase
{
    use RefreshDatabase;

    private User $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo('2026-11-18 20:00:00');
        $this->dueno = User::factory()->create();
        Sanctum::actingAs($this->dueno);
    }

    private function ocrResponde(array $campos): void
    {
        Http::fake(['*/comprobante' => Http::response(['lineas' => [], 'campos' => $campos])]);
    }

    private function subir()
    {
        return $this->post('/api/comprobantes', ['foto' => UploadedFile::fake()->image('comprobante.jpg', 600, 900)], ['Accept' => 'application/json']);
    }

    public function test_guarda_la_foto_y_devuelve_lo_que_leyo_el_ocr(): void
    {
        $this->ocrResponde(['valor' => 18000, 'fecha' => '2026-11-18', 'hora' => '11:15', 'nombre' => 'Carlos Perez', 'referencia' => 'M1234567']);

        $respuesta = $this->subir()
            ->assertOk()
            ->assertJsonPath('leido', true)
            ->assertJsonPath('campos.valor', 18000)
            ->assertJsonPath('campos.referencia', 'M1234567')
            ->assertJsonPath('repetido', false);

        $ruta = $respuesta->json('foto_ruta');
        $this->assertStringStartsWith("comprobantes/{$this->dueno->negocio_id}/", $ruta);
        Storage::disk('local')->assertExists($ruta);
        Http::assertSent(fn ($pedido) => str_ends_with($pedido->url(), '/comprobante'));
    }

    public function test_si_el_ocr_no_responde_la_foto_queda_y_los_datos_van_vacios(): void
    {
        Http::fake(fn () => throw new ConnectionException('apagado'));

        $respuesta = $this->subir()
            ->assertOk()
            ->assertJsonPath('leido', false)
            ->assertJsonPath('campos.valor', null);

        Storage::disk('local')->assertExists($respuesta->json('foto_ruta'));
    }

    public function test_avisa_si_el_comprobante_ya_estaba_cargado(): void
    {
        Pago::create(['fecha' => '2026-11-18', 'medio' => 'nequi', 'origen' => 'foto', 'valor' => 18000, 'referencia' => 'M1234567']);
        $this->ocrResponde(['referencia' => 'M1234567']);

        $this->subir()->assertJsonPath('repetido', true);
    }

    public function test_un_pago_anulado_no_cuenta_como_repetido(): void
    {
        Pago::create(['fecha' => '2026-11-18', 'medio' => 'nequi', 'origen' => 'foto', 'valor' => 18000, 'referencia' => 'M1234567', 'anulado_en' => now(), 'motivo_anulacion' => 'Valor equivocado']);
        $this->ocrResponde(['referencia' => 'M1234567']);

        $this->subir()->assertJsonPath('repetido', false);
    }

    public function test_no_acepta_archivos_que_no_son_imagenes(): void
    {
        $this->post('/api/comprobantes', ['foto' => UploadedFile::fake()->create('notas.pdf', 10, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('foto');
    }

    public function test_el_admin_no_sube_comprobantes(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $this->subir()->assertForbidden();
    }

    public function test_la_sincronizacion_guarda_la_foto_y_la_fecha_de_borrado(): void
    {
        $id = (string) Str::uuid();
        $ruta = "comprobantes/{$this->dueno->negocio_id}/foto.jpg";

        $this->postJson('/api/sincronizar', ['desde' => null, 'cierres' => [], 'pagos' => [[
            'id' => $id, 'fecha' => '2026-11-18', 'hora' => '11:15', 'medio' => 'nequi', 'origen' => 'pantallazo', 'estado' => 'confirmado',
            'valor' => 18000, 'nombre_pagador' => 'Carlos Perez', 'referencia' => 'M1234567', 'foto_ruta' => $ruta,
            'anulado_en' => null, 'motivo_anulacion' => null,
        ]]])->assertOk();

        $pago = Pago::find($id);
        $this->assertSame($ruta, $pago->foto_ruta);
        $this->assertSame('2027-02-16', $pago->foto_borrar_el->toDateString()); // 90 días (RN-43)
    }

    public function test_la_sincronizacion_no_acepta_fotos_de_otro_negocio(): void
    {
        $id = (string) Str::uuid();

        $this->postJson('/api/sincronizar', ['desde' => null, 'cierres' => [], 'pagos' => [[
            'id' => $id, 'fecha' => '2026-11-18', 'hora' => null, 'medio' => 'nequi', 'origen' => 'foto', 'estado' => 'confirmado',
            'valor' => 5000, 'nombre_pagador' => null, 'referencia' => null, 'foto_ruta' => 'comprobantes/otro-negocio/foto.jpg',
            'anulado_en' => null, 'motivo_anulacion' => null,
        ]]])->assertOk();

        $this->assertNull(Pago::find($id)->foto_ruta);
    }

    public function test_borra_las_fotos_vencidas_y_conserva_los_datos(): void
    {
        $vieja = "comprobantes/{$this->dueno->negocio_id}/vieja.jpg";
        $nueva = "comprobantes/{$this->dueno->negocio_id}/nueva.jpg";
        Storage::disk('local')->put($vieja, 'foto');
        Storage::disk('local')->put($nueva, 'foto');
        $pagoViejo = Pago::create(['fecha' => '2026-08-01', 'medio' => 'nequi', 'origen' => 'foto', 'valor' => 9000, 'foto_ruta' => $vieja, 'foto_borrar_el' => '2026-10-30']);
        $pagoNuevo = Pago::create(['fecha' => '2026-11-18', 'medio' => 'nequi', 'origen' => 'foto', 'valor' => 7000, 'foto_ruta' => $nueva, 'foto_borrar_el' => '2027-02-16']);

        $this->artisan('comprobantes:borrar-fotos')->assertSuccessful();

        Storage::disk('local')->assertMissing($vieja);
        Storage::disk('local')->assertExists($nueva);
        $this->assertNull($pagoViejo->fresh()->foto_ruta);
        $this->assertSame(9000, $pagoViejo->fresh()->valor);
        $this->assertSame($nueva, $pagoNuevo->fresh()->foto_ruta);
    }

    public function test_las_fotos_que_no_son_de_un_pago_se_borran_a_los_7_dias(): void
    {
        $carpeta = "comprobantes/{$this->dueno->negocio_id}";
        $huerfana = "{$carpeta}/sin-confirmar.jpg";
        $reciente = "{$carpeta}/de-ayer.jpg";
        $deUnPago = "{$carpeta}/de-un-pago.jpg";
        foreach ([$huerfana => 8, $reciente => 1, $deUnPago => 30] as $ruta => $dias) {
            Storage::disk('local')->put($ruta, 'foto');
            touch(Storage::disk('local')->path($ruta), now()->subDays($dias)->getTimestamp());
        }
        Pago::create(['fecha' => '2026-10-19', 'medio' => 'nequi', 'origen' => 'foto', 'valor' => 9000, 'foto_ruta' => $deUnPago, 'foto_borrar_el' => '2027-01-17']);

        $this->artisan('comprobantes:borrar-fotos')->assertSuccessful();

        Storage::disk('local')->assertMissing($huerfana);
        Storage::disk('local')->assertExists($reciente); // puede estar esperando que el dueño la revise
        Storage::disk('local')->assertExists($deUnPago); // RN-43: 90 días
    }

    public function test_el_dueno_ve_la_foto_de_su_pago(): void
    {
        $ruta = "comprobantes/{$this->dueno->negocio_id}/".Str::uuid().'.jpg';
        Storage::disk('local')->put($ruta, 'imagen');

        $this->get('/api/comprobantes/foto?ruta='.urlencode($ruta))->assertOk();
        $this->getJson('/api/comprobantes/foto?ruta='.urlencode("comprobantes/{$this->dueno->negocio_id}/no-existe.jpg"))->assertNotFound();
    }

    public function test_no_se_puede_pedir_una_foto_de_otro_negocio_ni_salir_de_la_carpeta(): void
    {
        $otro = User::factory()->create();
        $ajena = "comprobantes/{$otro->negocio_id}/".Str::uuid().'.jpg';
        Storage::disk('local')->put($ajena, 'imagen');

        foreach ([$ajena, "comprobantes/{$this->dueno->negocio_id}/../{$otro->negocio_id}/x.jpg", '../.env', "comprobantes/{$this->dueno->negocio_id}/a.php"] as $ruta) {
            $this->getJson('/api/comprobantes/foto?ruta='.urlencode($ruta))->assertNotFound();
            $this->deleteJson('/api/comprobantes/foto?ruta='.urlencode($ruta))->assertNotFound();
        }
        Storage::disk('local')->assertExists($ajena);

        Sanctum::actingAs(User::factory()->admin()->create());
        $this->getJson('/api/comprobantes/foto?ruta='.urlencode($ajena))->assertForbidden(); // RN-26: el admin no ve comprobantes
    }

    public function test_descartar_borra_la_foto_pero_no_la_de_un_pago(): void
    {
        $carpeta = "comprobantes/{$this->dueno->negocio_id}";
        $descartada = "{$carpeta}/".Str::uuid().'.jpg';
        $deUnPago = "{$carpeta}/".Str::uuid().'.jpg';
        Storage::disk('local')->put($descartada, 'imagen');
        Storage::disk('local')->put($deUnPago, 'imagen');
        Pago::create(['fecha' => '2026-11-18', 'medio' => 'nequi', 'origen' => 'foto', 'valor' => 9000, 'foto_ruta' => $deUnPago]);

        $this->deleteJson('/api/comprobantes/foto?ruta='.urlencode($descartada))->assertNoContent();
        $this->deleteJson('/api/comprobantes/foto?ruta='.urlencode($deUnPago))->assertNoContent();

        Storage::disk('local')->assertMissing($descartada);
        Storage::disk('local')->assertExists($deUnPago);
    }
}
