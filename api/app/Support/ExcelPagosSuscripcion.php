<?php

namespace App\Support;

use App\Models\PagoSuscripcion;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel de los pagos de la suscripción (contabilidad del servicio): un pago por fila.
 * Los anulados aparecen con su motivo, pero sin valor, para que la suma de la columna dé lo recaudado.
 */
class ExcelPagosSuscripcion
{
    public const COLUMNAS = ['Fecha', 'Negocio', 'Valor', 'Cubre hasta', 'Nota', 'Anulado'];

    private const ANCHOS = [14, 30, 14, 14, 30, 44];

    public static function exportar(string $desde, string $hasta, string $ruta): void
    {
        $pagos = PagoSuscripcion::with('negocio:id,nombre')
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderBy('fecha')->orderBy('id')
            ->get();

        $opciones = new Options();
        foreach (self::ANCHOS as $i => $ancho) {
            $opciones->setColumnWidth($ancho, $i + 1);
        }
        $writer = new Writer($opciones);
        $writer->openToFile($ruta);
        $writer->getCurrentSheet()->setName('Pagos');
        $writer->addRow(Row::fromValuesWithStyle(self::COLUMNAS, new Style(fontBold: true, backgroundColor: 'E1F5EE')));

        // Fecha como 18/11/2026 y pesos como $30.000 (el separador lo pone Excel según el idioma del equipo)
        $fecha = new Style(format: 'dd/mm/yyyy');
        $estilos = [0 => $fecha, 2 => new Style(format: '"$"#,##0'), 3 => $fecha];

        foreach ($pagos as $pago) {
            $anulado = $pago->anulado_en
                ? 'Sí, el '.$pago->anulado_en->format('d/m/Y').' ($'.number_format($pago->valor, 0, ',', '.').'): '.$pago->motivo_anulacion
                : null;
            $writer->addRow(Row::fromValuesWithStyles([
                $pago->fecha->toDateTimeImmutable(),
                $pago->negocio?->nombre,
                $anulado ? null : $pago->valor,
                $anulado ? null : $pago->vence_el->toDateTimeImmutable(),
                $pago->nota,
                $anulado,
            ], $estilos));
        }
        $writer->close();
    }
}
