import { describe, expect, it } from 'vitest'
import {
  avisoBase,
  estadoDelDia,
  hoy,
  horaActual,
  leerPesos,
  mejorYPeor,
  mesVecino,
  pesos,
  pesosCortos,
  semanasDelMes,
  totalDelMes,
  totalesDelDia,
  valorEnMiles,
  vendidoEnEfectivo,
  type DatosCierre,
  type DatosPago,
} from './calculos'

const cierre = (cambios: Partial<DatosCierre> = {}): DatosCierre => ({
  tipo: 'normal',
  no_abrio: false,
  base: 100000,
  efectivo: 450000,
  salida_caja: 0,
  total_historico: null,
  ...cambios,
})

const pago = (cambios: Partial<DatosPago> = {}): DatosPago => ({
  medio: 'nequi',
  estado: 'confirmado',
  valor: 25000,
  anulado_en: null,
  ...cambios,
})

describe('pesos', () => {
  it('separa los miles con punto, incluso con 4 cifras', () => {
    expect(pesos(450000)).toBe('$450.000')
    expect(pesos(4500)).toBe('$4.500')
    expect(pesos(5635000)).toBe('$5.635.000')
    expect(pesos(0)).toBe('$0')
  })

  it('abrevia como lo dice la gente: "mil" y "millones"', () => {
    expect(pesosCortos(410000)).toBe('410 mil')
    expect(pesosCortos(5635000)).toBe('5,6 millones')
    expect(pesosCortos(2000000)).toBe('2 millones')
    expect(pesosCortos(1000000)).toBe('1 millón')
    expect(pesosCortos(999600)).toBe('1 millón') // no "1000 mil"
    expect(pesosCortos(800)).toBe('$800')
  })

  it('las casillas del calendario van en miles, con "mil" aparte', () => {
    expect(valorEnMiles(431000)).toEqual({ texto: '431', unidad: 'mil' })
    expect(valorEnMiles(1250400)).toEqual({ texto: '1.250', unidad: 'mil' })
    expect(valorEnMiles(800)).toEqual({ texto: '$800', unidad: '' })
  })

  it('lee lo que escribe el dueño', () => {
    expect(leerPesos('450.000')).toBe(450000)
    expect(leerPesos('$ 25.000')).toBe(25000)
    expect(leerPesos('')).toBeNull()
  })
})

describe('totales del día', () => {
  it('RN-02: lo vendido en efectivo suma la plata que sacó y resta la base', () => {
    expect(vendidoEnEfectivo(cierre())).toBe(350000)
    // Día 12 del prototipo: contado 370.000 + sacó 20.000 - base 100.000
    expect(vendidoEnEfectivo(cierre({ efectivo: 370000, salida_caja: 20000 }))).toBe(290000)
  })

  it('RN-03: total = efectivo + Nequi + otros pagos (cierre de hoy del prototipo)', () => {
    const pagos = [pago({ valor: 25000 }), pago({ valor: 18000 }), pago({ valor: 32000 })]
    expect(totalesDelDia(cierre(), pagos)).toEqual({ efectivo: 350000, nequi: 75000, otros: 0, sinDetalle: 0, total: 425000 })
  })

  it('separa los otros pagos y no cuenta los anulados ni los sin confirmar', () => {
    const pagos = [
      pago({ valor: 70000 }),
      pago({ medio: 'otro', valor: 20000 }),
      pago({ valor: 99000, anulado_en: '2026-11-12T10:00:00Z' }),
      pago({ valor: 5000, estado: 'por_confirmar' }),
    ]
    expect(totalesDelDia(cierre({ efectivo: 370000, salida_caja: 20000 }), pagos).total).toBe(380000)
  })

  it('RN-31: un día "No abrió" solo suma los pagos que llegaron', () => {
    expect(totalesDelDia(cierre({ no_abrio: true, base: null, efectivo: null }), [pago({ valor: 10000 })]).total).toBe(10000)
  })

  it('RN-06: avisa si el efectivo más lo que sacó es menor que la base', () => {
    expect(avisoBase(cierre({ efectivo: 50000 }))).toBe(true)
    expect(avisoBase(cierre({ efectivo: 50000, salida_caja: 60000 }))).toBe(false)
  })
})

describe('calendario', () => {
  it('noviembre de 2026 empieza en domingo (lunes primero)', () => {
    const semanas = semanasDelMes(2026, 11)
    expect(semanas[0]).toEqual([null, null, null, null, null, null, '2026-11-01'])
    expect(semanas[4]![6]).toBe('2026-11-29')
    expect(semanas[5]![0]).toBe('2026-11-30')
  })

  it('pasa de mes y de año', () => {
    expect(mesVecino(2026, 12, 1)).toEqual({ anio: 2027, mes: 1 })
    expect(mesVecino(2026, 1, -1)).toEqual({ anio: 2025, mes: 12 })
  })

  it('estado de cada día', () => {
    expect(estadoDelDia('2026-11-19', '2026-11-18', undefined)).toBe('futuro')
    expect(estadoDelDia('2026-11-08', '2026-11-18', cierre({ no_abrio: true }))).toBe('no_abrio')
    expect(estadoDelDia('2026-11-12', '2026-11-18', cierre())).toBe('cerrado')
    expect(estadoDelDia('2026-11-18', '2026-11-18', undefined)).toBe('sin_cierre')
  })

  it('RF-22: el mejor y el peor día solo entre días cerrados', () => {
    const dias = [
      { fecha: '2026-11-07', estado: 'cerrado' as const, total: 690000 },
      { fecha: '2026-11-15', estado: 'cerrado' as const, total: 160000 },
      { fecha: '2026-11-08', estado: 'no_abrio' as const, total: 0 },
      { fecha: '2026-11-18', estado: 'sin_cierre' as const, total: 0 },
    ]
    expect(mejorYPeor(dias)).toEqual({ mejor: '2026-11-07', peor: '2026-11-15' })
    expect(totalDelMes(dias)).toBe(850000)
  })
})

describe('hora de Colombia', () => {
  it('a las 2 a. m. UTC todavía es el día anterior en Colombia', () => {
    const momento = new Date('2026-11-19T02:00:00Z')
    expect(hoy(momento)).toBe('2026-11-18')
    expect(horaActual(momento)).toBe('21:00')
  })
})
