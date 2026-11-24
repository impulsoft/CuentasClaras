<?php

namespace App\Support;

use App\Models\Abono;
use App\Models\Fiado;

/**
 * RF-44: lo que le deben a un negocio, sin nombres ni teléfonos de los deudores (RN-26).
 * Mismo cálculo que la app (app/src/deudas.ts): los abonos cubren primero el fiado más
 * antiguo (RN-28) y un deudor está vencido si algún fiado con saldo pasó su fecha (RN-23, RN-37).
 */
class ResumenDeudas
{
    /** @return array{por_cobrar: int, deudores: int, vencidos: int} */
    public static function deNegocio(string $negocioId): array
    {
        return self::deVarios([$negocioId])[$negocioId];
    }

    /**
     * Varios negocios con dos consultas en total (no dos por negocio), para la lista del admin (RNF-08).
     *
     * @param  list<string>  $negocioIds
     * @return array<string, array{por_cobrar: int, deudores: int, vencidos: int}>
     */
    public static function deVarios(array $negocioIds): array
    {
        $resumenes = array_fill_keys($negocioIds, ['por_cobrar' => 0, 'deudores' => 0, 'vencidos' => 0]);
        if (! $negocioIds) {
            return $resumenes;
        }

        $fiados = Fiado::withoutGlobalScopes()
            ->whereIn('negocio_id', $negocioIds)
            ->whereNull('anulado_en')
            ->orderBy('fecha')
            ->orderBy('id')
            ->get(['id', 'negocio_id', 'deudor_id', 'fecha', 'valor', 'fecha_pago'])
            ->groupBy('deudor_id');

        $abonado = Abono::withoutGlobalScopes()
            ->whereIn('negocio_id', $negocioIds)
            ->whereNull('anulado_en')
            ->groupBy('deudor_id')
            ->selectRaw('deudor_id, SUM(valor) AS total')
            ->pluck('total', 'deudor_id');

        $hoy = today()->toDateString();
        foreach ($fiados as $deudorId => $lista) {
            $disponible = (int) ($abonado[$deudorId] ?? 0);
            $saldo = 0;
            $vencido = false;
            foreach ($lista as $fiado) {
                $cubierto = min((int) $fiado->valor, $disponible);
                $disponible -= $cubierto;
                $falta = (int) $fiado->valor - $cubierto;
                if ($falta > 0) {
                    $saldo += $falta;
                    $vencido = $vencido || $fiado->fecha_pago->toDateString() < $hoy;
                }
            }
            if ($saldo > 0) {
                $negocioId = $lista->first()->negocio_id;
                $resumenes[$negocioId]['por_cobrar'] += $saldo;
                $resumenes[$negocioId]['deudores']++;
                $resumenes[$negocioId]['vencidos'] += $vencido ? 1 : 0;
            }
        }

        return $resumenes;
    }
}
