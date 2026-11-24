import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { borrarDatosLocales, db } from './db'
import { agregarPago, anularPago, guardarCierre, marcarNoAbrio, resolverConflicto } from './datos'
import { fiarAPersonaNueva } from './datosDeudores'
import { useSesionStore } from './stores/sesion'
import { useSincronizacionStore } from './stores/sincronizacion'

const NEGOCIO = 'negocio-1'

// Servidor falso: responde lo que se le indique y guarda lo que recibió
let respuestas: ((cuerpo: Record<string, unknown>) => unknown)[] = []
const recibido: Record<string, unknown>[] = []

function servidor(responder: (cuerpo: Record<string, unknown>) => unknown) {
  respuestas.push(responder)
}

function respuestaVacia(extra: Record<string, unknown> = {}) {
  return { hasta: '2026-11-18T20:00:00-05:00', negocio: { estado: 'activo' }, cierres: [], pagos: [], conflictos: [], ...extra }
}

beforeEach(async () => {
  await borrarDatosLocales()
  respuestas = []
  recibido.length = 0
  vi.stubGlobal('fetch', async (_url: string, opciones: RequestInit) => {
    const cuerpo = JSON.parse(String(opciones.body))
    recibido.push(cuerpo)
    const responder = respuestas.shift()
    if (!responder) throw new TypeError('Failed to fetch') // sin internet
    return new Response(JSON.stringify(responder(cuerpo)), { status: 200 })
  })
  setActivePinia(createPinia())
  const sesion = useSesionStore()
  sesion.token = 'token-de-prueba'
  sesion.usuario = {
    id: 1,
    nombre: 'Dueño',
    usuario: 'tienda',
    rol: 'dueno',
    debe_aceptar_terminos: false,
    debe_cambiar_contrasena: false,
    negocio: { id: NEGOCIO, nombre: 'Tienda', estado: 'activo' },
  }
})

afterEach(() => vi.unstubAllGlobals())

describe('guardar en el celular', () => {
  it('el cierre queda pendiente y al corregirlo guarda la fecha de modificación (RF-20)', async () => {
    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 450000, salida_caja: 0 })
    let cierre = await db.cierres.where('fecha').equals('2026-11-18').first()
    expect(cierre).toMatchObject({ pendiente: 1, modificado_en: null, efectivo: 450000 })

    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 470000, salida_caja: 0 })
    cierre = await db.cierres.where('fecha').equals('2026-11-18').first()
    expect(cierre?.efectivo).toBe(470000)
    expect(cierre?.modificado_en).not.toBeNull()
    expect(await db.cierres.count()).toBe(1) // RN-01
  })

  it('un pago en un día ya cerrado marca el cierre como modificado (RN-30)', async () => {
    expect(await agregarPago(NEGOCIO, '2026-11-17', { medio: 'nequi', valor: 25000, hora: '08:42' })).toBe(false)
    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 450000, salida_caja: 0 })
    expect(await agregarPago(NEGOCIO, '2026-11-18', { medio: 'otro', valor: 20000, hora: null })).toBe(true)
    expect((await db.cierres.where('fecha').equals('2026-11-18').first())?.modificado_en).not.toBeNull()
  })

  it('anular un pago no lo borra y guarda el motivo (RN-36)', async () => {
    await agregarPago(NEGOCIO, '2026-11-18', { medio: 'nequi', valor: 25000, hora: '08:42' })
    const pago = (await db.pagos.toArray())[0]!
    await anularPago(pago.id, 'Valor equivocado')
    expect(await db.pagos.get(pago.id)).toMatchObject({ motivo_anulacion: 'Valor equivocado', pendiente: 1 })
  })

  it('"No abrió" no pisa un día que ya tiene cierre, y registrar el cierre quita la marca (RN-31)', async () => {
    await marcarNoAbrio(NEGOCIO, '2026-11-08')
    expect((await db.cierres.where('fecha').equals('2026-11-08').first())?.no_abrio).toBe(true)
    await guardarCierre(NEGOCIO, '2026-11-08', { base: 0, efectivo: 10000, salida_caja: 0 })
    expect((await db.cierres.where('fecha').equals('2026-11-08').first())?.no_abrio).toBe(false)

    await marcarNoAbrio(NEGOCIO, '2026-11-08')
    expect((await db.cierres.where('fecha').equals('2026-11-08').first())?.no_abrio).toBe(false)
  })
})

