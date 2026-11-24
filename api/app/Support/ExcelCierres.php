<?php

namespace App\Support;

use App\Models\Cierre;
use App\Models\Pago;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Reader\XLSX\Reader;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Throwable;

/**
 * RF-26 / RF-27: Excel de cierres. La plantilla para importar tiene las mismas columnas que el
 * archivo que se exporta, así el dueño puede exportar, corregir y volver a subir.
 */
class ExcelCierres
{
    public const COLUMNAS = [
        'Fecha',
        'Base inicial',
        'Efectivo contado',
        'Plata que sacó de la caja',
        'Vendido en efectivo',
        'Nequi',
        'Otros pagos',
        'Total vendido',
        'Nota',
    ];

    private const ANCHOS = [14, 14, 16, 16, 16, 14, 14, 16, 34];

    // RF-26: un día por fila, con la plata que sacó de la caja y los otros pagos
    public static function exportar(string $negocioId, string $desde, string $hasta, string $ruta): void
    {
        $cierres = Cierre::withoutGlobalScopes()->where('negocio_id', $negocioId)->whereBetween('fecha', [$desde, $hasta])->orderBy('fecha')->get();
        $pagos = Pago::withoutGlobalScopes()->where('negocio_id', $negocioId)->whereBetween('fecha', [$desde, $hasta])
            ->where('estado', 'confirmado')->whereNull('anulado_en')
            ->get()->groupBy(fn (Pago $p) => $p->fecha->toDateString());

        $writer = self::abrir($ruta, 'Cierres');
        $estilos = self::estilosColumnas();
        foreach ($cierres as $cierre) {
            $fecha = $cierre->fecha->toDateString();
            $delDia = $pagos->get($fecha, collect());
            $nequi = (int) $delDia->where('medio', 'nequi')->sum('valor');
            $otros = (int) $delDia->where('medio', 'otro')->sum('valor');

            if ($cierre->no_abrio) {
                $fila = [null, null, null, null, $nequi ?: null, $otros ?: null, $nequi + $otros ?: null, 'No abrió'];
            } elseif ($cierre->tipo === 'historico') {
                // RN-35: solo el total y, si se conoce, el Nequi
                $fila = [null, null, null, null, $cierre->nequi_historico, null, $cierre->total_historico, 'Del cuaderno o Excel (sin detalle)'];
            } else {
                $efectivo = (int) $cierre->efectivo + (int) $cierre->salida_caja - (int) $cierre->base; // RN-02
                $fila = [(int) $cierre->base, (int) $cierre->efectivo, (int) $cierre->salida_caja, $efectivo, $nequi, $otros, $efectivo + $nequi + $otros, $cierre->modificado_en ? 'Corregido' : null];
            }
            $writer->addRow(Row::fromValuesWithStyles([Carbon::parse($fecha)->toDateTimeImmutable(), ...$fila], $estilos));
        }
        $writer->close();
    }

    // RF-27: plantilla vacía con las mismas columnas y una hoja de instrucciones
    public static function plantilla(string $ruta): void
    {
        $writer = self::abrir($ruta, 'Cierres');
        $writer->addNewSheetAndMakeItCurrent()->setName('Cómo llenarla');
        $instrucciones = [
            'Cómo llenar la plantilla',
            '',
            'Escriba un día por fila en la hoja "Cierres". La fecha va como 18/11/2026.',
            'Si sabe la base y el efectivo contado de ese día, escríbalos: queda como un cierre completo.',
            'También puede escribir la plata que sacó de la caja, lo que entró por Nequi y los otros pagos.',
            'Si solo sabe el total del día (por ejemplo, del cuaderno), escriba solo la fecha y el "Total vendido".',
            'Las columnas "Vendido en efectivo" y "Total vendido" de un cierre completo las calcula el sistema.',
            'Si un día no abrió, escriba la fecha y en "Nota" escriba: No abrió.',
            'Antes de guardar, la app le muestra lo que leyó para que lo revise.',
        ];
        foreach ($instrucciones as $i => $texto) {
            $writer->addRow(Row::fromValuesWithStyle([$texto], new Style(fontBold: $i === 0, fontSize: $i === 0 ? 14 : 12)));
        }
        $writer->close();
    }

