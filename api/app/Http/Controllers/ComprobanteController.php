<?php

namespace App\Http\Controllers;

use App\Models\Pago;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ComprobanteController extends Controller
{
    /*
     * RF-17: el dueño sube la foto o el pantallazo de un comprobante Nequi. La foto queda guardada
     * en el servidor (se borra a los 90 días, RN-43) y el OCR saca valor, fecha, hora, nombre y
     * referencia. Si el OCR no está disponible, la foto igual queda y el dueño escribe los datos (RF-18).
     */
    public function leer(Request $request)
    {
        $request->validate([
            'foto' => ['required', 'image', 'max:8192'],
        ], [
            'foto.required' => 'Falta la foto del comprobante.',
            'foto.image' => 'El archivo no es una imagen.',
            'foto.max' => 'La foto es muy pesada (máximo 8 MB).',
        ]);

        $negocioId = $request->user()->negocio_id;
        $extension = $request->file('foto')->extension() ?: 'jpg';
        $ruta = $request->file('foto')->storeAs("comprobantes/{$negocioId}", Str::uuid().'.'.$extension, 'local');

        $campos = ['valor' => null, 'fecha' => null, 'hora' => null, 'nombre' => null, 'referencia' => null];
        $leido = false;

        try {
            $respuesta = Http::timeout(config('services.ocr.espera'))
                ->attach('imagen', Storage::disk('local')->get($ruta), basename($ruta))
                ->post(rtrim(config('services.ocr.url'), '/').'/comprobante');

            if ($respuesta->successful()) {
                $campos = array_merge($campos, array_intersect_key($respuesta->json('campos') ?? [], $campos));
                $leido = true;
            }
        } catch (ConnectionException $e) {
            Log::warning('El servicio OCR no responde', ['error' => $e->getMessage()]);
        }

        return [
            'foto_ruta' => $ruta,
            'leido' => $leido,
            'campos' => $campos,
            'repetido' => $this->yaCargado($campos['referencia']),
        ];
    }

    // RN-43: el dueño puede ver la foto de un pago mientras se guarda (90 días). El admin no (rol:dueno).
    public function ver(Request $request)
    {
        $ruta = $this->rutaDelNegocio($request);
        abort_unless(Storage::disk('local')->exists($ruta), 404, 'La foto ya no está: se borra a los 90 días.');

        return response()->file(Storage::disk('local')->path($ruta), ['Cache-Control' => 'private, max-age=86400']);
    }

    // Foto descartada (no se volvió pago): se borra apenas el dueño la descarta, no a los 90 días
    public function borrar(Request $request)
    {
        $ruta = $this->rutaDelNegocio($request);
        if (! Pago::where('foto_ruta', $ruta)->exists()) {
            Storage::disk('local')->delete($ruta);
        }

        return response()->noContent();
    }

    // Solo una foto de la carpeta de este negocio, con un nombre de archivo simple (nada de "../")
    private function rutaDelNegocio(Request $request): string
    {
        $ruta = (string) $request->query('ruta', '');
        $negocioId = $request->user()->negocio_id;
        abort_unless(preg_match('#^comprobantes/'.preg_quote($negocioId, '#').'/[A-Za-z0-9-]+\.(jpe?g|png|webp|gif)$#', $ruta) === 1, 404);

        return $ruta;
    }

    // RN-05: el mismo comprobante no se carga dos veces (sin contar los pagos anulados)
    private function yaCargado(?string $referencia): bool
    {
        return $referencia !== null && Pago::where('referencia', $referencia)->whereNull('anulado_en')->exists();
    }
}
