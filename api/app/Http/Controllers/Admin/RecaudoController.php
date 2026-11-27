<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PagoSuscripcion;
use App\Support\ExcelPagosSuscripcion;
use Illuminate\Http\Request;

// Lo que ha entrado por las suscripciones: mes a mes y en Excel (contabilidad del servicio)
class RecaudoController extends Controller
{
    // Los últimos meses (12 por defecto), del más viejo al actual; los meses sin pagos van en 0
    public function index(Request $request)
    {
        $request->validate(['meses' => ['nullable', 'integer', 'min:1', 'max:36']]);
        $cantidad = (int) $request->input('meses', 12);
        $desde = today()->startOfMonth()->subMonthsNoOverflow($cantidad - 1);

        // Se agrupa en PHP: son pocos pagos al mes y así funciona igual en MySQL y en SQLite
        $porMes = PagoSuscripcion::vigentes()
            ->where('fecha', '>=', $desde->toDateString())
            ->get(['fecha', 'valor'])
            ->groupBy(fn (PagoSuscripcion $p) => $p->fecha->format('Y-m'));

        $meses = [];
        for ($mes = $desde->copy(); $mes->lessThanOrEqualTo(today()); $mes->addMonthNoOverflow()) {
            $pagos = $porMes->get($mes->format('Y-m'), collect());
            $meses[] = ['mes' => $mes->format('Y-m'), 'total' => (int) $pagos->sum('valor'), 'pagos' => $pagos->where('valor', '>', 0)->count()]; // los meses gratis no son pagos
        }

        return ['meses' => $meses, 'total' => array_sum(array_column($meses, 'total'))];
    }

    public function excel(Request $request)
    {
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], ['hasta.after_or_equal' => 'La fecha final no puede ser antes de la inicial.']);

        // tempnam() crea un archivo vacío; se le cambia el nombre para no dejar basura. Se borra al enviarlo.
        $base = tempnam(sys_get_temp_dir(), 'pagos');
        rename($base, $base.'.xlsx');
        $ruta = $base.'.xlsx';
        ExcelPagosSuscripcion::exportar($datos['desde'], $datos['hasta'], $ruta);

        return response()->download($ruta, "pagos-suscripcion-{$datos['desde']}-a-{$datos['hasta']}.xlsx")->deleteFileAfterSend();
    }
}
