import 'fake-indexeddb/auto'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { borrarDatosLocales, db } from './db'
import { agregarPago, anularPago, confirmarComprobante, guardarCierre, pagoManualParecido, referenciaCargada } from './datos'
import { guardarFoto, leerFoto, leerPendientes } from './comprobantes'
import { createPinia, setActivePinia } from 'pinia'
import { useComprobantesStore } from './stores/comprobantes'
import { useSesionStore } from './stores/sesion'

const NEGOCIO = 'negocio-1'
const imagen = () => new Blob(['foto'], { type: 'image/jpeg' })
const campos = { valor: 18000, fecha: '2026-11-17', hora: '11:15', nombre: 'Carlos Perez', referencia: 'M1234567' }

let servidorEncendido = true
const recibidas: FormData[] = []

beforeEach(async () => {
  await borrarDatosLocales()
  servidorEncendido = true
  recibidas.length = 0
  vi.stubGlobal('fetch', async (_url: string, opciones: RequestInit) => {
    if (!servidorEncendido) throw new TypeError('Failed to fetch')
    recibidas.push(opciones.body as FormData)
    return new Response(JSON.stringify({ foto_ruta: 'comprobantes/negocio-1/a.jpg', leido: true, campos, repetido: false }), { status: 200 })
  })
})

afterEach(() => vi.unstubAllGlobals())

describe('leer la foto (RF-17)', () => {
  it('envía la foto y queda lista para revisar con lo que leyó el OCR', async () => {
    const id = await guardarFoto('pantallazo', imagen())
    expect(await leerFoto(id, 'token')).toBe('leida')

    expect(recibidas[0]?.get('foto')).toBeInstanceOf(Blob)
    expect(await db.fotos.get(id)).toMatchObject({ estado: 'por_confirmar', foto_ruta: 'comprobantes/negocio-1/a.jpg', campos, repetido: false })
  })

  it('sin internet la foto queda guardada y se lee al volver la conexión (RF-18)', async () => {
    servidorEncendido = false
    const id = await guardarFoto('foto', imagen())
    expect(await leerFoto(id, 'token')).toBe('sin_conexion')
    expect((await db.fotos.get(id))?.estado).toBe('por_leer')

    servidorEncendido = true
    await leerPendientes('token')
    expect((await db.fotos.get(id))?.estado).toBe('por_confirmar')
  })

  it('marca como repetido un comprobante ya cargado en este celular aunque no haya llegado al servidor (RN-05)', async () => {
    const primera = await guardarFoto('pantallazo', imagen())
    await leerFoto(primera, 'token')
    await confirmarComprobante(NEGOCIO, (await db.fotos.get(primera))!, { ...campos })

    const segunda = await guardarFoto('pantallazo', imagen())
    await leerFoto(segunda, 'token')
    expect((await db.fotos.get(segunda))?.repetido).toBe(true)
  })
})

describe('confirmar (RF-18)', () => {
  it('el pago queda en el día del comprobante, con su foto, y la foto local se borra (RN-04)', async () => {
    const id = await guardarFoto('foto', imagen())
    await leerFoto(id, 'token')
    const cambio = await confirmarComprobante(NEGOCIO, (await db.fotos.get(id))!, { ...campos })

    expect(cambio).toBe(false)
    expect(await db.pagos.get(id)).toMatchObject({
      fecha: '2026-11-17',
      origen: 'foto',
      estado: 'confirmado',
      valor: 18000,
      nombre_pagador: 'Carlos Perez',
      referencia: 'M1234567',
      foto_ruta: 'comprobantes/negocio-1/a.jpg',
      pendiente: 1,
    })
    expect(await db.fotos.count()).toBe(0)
  })

  it('si ese día ya tenía cierre, el cierre queda como modificado (RN-30)', async () => {
    await guardarCierre(NEGOCIO, '2026-11-17', { base: 100000, efectivo: 300000, salida_caja: 0 })
    const id = await guardarFoto('pantallazo', imagen())
    await leerFoto(id, 'token')

    expect(await confirmarComprobante(NEGOCIO, (await db.fotos.get(id))!, { ...campos })).toBe(true)
  })
})

