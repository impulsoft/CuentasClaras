<?php

namespace Tests\Feature;

use App\Models\Cierre;
use App\Models\Pago;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

// RF-26 a RF-29, RN-07, RN-08, RN-35
class ExcelYCuadernoTest extends TestCase
{
    use RefreshDatabase;

    private User $dueno;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-18 10:00:00');
        $this->dueno = User::factory()->create();
        Sanctum::actingAs($this->dueno);
    }

    private function temporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'prueba');
        rename($base, $base.'.xlsx');

        return $this->temporales[] = $base.'.xlsx';
    }

    private function crear(string $modelo, array $datos): void
    {
        $modelo::withoutGlobalScopes()->forceCreate($datos + ['id' => (string) Str::uuid(), 'negocio_id' => $this->dueno->negocio_id]);
    }

    /** @return list<list<mixed>> */
    protected function tearDown(): void
    {
        foreach ($this->temporales as $ruta) {
            @unlink($ruta);
        }
        parent::tearDown();
    }

    /** @var list<string> */
    private array $temporales = [];

    private function filasDe(string $contenido): array
    {
        $ruta = $this->temporal();
        file_put_contents($ruta, $contenido);
        $reader = new Reader();
        $reader->open($ruta);
        $filas = [];
        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $row) {
                $filas[] = $row->toArray();
            }
            break;
        }
        $reader->close();
        unlink($ruta);

        return $filas;
    }

    private function excel(array $filas): UploadedFile
    {
        $ruta = $this->temporal();
        $writer = new Writer();
        $writer->openToFile($ruta);
        foreach ($filas as $fila) {
            $writer->addRow(Row::fromValues($fila));
        }
        $writer->close();

        return new UploadedFile($ruta, 'cierres.xlsx', null, null, true);
    }

    public function test_exporta_cada_dia_con_la_plata_que_saco_y_los_otros_pagos(): void
    {
        $this->crear(Cierre::class, ['fecha' => '2026-11-17', 'base' => 100000, 'efectivo' => 400000, 'salida_caja' => 50000]);
        $this->crear(Cierre::class, ['fecha' => '2026-11-10', 'tipo' => 'historico', 'total_historico' => 380000, 'nequi_historico' => 60000]);
        $this->crear(Pago::class, ['fecha' => '2026-11-17', 'medio' => 'nequi', 'origen' => 'manual', 'valor' => 20000]);
        $this->crear(Pago::class, ['fecha' => '2026-11-17', 'medio' => 'otro', 'origen' => 'manual', 'valor' => 15000]);
        $this->crear(Pago::class, ['fecha' => '2026-11-17', 'medio' => 'nequi', 'origen' => 'manual', 'valor' => 99000, 'anulado_en' => now(), 'motivo_anulacion' => 'x']);

        $respuesta = $this->get('/api/excel/cierres?desde=2026-11-01&hasta=2026-11-30')->assertOk();
        $filas = $this->filasDe($respuesta->streamedContent());

        $this->assertSame('Fecha', $filas[0][0]);
        $this->assertSame('Plata que sacó de la caja', $filas[0][3]);
        // Ordenados por fecha: primero el histórico, después el normal
        $this->assertSame(['', '', '', '', 60000, '', 380000, 'Del cuaderno o Excel (sin detalle)'], array_slice($filas[1], 1)); // las celdas vacías se leen como ''
        $this->assertSame([100000, 400000, 50000, 350000, 20000, 15000, 385000], array_slice($filas[2], 1, 7)); // RN-02, RN-03
        $this->assertInstanceOf(DateTimeImmutable::class, $filas[2][0]);
    }

    public function test_descargar_no_deja_archivos_vacios_en_la_carpeta_temporal(): void
    {
        // En Windows tempnam() usa solo las 3 primeras letras: "cieXXXX.tmp"
        $vacios = fn () => array_values(array_filter(glob(sys_get_temp_dir().DIRECTORY_SEPARATOR.'cie*.tmp') ?: [], fn ($f) => filesize($f) === 0));
        $antes = $vacios();
        $excel = $this->get('/api/excel/cierres?desde=2026-11-01&hasta=2026-11-30')->assertOk();
        $plantilla = $this->get('/api/excel/plantilla')->assertOk();

        $this->assertSame($antes, $vacios());
        // En producción el .xlsx se borra al terminar de enviarlo; aquí se limpia a mano
        foreach ([$excel, $plantilla] as $respuesta) {
            @unlink($respuesta->baseResponse->getFile()->getPathname());
        }
    }

    public function test_la_plantilla_tiene_las_mismas_columnas(): void
    {
        $filas = $this->filasDe($this->get('/api/excel/plantilla')->assertOk()->streamedContent());
        $this->assertSame(['Fecha', 'Base inicial', 'Efectivo contado', 'Plata que sacó de la caja', 'Vendido en efectivo', 'Nequi', 'Otros pagos', 'Total vendido', 'Nota'], $filas[0]);
    }

    public function test_lee_el_excel_y_marca_los_errores_sin_guardar_nada(): void
    {
        $archivo = $this->excel([
            ['Fecha', 'Base inicial', 'Efectivo contado', 'Plata que sacó de la caja', 'Vendido en efectivo', 'Nequi', 'Otros pagos', 'Total vendido', 'Nota'],
            [new DateTimeImmutable('2026-11-01'), 100000, 450000, 20000, null, 30000, null, null, null], // normal
            ['02/11/2026', null, null, null, null, 50000, null, '$ 410.000', null], // histórico (RN-35)
            ['2026-11-03', null, null, null, null, null, null, null, 'No abrió'],
            ['31/02/2026', null, null, null, null, null, null, 300000, null], // fecha inválida
            ['25/12/2026', null, null, null, null, null, null, 300000, null], // futura (RN-07)
            ['01/11/2026', null, null, null, null, null, null, 300000, null], // repetida
            ['04/11/2026', 100000, null, null, null, null, null, null, null], // falta el efectivo
        ]);

        $filas = $this->post('/api/excel/leer', ['archivo' => $archivo])->assertOk()->json('filas');

        $this->assertSame(['fecha' => '2026-11-01', 'tipo' => 'normal', 'base' => 100000, 'efectivo' => 450000, 'salida_caja' => 20000, 'nequi' => 30000, 'errores' => []],
            array_intersect_key($filas[0], array_flip(['fecha', 'tipo', 'base', 'efectivo', 'salida_caja', 'nequi', 'errores'])));
        $this->assertSame(['historico', 410000, 50000], [$filas[1]['tipo'], $filas[1]['total'], $filas[1]['nequi']]);
        $this->assertSame('no_abrio', $filas[2]['tipo']);
        $this->assertSame(['La fecha no es válida.'], $filas[3]['errores']);
        $this->assertSame(['La fecha es un día que no ha llegado.'], $filas[4]['errores']);
        $this->assertSame(['Ese día está repetido en la fila 2.'], $filas[5]['errores']);
        $this->assertSame(['Falta la base inicial o el efectivo contado.'], $filas[6]['errores']);
        $this->assertSame(0, Cierre::withoutGlobalScopes()->count()); // RN-08
    }

    public function test_un_archivo_que_no_es_excel_se_rechaza_con_un_mensaje_claro(): void
    {
        $this->post('/api/excel/leer', ['archivo' => UploadedFile::fake()->create('cierres.pdf', 10)], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonPath('errors.archivo.0', 'El archivo debe ser de Excel (.xlsx). Use la plantilla.');
    }

    public function test_el_cuaderno_devuelve_los_dias_leidos_y_no_guarda_la_foto(): void
    {
        Http::fake(['*/cuaderno' => Http::response(['renglones' => [], 'filas' => [
            ['mes' => 11, 'dia' => 1, 'total' => 410000, 'texto' => '1 410.000'],
            ['mes' => null, 'dia' => 2, 'total' => 385000, 'texto' => '2 385.000'],
        ]])]);

        $this->post('/api/cuaderno', ['foto' => UploadedFile::fake()->image('pagina.jpg')])
            ->assertOk()
            ->assertJsonPath('filas.0', ['mes' => 11, 'dia' => 1, 'total' => 410000, 'texto' => '1 410.000'])
            ->assertJsonPath('filas.1.mes', null);
        $this->assertSame([], \Storage::disk('local')->allFiles('cuaderno'));
    }

    public function test_si_el_lector_no_responde_avisa(): void
    {
        Http::fake(['*/cuaderno' => Http::response('error', 500)]);
        $this->post('/api/cuaderno', ['foto' => UploadedFile::fake()->image('pagina.jpg')], ['Accept' => 'application/json'])
            ->assertStatus(503)->assertJsonPath('codigo', 'ocr_no_disponible');
    }

    public function test_el_admin_descarga_el_excel_de_un_negocio_y_ve_un_rango_de_fechas(): void
    {
        $this->crear(Cierre::class, ['fecha' => '2025-12-31', 'base' => 1, 'efectivo' => 2]);
        $this->crear(Cierre::class, ['fecha' => '2026-11-17', 'base' => 100000, 'efectivo' => 400000]);
        Sanctum::actingAs(User::factory()->admin()->create());
        $id = $this->dueno->negocio_id;

        $this->get("/api/admin/negocios/{$id}/excel?desde=2026-01-01&hasta=2026-12-31")->assertOk()->streamedContent(); // al enviarlo se borra el temporal
        $this->getJson("/api/admin/negocios/{$id}/mes?desde=2025-01-01&hasta=2026-11-18")->assertOk()->assertJsonCount(2, 'cierres');
    }
}
