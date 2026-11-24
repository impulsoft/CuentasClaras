import 'fake-indexeddb/auto'
import { beforeEach, describe, expect, it } from 'vitest'
import { borrarDatosLocales, db, type Abono, type Deudor, type Fiado } from './db'
import { deudorSugerido, normalizarTelefono, ordenarDeudores, pagaHoy, repartirPago, resumirDeudor, textoPlazo, totalPorCobrar } from './deudas'
import { agregarFiado, anularAbono, borrarDatosDeudor, deudorConTelefono, fiarAPersonaNueva, marcarPagado, registrarAbono, resumenDe } from './datosDeudores'
import { anularPago, confirmarComprobante, guardarCierre } from './datos'

const HOY = '2026-11-18'
const NEGOCIO = 'negocio-1'

const deudor = (cambios: Partial<Deudor> = {}): Deudor => ({
  id: 'd1',
  negocio_id: NEGOCIO,
  nombre: 'Carlos Pérez',
  telefono: '3104567890',
  autorizacion_en: '2026-11-05T10:00:00Z',
  anonimizado_en: null,
  version: null,
  pendiente: 0,
  ...cambios,
})
let n = 0
const fiado = (cambios: Partial<Fiado> = {}): Fiado => ({
  id: `f${++n}`,
  negocio_id: NEGOCIO,
  deudor_id: 'd1',
  fecha: '2026-11-05',
  descripcion: 'Arroz',
  valor: 30000,
  fecha_pago: '2026-11-25',
  anulado_en: null,
  motivo_anulacion: null,
  version: null,
  pendiente: 0,
  ...cambios,
})
const abono = (cambios: Partial<Abono> = {}): Abono => ({
  id: `a${++n}`,
  negocio_id: NEGOCIO,
  deudor_id: 'd1',
  pago_id: null,
  fecha: '2026-11-12',
  valor: 18000,
  nota: null,
  anulado_en: null,
  motivo_anulacion: null,
  version: null,
  pendiente: 0,
  ...cambios,
})

describe('saldo y estado del deudor (RN-17, RN-28, RN-37)', () => {
  it('sin abonos está pendiente y debe todo', () => {
    const r = resumirDeudor(deudor(), [fiado()], [], HOY)
    expect(r).toMatchObject({ saldo: 30000, estado: 'pendiente', pagaEl: '2026-11-25' })
  })

  it('con un abono parcial queda "Abonó" y muestra cuánto debía', () => {
    const r = resumirDeudor(deudor(), [fiado()], [abono()], HOY)
    expect(r).toMatchObject({ saldo: 12000, estado: 'abono', debia: 30000 })
  })

  it('el abono cubre primero el fiado más antiguo y lo que sobra pasa al siguiente', () => {
    const viejo = fiado({ fecha: '2026-11-01', valor: 10000 })
    const nuevo = fiado({ fecha: '2026-11-10', valor: 20000 })
    const r = resumirDeudor(deudor(), [nuevo, viejo], [abono({ valor: 15000 })], HOY)
    expect(r.fiados.map((f) => [f.fiado.id, f.saldo])).toEqual([
      [viejo.id, 0],
      [nuevo.id, 15000],
    ])
  })

  it('si abonó pero tiene un fiado vencido, está Vencido', () => {
    const r = resumirDeudor(deudor(), [fiado({ fecha_pago: '2026-11-10' })], [abono()], HOY)
    expect(r.estado).toBe('vencido')
  })

  it('un fiado ya pagado no lo hace vencido aunque su fecha haya pasado', () => {
    const pagado = fiado({ fecha: '2026-11-01', valor: 10000, fecha_pago: '2026-11-05' })
    const r = resumirDeudor(deudor(), [pagado, fiado()], [abono({ valor: 10000 })], HOY)
    expect(r.estado).toBe('pendiente')
  })

  it('cuando los abonos cubren todo queda Pagado (RN-20) y los anulados no cuentan (RN-36)', () => {
    const r = resumirDeudor(deudor(), [fiado(), fiado({ valor: 5000, anulado_en: '2026-11-06T00:00:00Z' })], [abono({ valor: 30000, fecha: '2026-11-08' })], HOY)
    expect(r).toMatchObject({ saldo: 0, estado: 'pagado', pagoEl: '2026-11-08' })
    expect(r.anulados).toHaveLength(1)
  })

  it('los vencidos van primero y el total cuenta personas, no fiados (RF-32, RF-41)', () => {
    const lista = [
      resumirDeudor(deudor({ id: 'p', nombre: 'Pagó' }), [fiado({ deudor_id: 'p' })], [abono({ deudor_id: 'p', valor: 30000 })], HOY),
      resumirDeudor(deudor({ id: 'q', nombre: 'Debe' }), [fiado({ deudor_id: 'q' })], [], HOY),
      resumirDeudor(deudor({ id: 'v', nombre: 'Vencido' }), [fiado({ deudor_id: 'v', fecha_pago: '2026-11-01' }), fiado({ deudor_id: 'v', fecha_pago: '2026-11-02' })], [], HOY),
    ]
    expect(ordenarDeudores(lista).map((d) => d.deudor.id)).toEqual(['v', 'q', 'p'])
    expect(totalPorCobrar(lista)).toEqual({ total: 90000, personas: 2, vencidas: 1 })
  })
})

