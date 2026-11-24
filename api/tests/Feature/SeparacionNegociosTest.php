<?php

namespace Tests\Feature;

use App\Models\Cierre;
use App\Models\Deudor;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

// RNF-08, RN-10, RN-01, RN-32
class SeparacionNegociosTest extends TestCase
{
    use RefreshDatabase;

    public function test_cada_dueno_solo_ve_los_cierres_de_su_negocio(): void
    {
        $ana = User::factory()->create();
        $jose = User::factory()->create();

        Sanctum::actingAs($ana);
        Cierre::create(['fecha' => '2026-11-18', 'base' => 100000, 'efectivo' => 450000]);

        Sanctum::actingAs($jose);
        Cierre::create(['fecha' => '2026-11-18', 'base' => 50000, 'efectivo' => 200000]);

        $this->assertSame(1, Cierre::count());
        $this->assertSame($jose->negocio_id, Cierre::first()->negocio_id);
    }

    public function test_un_dueno_no_puede_guardar_en_otro_negocio(): void
    {
        $ana = User::factory()->create();
        $jose = User::factory()->create();
        Sanctum::actingAs($ana);

        $cierre = new Cierre(['fecha' => '2026-11-18']);
        $cierre->negocio_id = $jose->negocio_id;
        $cierre->save();

        $this->assertSame($ana->negocio_id, $cierre->fresh()->negocio_id);
    }

    public function test_el_admin_ve_los_cierres_de_todos_los_negocios(): void
    {
        foreach (User::factory()->count(2)->create() as $dueno) {
            Sanctum::actingAs($dueno);
            Cierre::create(['fecha' => '2026-11-18']);
        }

        Sanctum::actingAs(User::factory()->admin()->create());

        $this->assertSame(2, Cierre::count());
    }

    public function test_respeta_el_id_que_genera_el_celular(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $id = (string) Str::uuid();

        Cierre::create(['id' => $id, 'fecha' => '2026-11-18']);

        $this->assertTrue(Cierre::whereKey($id)->exists());
    }

    public function test_solo_un_cierre_por_dia(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Cierre::create(['fecha' => '2026-11-18']);

        $this->expectException(QueryException::class);
        Cierre::create(['fecha' => '2026-11-18']);
    }

    public function test_no_se_repite_el_telefono_de_un_deudor_en_el_mismo_negocio(): void
    {
        Sanctum::actingAs(User::factory()->create());
        Deudor::create(['nombre' => 'Carlos Pérez', 'telefono' => '3104567890']);

        $this->expectException(QueryException::class);
        Deudor::create(['nombre' => 'Carlos P.', 'telefono' => '3104567890']);
    }
}
