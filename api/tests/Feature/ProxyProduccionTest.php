<?php

namespace Tests\Feature;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * En el servidor la API está detrás del proxy de Dokploy (Traefik), que agrega la IP real del
 * cliente al final de X-Forwarded-For. Confiando solo en las redes de Docker cada cliente tiene su propio
 * límite de intentos para entrar, y no se puede esquivar escribiendo otra IP en ese encabezado.
 */
class ProxyProduccionTest extends TestCase
{
    use RefreshDatabase;

    private const PROXY = '172.18.0.5';

    // El mismo valor de docker-compose.yml
    private const REDES_DOCKER = '10.0.0.0/8,172.16.0.0/12,192.168.0.0/16';

    public function createApplication(): Application
    {
        putenv('PROXIES_CONFIABLES='.self::REDES_DOCKER);
        $_ENV['PROXIES_CONFIABLES'] = $_SERVER['PROXIES_CONFIABLES'] = self::REDES_DOCKER;

        return parent::createApplication();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        putenv('PROXIES_CONFIABLES');
        unset($_ENV['PROXIES_CONFIABLES'], $_SERVER['PROXIES_CONFIABLES']);
    }

    private function entrarDesde(string $reenviadoPor)
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders(['X-Forwarded-For' => $reenviadoPor, 'X-Forwarded-Proto' => 'https'])
            ->postJson('/api/sesion', ['usuario' => 'nadie', 'contrasena' => 'equivocada']);
    }

    public function test_cada_cliente_detras_del_proxy_tiene_su_propio_limite(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->entrarDesde('181.50.1.1')->assertStatus(422);
        }
        $this->entrarDesde('181.50.1.1')->assertStatus(429);

        // Otra tienda que entra por el mismo proxy no queda bloqueada
        $this->entrarDesde('190.24.2.2')->assertStatus(422);
    }

    public function test_no_se_esquiva_el_limite_inventando_la_ip(): void
    {
        // El cliente escribe una IP distinta cada vez; el proxy agrega la real al final
        for ($i = 0; $i < 10; $i++) {
            $this->entrarDesde("10.0.0.{$i}, 181.50.1.1")->assertStatus(422);
        }
        $this->entrarDesde('10.0.0.99, 181.50.1.1')->assertStatus(429);
    }

    public function test_reconoce_que_el_pedido_llego_por_https(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => self::PROXY])
            ->withHeaders(['X-Forwarded-For' => '181.50.1.1', 'X-Forwarded-Proto' => 'https'])
            ->get('/up')
            ->assertOk();

        $this->assertTrue(request()->secure());
        $this->assertSame('181.50.1.1', request()->ip());
    }
}