describe('repetidos', () => {
  it('una referencia anulada ya no cuenta como cargada (RN-05)', async () => {
    const id = await guardarFoto('pantallazo', imagen())
    await leerFoto(id, 'token')
    await confirmarComprobante(NEGOCIO, (await db.fotos.get(id))!, { ...campos })
    expect(await referenciaCargada('M1234567')).toBe(true)

    await anularPago(id, 'Valor equivocado')
    expect(await referenciaCargada('M1234567')).toBe(false)
  })

  it('encuentra un pago escrito a mano con el mismo valor y hora (RN-40)', async () => {
    await agregarPago(NEGOCIO, '2026-11-17', { medio: 'nequi', valor: 18000, hora: '11:15' })
    expect((await pagoManualParecido('2026-11-17', 18000, '11:15'))?.valor).toBe(18000)
    expect(await pagoManualParecido('2026-11-17', 18000, '11:16')).toBeUndefined()
    expect(await pagoManualParecido('2026-11-17', 25000, '11:15')).toBeUndefined()
  })
})

describe('ventana del pago mientras lee', () => {
  it('se abre apenas se toma la foto, antes de que el servidor termine de leer', async () => {
    setActivePinia(createPinia())
    useSesionStore().token = 'token'
    let responder: () => void = () => {}
    vi.stubGlobal('fetch', () => new Promise<Response>((listo) => {
      responder = () => listo(new Response(JSON.stringify({ foto_ruta: 'comprobantes/negocio-1/a.jpg', leido: true, campos, repetido: false }), { status: 200 }))
    }))
    const comprobantes = useComprobantesStore()

    const terminado = comprobantes.agregar('foto', new File(['foto'], 'foto.jpg', { type: 'image/jpeg' }))
    await vi.waitFor(() => expect(comprobantes.revisando).not.toBeNull())
    const id = comprobantes.revisando!
    expect((await db.fotos.get(id))?.estado).toBe('por_leer')
    expect(comprobantes.leyendo).toBe(true)

    responder()
    await terminado
    expect(comprobantes.revisando).toBe(id)
    expect(comprobantes.leyendo).toBe(false)
    expect(await db.fotos.get(id)).toMatchObject({ estado: 'por_confirmar', campos })
  })

  it('se cierra si no hay internet, y la foto queda guardada para leerla después (RF-18)', async () => {
    setActivePinia(createPinia())
    useSesionStore().token = 'token'
    servidorEncendido = false
    const comprobantes = useComprobantesStore()

    await comprobantes.agregar('foto', new File(['foto'], 'foto.jpg', { type: 'image/jpeg' }))

    expect(comprobantes.revisando).toBeNull()
    expect(await db.fotos.where('estado').equals('por_leer').count()).toBe(1)
  })
})

describe('descartar (RN-43)', () => {
  it('borra la foto del celular y le pide al servidor que la borre', async () => {
    setActivePinia(createPinia())
    useSesionStore().token = 'token'
    const pedidos: { url: string; metodo: string }[] = []
    vi.stubGlobal('fetch', async (url: string, opciones: RequestInit) => {
      pedidos.push({ url, metodo: opciones.method ?? 'GET' })
      if (opciones.method === 'DELETE') return new Response(null, { status: 204 })
      return new Response(JSON.stringify({ foto_ruta: 'comprobantes/negocio-1/a.jpg', leido: true, campos, repetido: false }), { status: 200 })
    })
    const id = await guardarFoto('pantallazo', imagen())
    await leerFoto(id, 'token')

    await useComprobantesStore().descartar(id)

    expect(await db.fotos.count()).toBe(0)
    expect(pedidos.at(-1)).toEqual({ url: expect.stringContaining('/comprobantes/foto?ruta=comprobantes%2Fnegocio-1%2Fa.jpg'), metodo: 'DELETE' })
  })
})
