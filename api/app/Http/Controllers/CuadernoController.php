<?php

namespace App\Http\Controllers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * RF-28: foto de una página del cuaderno → días y totales para revisar (RF-29).
 * La foto no se guarda: se envía al OCR y se descarta (RN-43).
 */
class CuadernoController extends Controller
{
    public function leer(Request $request)
    {
        $request->validate(['foto' => ['required', 'image', 'max:8192']], [
            'foto.required' => 'Tome o escoja la foto de la página.',
            'foto.image' => 'El archivo debe ser una foto.',
            'foto.max' => 'La foto es muy grande (máximo 8 MB).',
        ]);
        $foto = $request->file('foto');

        try {
            $respuesta = Http::timeout(config('services.ocr.espera'))
                ->attach('imagen', file_get_contents($foto->getRealPath()), 'cuaderno.'.$foto->extension())
                ->post(rtrim(config('services.ocr.url'), '/').'/cuaderno');
        } catch (ConnectionException $e) {
            Log::warning('OCR del cuaderno sin respuesta', ['error' => $e->getMessage()]);

            return $this->sinLector();
        }

        if (! $respuesta->successful()) {
            return $this->sinLector();
        }

        return [
            'filas' => collect($respuesta->json('filas', []))->map(fn ($f) => [
                'mes' => $f['mes'] ?? null,
                'dia' => (int) $f['dia'],
                'total' => (int) $f['total'],
                'texto' => (string) ($f['texto'] ?? ''),
            ])->values(),
        ];
    }

    private function sinLector()
    {
        return response()->json([
            'codigo' => 'ocr_no_disponible',
            'message' => 'No se pudo leer la foto en este momento. Intente de nuevo en un rato.',
        ], 503);
    }
}
