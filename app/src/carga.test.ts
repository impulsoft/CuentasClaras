import 'fake-indexeddb/auto'
import { beforeEach, describe, expect, it } from 'vitest'
import { borrarDatosLocales, db } from './db'
import { guardarCierre, agregarPago } from './datos'
import { guardarFilas, revisarFilas, type FilaCarga } from './carga'

const HOY = '2026-11-18'
const NEGOCIO = 'negocio-1'
let n = 0
const fila = (cambios: Partial<FilaCarga>): FilaCarga => ({
  id: ++n,
  origen: `Fila ${n}`,
  fecha: '2026-11-01',
  tipo: 'historico',
  base: null,
  efectivo: null,
  salida_caja: null,
  nequi: null,
  otros: null,
  total: 300000,
  incluir: true,
  reemplazar: false,
  ...cambios,
})

beforeEach(() => borrarDatosLocales())

describe('revisar antes de guardar (RF-29)', () => {
  it('marca fechas inválidas, futuras y repetidas, y los días que ya tienen cierre', () => {
    const filas = [
      fila({ fecha: '2026-02-31' }),
      fila({ fecha: '2026-12-25' }),
      fila({ fecha: '2026-11-03' }),
      fila({ fecha: '2026-11-03' }),
      fila({ fecha: '2026-11-04', tipo: 'normal', base: 100000 }),
      fila({ fecha: '2026-11-05' }),
    ]
    const r = revisarFilas(filas, new Set(['2026-11-05']), HOY)
    expect(filas.map((f) => r.get(f.id)!.errores)).toEqual([
      ['La fecha no es válida.'],
      ['La fecha es un día que no ha llegado.'],
      [],
      ['Ese día está repetido (Fila 3).'],
      ['Falta la base inicial o el efectivo contado.'],
      [],
    ])
    expect(r.get(filas[5]!.id)!.yaTiene).toBe(true)
  })

  it('una fila que no se incluye no cuenta como repetida', () => {
    const filas = [fila({ fecha: '2026-11-03', incluir: false }), fila({ fecha: '2026-11-03' })]
    expect(revisarFilas(filas, new Set(), HOY).get(filas[1]!.id)!.errores).toEqual([])
  })
})

describe('guardar lo confirmado (RF-27, RF-28, RN-33, RN-35)', () => {
  it('guarda históricos y cierres completos con sus pagos, y omite lo que tiene errores', async () => {
    const guardadas = await guardarFilas(
      NEGOCIO,
      [
        fila({ fecha: '2026-11-01', total: 410000, nequi: 50000 }),
        fila({ fecha: '2026-11-02', tipo: 'normal', base: 100000, efectivo: 450000, salida_caja: 20000, nequi: 30000, otros: 10000, total: null }),
        fila({ fecha: '2026-11-03', tipo: 'no_abrio', total: null }),
        fila({ fecha: '2026-12-25' }),
      ],
      HOY,
    )

    expect(guardadas).toBe(3)
    expect(await db.cierres.where('fecha').equals('2026-11-01').first()).toMatchObject({ tipo: 'historico', total_historico: 410000, nequi_historico: 50000, pendiente: 1 })
    expect(await db.cierres.where('fecha').equals('2026-11-02').first()).toMatchObject({ tipo: 'normal', base: 100000, efectivo: 450000, salida_caja: 20000, modificado_en: null })
    expect((await db.pagos.where('fecha').equals('2026-11-02').toArray()).map((p) => [p.medio, p.valor]).sort()).toEqual([
      ['nequi', 30000],
      ['otro', 10000],
    ])
    expect((await db.cierres.where('fecha').equals('2026-11-03').first())?.no_abrio).toBe(true)
  })

  it('un día con cierre se omite, salvo que el dueño escoja reemplazarlo; sus pagos se conservan', async () => {
    await guardarCierre(NEGOCIO, '2026-11-05', { base: 100000, efectivo: 300000, salida_caja: 0 })
    await agregarPago(NEGOCIO, '2026-11-05', { medio: 'nequi', valor: 25000, hora: '10:00' })

    expect(await guardarFilas(NEGOCIO, [fila({ fecha: '2026-11-05', total: 999000 })], HOY)).toBe(0)
    expect((await db.cierres.where('fecha').equals('2026-11-05').first())?.efectivo).toBe(300000)

    const reemplazo = fila({ fecha: '2026-11-05', tipo: 'normal', base: 100000, efectivo: 500000, nequi: 70000, total: null, reemplazar: true })
    expect(await guardarFilas(NEGOCIO, [reemplazo], HOY)).toBe(1)
    const cierre = await db.cierres.where('fecha').equals('2026-11-05').first()
    expect(cierre?.efectivo).toBe(500000)
    expect(cierre?.modificado_en).not.toBeNull()
    expect((await db.pagos.where('fecha').equals('2026-11-05').toArray()).map((p) => p.valor)).toEqual([25000])
  })
})
