<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// La API crea sola la cuenta del admin al arrancar en el servidor (docker/iniciar.sh)
class CuentaAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['cierre.admin.usuario' => 'admin', 'cierre.admin.contrasena' => 'clave-del-servidor-1']);
    }

    private function entrar(string $contrasena)
    {
        return $this->postJson('/api/sesion', ['usuario' => 'admin', 'contrasena' => $contrasena]);
    }

    public function test_crea_el_admin_si_no_existe_y_puede_entrar(): void
    {
        $this->artisan('cierre:admin')->expectsOutputToContain('Se creó el admin')->assertSuccessful();

        $this->entrar('clave-del-servidor-1')->assertOk()->assertJsonPath('usuario.rol', 'admin');
    }

    public function test_si_ya_existe_no_le_cambia_la_contrasena_que_el_puso(): void
    {
        $this->artisan('cierre:admin')->assertSuccessful();
        User::where('usuario', 'admin')->first()->update(['password' => 'la-que-cambio-el-admin']);

        $this->artisan('cierre:admin')->expectsOutputToContain('ya existe')->assertSuccessful();

        $this->entrar('la-que-cambio-el-admin')->assertOk();
        $this->assertSame(1, User::where('rol', User::ADMIN)->count());
    }

    public function test_restablecer_le_devuelve_la_contrasena_de_las_variables(): void
    {
        $this->artisan('cierre:admin')->assertSuccessful();
        $admin = User::where('usuario', 'admin')->first();
        $admin->update(['password' => 'una-que-olvido', 'activo' => false]);
        $admin->createToken('celular');

        $this->artisan('cierre:admin --restablecer')->expectsOutputToContain('Listo')->assertSuccessful();

        $this->assertSame(0, $admin->tokens()->count());
        $this->entrar('clave-del-servidor-1')->assertOk();
    }

    public function test_no_toca_a_un_dueno_con_ese_usuario_ni_funciona_sin_contrasena(): void
    {
        User::factory()->create(['usuario' => 'admin']);
        $this->artisan('cierre:admin --restablecer')->expectsOutputToContain('es de un dueño')->assertFailed();

        config(['cierre.admin.contrasena' => null]);
        $this->artisan('cierre:admin')->expectsOutputToContain('Falta ADMIN_USUARIO o ADMIN_CONTRASENA')->assertFailed();
    }
}
