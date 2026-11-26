<?php

use App\Models\Negocio;
use App\Models\Pago;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// RN-43: las fotos de los comprobantes se guardan 90 días; los datos leídos se conservan
Artisan::command('comprobantes:borrar-fotos', function () {
    $disco = Storage::disk('local');
    $borradas = 0;

    Pago::withoutGlobalScopes()
        ->whereNotNull('foto_ruta')
        ->whereDate('foto_borrar_el', '<=', today())
        ->each(function (Pago $pago) use ($disco, &$borradas) {
            $disco->delete($pago->foto_ruta);
            $pago->update(['foto_ruta' => null]);
            $borradas++;
        });

    // Fotos que nunca se volvieron pago (el dueño las descartó sin internet o nunca las revisó).
    // Son datos personales que no hace falta guardar: a los 7 días se borran, que es lo más que un
    // celular puede seguir registrando sin conectarse (RN-27). Las de un pago siguen la regla de los 90 días.
    $limite = now()->subDays(7)->getTimestamp();
    $deUnPago = Pago::withoutGlobalScopes()->whereNotNull('foto_ruta')->pluck('foto_ruta')->flip();
    foreach ($disco->allFiles('comprobantes') as $archivo) {
        if (! $deUnPago->has($archivo) && $disco->lastModified($archivo) < $limite) {
            $disco->delete($archivo);
            $borradas++;
        }
    }

    $this->info("Fotos borradas: {$borradas}");
})->purpose('Borra las fotos de comprobantes: las de un pago a los 90 días y las que no se usaron a los 7 (RN-43)');

Schedule::command('comprobantes:borrar-fotos')->dailyAt('03:00');

/*
 * RN-34 / RN-16: un negocio con 6 meses sin servicio (suscripción vencida, bloqueado o dueño
 * desactivado) pierde sus datos de forma definitiva: cierres, comprobantes, fiados y deudores.
 * Se conservan el negocio, la cuenta y el historial de pagos de la suscripción.
 * A los 5 meses el dueño y el admin ya ven el aviso con la fecha (Negocio::datosSeBorranEl).
 */
Artisan::command('negocios:borrar-datos-vencidos {--simular : Solo muestra qué negocios se borrarían}', function () {
    $borrados = 0;

    Negocio::with('dueno')->whereNull('datos_borrados_en')->each(function (Negocio $negocio) use (&$borrados) {
        $desde = $negocio->sinServicioDesde();
        if (! $desde || today()->lessThan($desde->copy()->addMonthsNoOverflow(Negocio::MESES_BORRADO))) {
            return;
        }

        $this->line("{$negocio->nombre}: sin servicio desde el {$desde->toDateString()}");
        if ($this->option('simular')) {
            return;
        }

        $negocio->borrarDatos(); // lo mismo que el retiro a pedido del dueño
        $borrados++;
    });

    $this->info($this->option('simular') ? 'Simulación: no se borró nada.' : "Negocios con datos borrados: {$borrados}");
})->purpose('Borra los datos de los negocios con 6 meses sin servicio (RN-34)');

Schedule::command('negocios:borrar-datos-vencidos')->dailyAt('03:30');