    /**
     * RF-27 / RF-29: lee el archivo y devuelve cada fila con sus datos y sus errores, sin guardar
     * nada (RN-08). Los días que ya tienen cierre los revisa la app, que conoce lo último del celular.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function leer(string $ruta): array
    {
        $reader = new Reader();
        $reader->open($ruta);
        $filas = [];
        $vistas = [];

        foreach ($reader->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $numero => $row) {
                $celdas = array_pad($row->toArray(), 9, null);
                if ($numero === 1 && is_string($celdas[0]) && str_contains(mb_strtolower($celdas[0]), 'fecha')) {
                    continue; // encabezados
                }
                if (! array_filter($celdas, fn ($c) => $c !== null && $c !== '')) {
                    continue; // fila vacía
                }
                $filas[] = self::fila($numero, $celdas, $vistas);
            }
            break; // solo la primera hoja
        }
        $reader->close();

        return $filas;
    }

    private static function fila(int $numero, array $c, array &$vistas): array
    {
        $errores = [];
        $fecha = self::fecha($c[0]);
        if (! $fecha) {
            $errores[] = 'La fecha no es válida.';
        } elseif ($fecha > today()->toDateString()) {
            $errores[] = 'La fecha es un día que no ha llegado.'; // RN-07
        } elseif (isset($vistas[$fecha])) {
            $errores[] = "Ese día está repetido en la fila {$vistas[$fecha]}.";
        }
        if ($fecha && ! isset($vistas[$fecha])) {
            $vistas[$fecha] = $numero;
        }

        [$base, $efectivo, $salida, , $nequi, $otros, $total] = array_map(self::pesos(...), array_slice($c, 1, 7));
        $nota = is_string($c[8]) ? trim($c[8]) : null;
        $noAbrio = $nota !== null && str_contains(self::sinTildes($nota), 'no abrio');

        if ($noAbrio) {
            $tipo = 'no_abrio';
        } elseif ($base !== null || $efectivo !== null) {
            $tipo = 'normal';
            if ($base === null || $efectivo === null) {
                $errores[] = 'Falta la base inicial o el efectivo contado.';
            }
        } elseif ($total !== null) {
            $tipo = 'historico'; // RN-35
        } else {
            $tipo = null;
            $errores[] = 'La fila no tiene valores.';
        }

        return [
            'fila' => $numero,
            'fecha' => $fecha,
            'tipo' => $tipo,
            'base' => $base,
            'efectivo' => $efectivo,
            'salida_caja' => $salida ?? 0,
            'nequi' => $nequi,
            'otros' => $otros,
            'total' => $tipo === 'historico' ? $total : null,
            'errores' => $errores,
        ];
    }

    // Fecha como celda de fecha, número de serie de Excel o texto (18/11/2026 o 2026-11-18)
    private static function fecha(mixed $valor): ?string
    {
        try {
            if ($valor instanceof DateTimeInterface) {
                return Carbon::instance($valor)->toDateString();
            }
            if (is_int($valor) || is_float($valor)) {
                return $valor > 20000 && $valor < 80000 ? Carbon::create(1899, 12, 30)->addDays((int) $valor)->toDateString() : null;
            }
            $texto = trim((string) $valor);
            if (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})$#', $texto, $m) && checkdate((int) $m[2], (int) $m[1], (int) $m[3])) {
                return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
            }
            if (preg_match('#^(\d{4})-(\d{1,2})-(\d{1,2})$#', $texto, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]);
            }
        } catch (Throwable) {
        }

        return null;
    }

    // Pesos enteros: 410000, "410.000", "$ 410.000"; vacío = null
    private static function pesos(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }
        if (is_int($valor) || is_float($valor)) {
            return max(0, (int) round($valor));
        }
        $digitos = preg_replace('/\D/', '', preg_replace('/,\d{1,2}$/', '', trim((string) $valor)));

        return $digitos === '' ? null : (int) $digitos;
    }

    private static function sinTildes(string $texto): string
    {
        return strtr(mb_strtolower($texto), ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u']);
    }

    // Fecha como 18/11/2026 y pesos como $410.000 (el separador lo pone Excel según el idioma del equipo)
    private static function estilosColumnas(): array
    {
        $pesos = new Style(format: '"$"#,##0');

        return [0 => new Style(format: 'dd/mm/yyyy'), 1 => $pesos, 2 => $pesos, 3 => $pesos, 4 => $pesos, 5 => $pesos, 6 => $pesos, 7 => $pesos];
    }

    private static function abrir(string $ruta, string $hoja): Writer
    {
        $opciones = new Options();
        foreach (self::ANCHOS as $i => $ancho) {
            $opciones->setColumnWidth($ancho, $i + 1);
        }
        $writer = new Writer($opciones);
        $writer->openToFile($ruta);
        $writer->getCurrentSheet()->setName($hoja);

        $negrita = new Style(fontBold: true, backgroundColor: 'E1F5EE');
        $writer->addRow(Row::fromValuesWithStyle(self::COLUMNAS, $negrita));

        return $writer;
    }
}
