<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // En el servidor la API está detrás del proxy de Dokploy (Traefik). Sin esto Laravel ve la
        // IP del proxy en todos los pedidos: el límite de intentos para entrar sería uno solo para
        // todos los negocios y las direcciones saldrían con http. En producción son las redes internas
        // de Docker, donde vive el proxy (ver docker-compose.yml). No usar "*": Laravel confiaría en
        // cualquier IP del encabezado X-Forwarded-For y un cliente podría inventarse otra para
        // saltarse el límite de intentos.
        $middleware->trustProxies(at: env('PROXIES_CONFIABLES') ?: null);

        $middleware->alias([
            'cuenta.activa' => \App\Http\Middleware\CuentaActiva::class,
            'terminos' => \App\Http\Middleware\TerminosAceptados::class,
            'rol' => \App\Http\Middleware\TieneRol::class,
            'sesion.vigente' => \App\Http\Middleware\SesionVigente::class,
            'contrasena' => \App\Http\Middleware\ContrasenaAlDia::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