describe('pago Nequi como abono (RN-39)', () => {
  it('si paga más de lo que debe, lo que sobra es venta', () => {
    expect(repartirPago(18000, 12000)).toEqual({ abono: 12000, venta: 6000 })
    expect(repartirPago(10000, 12000)).toEqual({ abono: 10000, venta: 0 })
  })

  it('sugiere al deudor por el nombre, también cuando Nequi lo oculta', () => {
    const lista = [
      resumirDeudor(deudor(), [fiado()], [], HOY),
      resumirDeudor(deudor({ id: 'd2', nombre: 'Brayan Steven Figueroa' }), [fiado({ deudor_id: 'd2' })], [], HOY),
    ]
    expect(deudorSugerido('CARLOS PEREZ', lista)?.deudor.id).toBe('d1')
    expect(deudorSugerido('Bra*** Sti*** Fig*****', lista)?.deudor.id).toBe('d2')
    expect(deudorSugerido('Carlos Gómez', lista)).toBeUndefined() // solo el nombre no basta
    expect(deudorSugerido(null, lista)).toBeUndefined()
  })
})

describe('teléfono (RN-32)', () => {
  it('se guarda solo con números', () => {
    expect(normalizarTelefono('+57 310 456-7890')).toBe('3104567890')
    expect(normalizarTelefono('310 456 7890')).toBe('3104567890')
  })
})