describe('sincronización (RF-30)', () => {
  it('envía lo pendiente y guarda la versión que devuelve el servidor', async () => {
    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 450000, salida_caja: 0 })
    servidor((cuerpo) => {
      const [enviado] = cuerpo.cierres as Record<string, unknown>[]
      return respuestaVacia({ cierres: [{ ...enviado, negocio_id: NEGOCIO, version: '2026-11-18T20:00:00-05:00' }] })
    })

    const sync = useSincronizacionStore()
    await sync.sincronizar()

    const enviado = (recibido[0]!.cierres as Record<string, unknown>[])[0]!
    expect(enviado).not.toHaveProperty('pendiente')
    expect(enviado).not.toHaveProperty('negocio_id')
    expect(await db.cierres.toArray()).toMatchObject([{ pendiente: 0, version: '2026-11-18T20:00:00-05:00' }])
    expect((await db.meta.get('desde'))?.valor).toBe('2026-11-18T20:00:00-05:00')
    expect(sync.estado).toBe('en_espera')
  })

  it('sin internet no pierde nada: todo sigue pendiente', async () => {
    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 450000, salida_caja: 0 })
    const sync = useSincronizacionStore()
    await sync.sincronizar()

    expect(sync.estado).toBe('sin_conexion')
    expect((await db.cierres.toArray())[0]?.pendiente).toBe(1)
  })

  it('si el cierre cambió en otro dispositivo, guarda las dos versiones para escoger (RNF-12)', async () => {
    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 470000, salida_caja: 0 })
    const local = (await db.cierres.toArray())[0]!
    const delServidor = { ...local, id: 'id-del-otro-celular', negocio_id: NEGOCIO, efectivo: 480000, version: 'v-servidor' }
    servidor(() => respuestaVacia({ conflictos: [{ tabla: 'cierres', id_local: local.id, servidor: delServidor }] }))

    await useSincronizacionStore().sincronizar()

    const conflicto = await db.conflictos.get('id-del-otro-celular')
    expect(conflicto?.local.efectivo).toBe(470000)
    expect(conflicto?.servidor.efectivo).toBe(480000)
    expect(await db.cierres.toArray()).toMatchObject([{ id: 'id-del-otro-celular', efectivo: 480000, pendiente: 0 }])

    // El dueño se queda con el de este celular: se envía con la versión del servidor
    await resolverConflicto('id-del-otro-celular', true)
    expect(await db.cierres.get('id-del-otro-celular')).toMatchObject({ efectivo: 470000, version: 'v-servidor', pendiente: 1 })
    expect(await db.conflictos.count()).toBe(0)
  })

  it('si el dueño corrige mientras se envía, conserva su cambio con la nueva versión', async () => {
    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 450000, salida_caja: 0 })
    servidor((cuerpo) => {
      const [enviado] = cuerpo.cierres as Record<string, unknown>[]
      return respuestaVacia({ cierres: [{ ...enviado, negocio_id: NEGOCIO, version: 'v2' }] })
    })
    const sync = useSincronizacionStore()
    const enCurso = sync.sincronizar()
    await guardarCierre(NEGOCIO, '2026-11-18', { base: 100000, efectivo: 999000, salida_caja: 0 })
    await enCurso

    expect((await db.cierres.toArray())[0]).toMatchObject({ efectivo: 999000, pendiente: 1 })
  })

  it('negocio bloqueado o 7 días sin sincronizar: solo consulta (RF-12, RN-27)', async () => {
    servidor(() => respuestaVacia({ negocio: { estado: 'bloqueado' } }))
    const sync = useSincronizacionStore()
    await sync.sincronizar()
    expect(sync.motivoSoloConsulta).toBe('bloqueado')

    setActivePinia(createPinia())
    await db.meta.put({ clave: 'estado_negocio', valor: 'activo' })
    await db.meta.put({ clave: 'ultima_sincronizacion', valor: new Date(Date.now() - 8 * 24 * 3600 * 1000).toISOString() })
    const otra = useSincronizacionStore()
    await vi.waitFor(() => expect(otra.motivoSoloConsulta).toBe('sin_sincronizar'))
  })

  it('envía deudores, fiados y abonos, y une al deudor repetido con el que ya estaba (RF-45, RN-32)', async () => {
    const repetido = await fiarAPersonaNueva(NEGOCIO, { nombre: 'Carlos', telefono: '3104567890' }, { fecha: '2026-11-18', descripcion: 'Arroz', valor: 12000, fecha_pago: '2026-11-25' })
    servidor((cuerpo) => {
      const fiados = cuerpo.fiados as Record<string, unknown>[]
      return respuestaVacia({
        deudores: [
          { id: repetido, negocio_id: NEGOCIO, nombre: null, telefono: null, unido_a: 'deudor-real', version: 'v1' },
          { id: 'deudor-real', negocio_id: NEGOCIO, nombre: 'Carlos Pérez', telefono: '3104567890', version: 'v1' },
        ],
        fiados: fiados.map((f) => ({ ...f, deudor_id: 'deudor-real', negocio_id: NEGOCIO, version: 'v1' })),
      })
    })

    await useSincronizacionStore().sincronizar()

    expect(recibido[0]).toMatchObject({ deudores: [{ id: repetido, telefono: '3104567890' }], fiados: [{ deudor_id: repetido, valor: 12000 }] })
    expect(await db.deudores.toArray()).toMatchObject([{ id: 'deudor-real', nombre: 'Carlos Pérez', pendiente: 0 }])
    expect(await db.fiados.toArray()).toMatchObject([{ deudor_id: 'deudor-real', pendiente: 0 }])
    expect(useSincronizacionStore().pendientes).toBe(0)
  })

  it('con muchos cambios envía por partes y en orden: el pago va antes que el abono que lo usa', async () => {
    const dia = (i: number) => new Date(Date.UTC(2024, 0, 1 + i)).toISOString().slice(0, 10)
    await db.cierres.bulkAdd(
      Array.from({ length: 850 }, (_, i) => ({
        id: `c${i}`, negocio_id: NEGOCIO, fecha: dia(i), tipo: 'normal' as const, no_abrio: false, base: 1, efectivo: 2, salida_caja: 0,
        total_historico: null, nequi_historico: null, modificado_en: null, version: null, pendiente: 1 as const,
      })),
    )
    await agregarPago(NEGOCIO, '2026-11-18', { medio: 'nequi', valor: 18000, hora: '11:15' })
    const pagoId = (await db.pagos.toArray())[0]!.id
    await db.abonos.add({ id: 'a1', negocio_id: NEGOCIO, deudor_id: 'd1', pago_id: pagoId, fecha: '2026-11-18', valor: 12000, nota: null, anulado_en: null, motivo_anulacion: null, version: null, pendiente: 1 })

    // El servidor falso confirma todo lo que recibe
    const eco = (cuerpo: Record<string, unknown>) =>
      respuestaVacia({
        cierres: (cuerpo.cierres as Record<string, unknown>[]).map((c) => ({ ...c, negocio_id: NEGOCIO, version: 'v1' })),
        pagos: (cuerpo.pagos as Record<string, unknown>[]).map((c) => ({ ...c, negocio_id: NEGOCIO, version: 'v1' })),
        abonos: (cuerpo.abonos as Record<string, unknown>[]).map((c) => ({ ...c, negocio_id: NEGOCIO, version: 'v1' })),
      })
    servidor(eco)
    servidor(eco)
    servidor(eco)

    await useSincronizacionStore().sincronizar()

    const tamanos = recibido.map((c) => ['cierres', 'pagos', 'abonos'].map((t) => (c[t] as unknown[]).length))
    expect(tamanos).toEqual([
      [400, 0, 0],
      [400, 0, 0],
      [50, 1, 1],
    ])
    expect(recibido.every((c) => (c.cierres as unknown[]).length <= 500)).toBe(true) // límite del servidor
    expect(useSincronizacionStore().pendientes).toBe(0)
  })
})
