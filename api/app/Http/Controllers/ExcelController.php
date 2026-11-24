<?php

namespace App\Http\Controllers;

use App\Models\Negocio;
use App\Support\ExcelCierres;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

// RF-26 / RF-27: Excel de cierres. Necesita internet (RNF-01).
class ExcelController extends Controller
{
    // RF-26: el dueño descarga sus cierres de un mes, un año o un rango de fechas
    public function exportar(Request $request)
    {
        return $this->descargar($request, $request->user()->negocio);
    }

    // RF-26: el admin también puede descargar los de cualquier negocio
    public function exportarAdmin(Request $request, Negocio $negocio)
    {
        return $this->descargar($request, $negocio);
    }

    // RF-27: plantilla con las mismas columnas del Excel que se exporta
    public function plantilla()
    {
        $ruta = $this->temporal();
        ExcelCierres::plantilla($ruta);

        return response()->download($ruta, 'plantilla-cierres.xlsx')->deleteFileAfterSend();
    }

    // RF-27 / RF-29: lee el archivo y devuelve las filas para revisar; no guarda nada (RN-08)
    public function leer(Request $request)
    {
        $request->validate(['archivo' => ['required', 'file', 'max:5120']], [
            'archivo.required' => 'Escoja el archivo de Excel.',
            'archivo.max' => 'El archivo es muy grande (máximo 5 MB).',
        ]);
        $archivo = $request->file('archivo');
        if (strtolower($archivo->getClientOriginalExtension()) !== 'xlsx') {
            return $this->noSePudo('El archivo debe ser de Excel (.xlsx). Use la plantilla.');
        }

        try {
            $filas = ExcelCierres::leer($archivo->getRealPath());
        } catch (Throwable) {
            return $this->noSePudo('No se pudo leer el archivo. Revise que sea la plantilla en formato .xlsx.');
        }

        if (! $filas) {
            return $this->noSePudo('El archivo no tiene filas con datos.');
        }
        if (count($filas) > 1000) {
            return $this->noSePudo('El archivo tiene más de 1.000 filas. Súbalo por partes (por ejemplo, un año a la vez).');
        }

        return ['filas' => $filas];
    }

    private function descargar(Request $request, Negocio $negocio)
    {
        $datos = $request->validate([
            'desde' => ['required', 'date_format:Y-m-d'],
            'hasta' => ['required', 'date_format:Y-m-d', 'after_or_equal:desde'],
        ], ['hasta.after_or_equal' => 'La fecha final no puede ser antes de la inicial.']);

        $ruta = $this->temporal();
        ExcelCierres::exportar($negocio->id, $datos['desde'], $datos['hasta'], $ruta);
        $nombre = 'cierres-'.Str::slug($negocio->nombre)."-{$datos['desde']}-a-{$datos['hasta']}.xlsx";

        return response()->download($ruta, $nombre)->deleteFileAfterSend();
    }

    // tempnam() crea un archivo vacío; se le cambia el nombre (no se crea otro) para que no quede
    // basura en la carpeta temporal. El .xlsx se borra después de enviarlo.
    private function temporal(): string
    {
        $base = tempnam(sys_get_temp_dir(), 'cierres');
        rename($base, $base.'.xlsx');

        return $base.'.xlsx';
    }

    private function noSePudo(string $mensaje)
    {
        return response()->json(['message' => $mensaje, 'errors' => ['archivo' => [$mensaje]]], 422);
    }
}