describe('base local', () => {
  beforeEach(() => borrarDatosLocales())

  const datosFiado = { fecha: '2026-11-05', descripcion: ' Arroz y aceite ', valor: 30000, fecha_pago: '2026-11-25' }

  it('un fiado a una persona nueva guarda la autorización y queda pendiente de enviar (RN-41, RF-45)', async () => {
    const id = await fiarAPersonaNueva(NEGOCIO, { nombre: 'Ana Torres', telefono: '320 111 2233' }, datosFiado)
    expect(await db.deudores.get(id)).toMatchObject({ telefono: '3201112233', pendiente: 1 })
    expect((await db.deudores.get(id))?.autorizacion_en).toBeTruthy()
    expect((await deudorConTelefono('+57 3201112233'))?.id).toBe(id)
    expect(await db.fiados.where('deudor_id').equals(id).first()).toMatchObject({ descripcion: 'Arroz y aceite', pendiente: 1 })
  })

  it('otro fiado a la misma persona suma al saldo (RN-21)', async () => {
    const id = await fiarAPersonaNueva(NEGOCIO, { nombre: 'Ana', telefono: '3201112233' }, datosFiado)
    await agregarFiado(NEGOCIO, id, { ...datosFiado, valor: 10000 })
    expect((await resumenDe(id, HOY))?.saldo).toBe(40000)
  })

  it('marcar como pagado registra un abono por todo el saldo con la fecha de hoy (RN-29)', async () => {
    const id = await fiarAPersonaNueva(NEGOCIO, { nombre: 'Ana', telefono: '3201112233' }, datosFiado)
    await registrarAbono(NEGOCIO, id, { valor: 5000, fecha: '2026-11-10', nota: null })
    expect(await marcarPagado(NEGOCIO, id)).toBe(25000)
    expect((await resumenDe(id))?.estado).toBe('pagado')
  })

  it('los datos solo se borran cuando ya no debe nada y se conservan los valores (RN-42)', async () => {
    const id = await fiarAPersonaNueva(NEGOCIO, { nombre: 'Ana', telefono: '3201112233' }, datosFiado)
    expect(await borrarDatosDeudor(id)).toBe(false)
    await marcarPagado(NEGOCIO, id)
    expect(await borrarDatosDeudor(id)).toBe(true)
    expect(await db.deudores.get(id)).toMatchObject({ nombre: null, telefono: null })
    expect((await resumenDe(id))?.nombre).toBe('Cliente anónimo')
    expect(await db.fiados.count()).toBe(1)
  })

  it('anular un abono de un día con cierre marca el cierre como modificado (RN-36)', async () => {
    const id = await fiarAPersonaNueva(NEGOCIO, { nombre: 'Ana', telefono: '3201112233' }, datosFiado)
    await guardarCierre(NEGOCIO, '2026-11-10', { base: 100000, efectivo: 200000, salida_caja: 0 })
    await registrarAbono(NEGOCIO, id, { valor: 5000, fecha: '2026-11-10', nota: null })
    const abonoId = (await db.abonos.toArray())[0]!.id
    expect(await anularAbono(abonoId, 'Valor equivocado')).toBe(true)
    expect((await resumenDe(id, HOY))?.saldo).toBe(30000)
  })

  it('un comprobante confirmado como abono baja la deuda, y si se anula el pago la deuda vuelve (RN-39)', async () => {
    const id = await fiarAPersonaNueva(NEGOCIO, { nombre: 'Carlos Pérez', telefono: '3104567890' }, { ...datosFiado, valor: 12000 })
    const foto = { id: 'pago-1', creado_en: '', origen: 'pantallazo' as const, imagen: new Blob(), estado: 'por_confirmar' as const, foto_ruta: null, leido: true, campos: null, repetido: false }
    await db.fotos.add(foto)
    await confirmarComprobante(NEGOCIO, foto, { valor: 18000, fecha: HOY, hora: '11:15', nombre: 'Carlos Pérez', referencia: 'M1', abono: { deudorId: id, valor: 12000 } })

    expect(await db.abonos.where('pago_id').equals('pago-1').first()).toMatchObject({ valor: 12000, fecha: HOY })
    expect((await resumenDe(id))?.saldo).toBe(0)
    expect((await db.pagos.get('pago-1'))?.valor).toBe(18000) // el pago cuenta completo en el día (RN-18)

    await anularPago('pago-1', 'No llegó la plata')
    expect((await resumenDe(id))?.saldo).toBe(12000)
  })
})

describe('cuándo paga, como lo dice la gente', () => {
  const HOY = '2026-11-21'
  it('cuenta los días de vencido', () => {
    expect(textoPlazo('2026-11-20', true, HOY)).toBe('venció ayer')
    expect(textoPlazo('2026-11-09', true, HOY)).toBe('venció hace 12 días')
    expect(textoPlazo('2026-08-01', true, HOY)).toBe('venció hace 3 meses')
  })

  it('dice hoy y mañana; si falta más, la fecha', () => {
    expect(textoPlazo('2026-11-21', false, HOY)).toBe('paga hoy')
    expect(textoPlazo('2026-11-22', false, HOY)).toBe('paga mañana')
    expect(textoPlazo('2026-11-30', false, HOY)).toBe('paga el 30 nov')
  })

  it('resalta a quien paga hoy, pero no al que ya pagó ni al vencido', () => {
    expect(pagaHoy({ estado: 'pendiente', pagaEl: HOY }, HOY)).toBe(true)
    expect(pagaHoy({ estado: 'abono', pagaEl: HOY }, HOY)).toBe(true)
    expect(pagaHoy({ estado: 'pagado', pagaEl: null }, HOY)).toBe(false)
    expect(pagaHoy({ estado: 'pendiente', pagaEl: '2026-11-22' }, HOY)).toBe(false)
  })
})