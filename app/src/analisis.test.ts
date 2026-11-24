import { describe, expect, it } from 'vitest'
import { analisisAnual, analisisDiario, analisisMensual, compararAnios, diasConCierre, porcentaje, porMedio, textoMeses, type CierreAnalisis, type PagoAnalisis } from './analisis'

const HOY = '2026-11-18'
const normal = (fecha: string, efectivo: number): CierreAnalisis => ({
  fecha,
  tipo: 'normal',
  no_abrio: false,
  base: 100000,
  efectivo: 100000 + efectivo,
  salida_caja: 0,
  total_historico: null,
})
const historico = (fecha: string, total: number, nequi: number | null = null): CierreAnalisis => ({
  fecha,
  tipo: 'historico',
  no_abrio: false,
  base: null,
  efectivo: null,
  salida_caja: 0,
  total_historico: total,
  nequi_historico: nequi,
})
const pago = (fecha: string, medio: 'nequi' | 'otro', valor: number, anulado = false): PagoAnalisis => ({
  fecha,
  medio,
  estado: 'confirmado',
  valor,
  anulado_en: anulado ? '2026-11-18T10:00:00Z' : null,
})

describe('análisis diario (RF-22, RF-25)', () => {
  const cierres = [normal('2026-11-02', 300000), normal('2026-11-03', 200000), historico('2026-11-04', 400000, 100000), { ...normal('2026-11-05', 0), no_abrio: true }]
  const pagos = [pago('2026-11-02', 'nequi', 50000), pago('2026-11-03', 'otro', 10000), pago('2026-11-05', 'nequi', 20000), pago('2026-11-06', 'nequi', 99000), pago('2026-11-02', 'nequi', 7000, true)]
  const dias = diasConCierre(cierres, pagos, HOY)

  it('suma cada día por medio de pago y separa lo "sin detalle" de los históricos (RN-35)', () => {
    const a = analisisDiario(dias, 2026, 11, HOY)
    expect(a.desglose).toEqual({ efectivo: 500000, nequi: 170000, otros: 10000, sinDetalle: 300000, total: 980000 })
    expect(a.dias.find((d) => d.fecha === '2026-11-06')?.total).toBe(0) // sin cierre: su pago entra cuando cierre (RN-30)
    expect(a.dias.find((d) => d.fecha === '2026-11-05')?.total).toBe(20000) // "No abrió": cuenta su pago (RN-31)
  })

  it('el día de menor venta no cuenta los días sin cierre ni los "No abrió"', () => {
    const a = analisisDiario(dias, 2026, 11, HOY)
    expect(a.mejor).toBe('2026-11-04')
    expect(a.peor).toBe('2026-11-03')
  })
})

describe('análisis mensual y anual (RF-23, RF-24)', () => {
  const cierres = [
    normal('2025-11-10', 1000000),
    normal('2026-08-10', 900000),
    normal('2026-09-10', 300000),
    normal('2026-10-10', 500000),
    normal('2026-11-10', 100000), // mes en curso: no cuenta para el de menor venta
  ]
  const dias = diasConCierre(cierres, [], HOY)

  it('muestra los 12 meses con el de mayor y el de menor venta', () => {
    const a = analisisMensual(dias, 2026, HOY)
    expect(a.meses).toHaveLength(12)
    expect(a.meses[9]!.desglose.total).toBe(500000)
    expect(a.mejor).toBe('2026-08')
    expect(a.peor).toBe('2026-09')
    expect(a.desglose.total).toBe(1800000)
  })

  it('compara cada año con el anterior usando solo los meses que tienen los dos', () => {
    // 2025 solo tiene noviembre: el cambio de 2026 es noviembre contra noviembre, no el año entero
    const anios = analisisAnual(dias, HOY)
    expect(anios.map((a) => [a.clave, a.desglose.total, a.cambio])).toEqual([
      ['2025', 1000000, null],
      ['2026', 1800000, -0.9],
    ])
    expect(anios[1]!.periodo).toEqual({ meses: [11], hastaDia: 18 })
  })
})

describe('porcentajes por medio de pago (RF-25)', () => {
  it('solo los medios con valor, con su porcentaje', () => {
    const medios = porMedio({ efectivo: 750000, nequi: 250000, otros: 0, sinDetalle: 0, total: 1000000 })
    expect(medios.map((m) => [m.nombre, m.porcentaje])).toEqual([
      ['Efectivo', 0.75],
      ['Nequi', 0.25],
    ])
    expect(porcentaje(0.125)).toBe('12,5 %')
    expect(porcentaje(1 / 3)).toBe('33,3 %')
  })
})

describe('comparar dos años (RF-24)', () => {
  it('pone dos años cualesquiera lado a lado, con el cambio', () => {
    const dias = diasConCierre([normal('2024-03-01', 500000), historico('2025-03-01', 800000), normal('2026-03-01', 600000)], [], HOY)
    const { filas, periodo } = compararAnios(dias, '2024', '2026', HOY)
    expect(filas.map((f) => [f.nombre, f.antes, f.despues, f.cambio])).toEqual([
      ['Total vendido', 500000, 600000, 0.2],
      ['Efectivo', 500000, 600000, 0.2],
    ])
    expect(periodo).toEqual({ meses: [3], hastaDia: null })
  })

  it('un año con 4 meses cargados contra otro con 11 compara solo los meses que tienen los dos', () => {
    // 2025: septiembre a diciembre. 2026: enero a noviembre (hoy es 18 de noviembre)
    const cierres = [
      ...[9, 10, 11, 12].map((m) => historico(`2025-${String(m).padStart(2, '0')}-10`, 1000000)),
      historico('2025-11-25', 1000000), // después del 18: no cuenta, en 2026 noviembre va hasta el 18
      ...[1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11].map((m) => historico(`2026-${String(m).padStart(2, '0')}-10`, 1100000)),
    ]
    const { filas, periodo } = compararAnios(diasConCierre(cierres, [], HOY), '2025', '2026', HOY)

    expect(periodo).toEqual({ meses: [9, 10, 11], hastaDia: 18 })
    expect(filas[0]).toMatchObject({ nombre: 'Total vendido', antes: 3000000, despues: 3300000 })
    expect(filas[0]!.cambio).toBeCloseTo(0.1) // +10 %, no +263 % del año entero
  })

  it('si un año viene del cuaderno (solo totales), no compara por medio de pago (RN-35)', () => {
    const dias = diasConCierre([historico('2025-03-10', 800000), normal('2026-03-10', 600000)], [pago('2026-03-10', 'nequi', 100000)], HOY)
    const { filas, soloTotal } = compararAnios(dias, '2025', '2026', HOY)
    expect(soloTotal).toBe(true)
    expect(filas.map((f) => f.nombre)).toEqual(['Total vendido'])
  })

  it('sin meses en común no hay comparación', () => {
    const dias = diasConCierre([normal('2024-03-01', 500000), normal('2025-07-01', 600000)], [], HOY)
    expect(compararAnios(dias, '2024', '2025', HOY).filas).toEqual([])
  })

  it('nombra los meses comparados', () => {
    expect(textoMeses([9, 10, 11])).toBe('septiembre a noviembre')
    expect(textoMeses([9, 10, 11], true)).toBe('sep a nov')
    expect(textoMeses([3, 5])).toBe('marzo y mayo')
    expect(textoMeses([1, 3, 5])).toBe('enero, marzo y mayo')
    expect(textoMeses([11])).toBe('noviembre')
    expect(textoMeses(Array.from({ length: 12 }, (_, i) => i + 1))).toBe('todo el año')
  })
})
