<?php

use App\Http\Controllers\Admin;
use App\Http\Controllers\ComprobanteController;
use App\Http\Controllers\ContrasenaController;
use App\Http\Controllers\CuadernoController;
use App\Http\Controllers\ExcelController;
use App\Http\Controllers\NegocioController;
use App\Http\Controllers\SesionController;
use App\Http\Controllers\SincronizacionController;
use App\Http\Controllers\TerminosController;
use Illuminate\Support\Facades\Route;

// Para comprobar desde la app que el servidor responde
Route::get('/estado', function () {
    return [
        'app' => config('app.name'),
        'hora' => now()->toIso8601String(),
    ];
});

Route::post('/sesion', [SesionController::class, 'iniciar'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'cuenta.activa', 'sesion.vigente'])->group(function () {
    Route::get('/yo', [SesionController::class, 'yo']);
    Route::delete('/sesion', [SesionController::class, 'cerrar']);
    Route::post('/contrasena', [ContrasenaController::class, 'cambiar'])->middleware('throttle:10,1'); // RF-47

    // Dueño
    Route::post('/terminos', [TerminosController::class, 'aceptar'])->middleware(['rol:dueno', 'contrasena']);

    Route::middleware(['rol:dueno', 'contrasena', 'terminos'])->group(function () {
        Route::get('/negocio', [NegocioController::class, 'mostrar']);
        Route::post('/sincronizar', [SincronizacionController::class, 'sincronizar'])->name('sincronizar');
        Route::post('/comprobantes', [ComprobanteController::class, 'leer'])->middleware('throttle:60,1');
        Route::get('/comprobantes/foto', [ComprobanteController::class, 'ver']); // ?ruta=
        Route::delete('/comprobantes/foto', [ComprobanteController::class, 'borrar']); // ?ruta=

        // Excel (RF-26, RF-27) y cuaderno (RF-28). Exportar se permite aunque el negocio esté bloqueado (RN-13)
        Route::get('/excel/cierres', [ExcelController::class, 'exportar']);
        Route::get('/excel/plantilla', [ExcelController::class, 'plantilla']);
        Route::post('/excel/leer', [ExcelController::class, 'leer'])->middleware('throttle:20,1');
        Route::post('/cuaderno', [CuadernoController::class, 'leer'])->middleware('throttle:30,1');
    });

    // Admin
    Route::middleware('rol:admin')->prefix('admin')->group(function () {
        Route::get('/resumen', [Admin\NegocioController::class, 'resumen']);
        Route::get('/recaudo', [Admin\RecaudoController::class, 'index']);
        Route::get('/recaudo/excel', [Admin\RecaudoController::class, 'excel']);
        Route::get('/negocios', [Admin\NegocioController::class, 'index']);
        Route::post('/negocios', [Admin\NegocioController::class, 'store']);
        Route::get('/negocios/{negocio}', [Admin\NegocioController::class, 'show']);
        Route::put('/negocios/{negocio}', [Admin\NegocioController::class, 'update']);
        Route::post('/negocios/{negocio}/pagos', [Admin\NegocioController::class, 'registrarPago']);
        Route::post('/negocios/{negocio}/pagos/{pago}/anular', [Admin\NegocioController::class, 'anularPago']);
        Route::post('/negocios/{negocio}/retirar', [Admin\NegocioController::class, 'retirar']);
        Route::post('/negocios/{negocio}/bloquear', [Admin\NegocioController::class, 'bloquear']);
        Route::post('/negocios/{negocio}/desbloquear', [Admin\NegocioController::class, 'desbloquear']);
        Route::post('/negocios/{negocio}/desactivar', [Admin\NegocioController::class, 'desactivar']);
        Route::post('/negocios/{negocio}/activar', [Admin\NegocioController::class, 'activar']);
        Route::post('/negocios/{negocio}/contrasena', [Admin\NegocioController::class, 'restablecerContrasena']);
        Route::get('/negocios/{negocio}/mes', [Admin\NegocioController::class, 'mes']);
        Route::get('/negocios/{negocio}/excel', [ExcelController::class, 'exportarAdmin']);
    });
});
