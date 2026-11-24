import { liveQuery } from 'dexie'
import { onScopeDispose, shallowRef, watch, type ShallowRef, type WatchSource } from 'vue'
import { db, nuevoId, type Cierre, type FotoComprobante, type Pago } from './db'
import { nuevoAbono } from './datosDeudores'

/*
 * Lecturas y escrituras de la base local. Todo se guarda primero en el celular
 * (funciona sin internet) y queda "pendiente" hasta que se sincroniza (RF-30).
 */

// Consulta que se actualiza sola cuando cambian los datos (liveQuery de Dexie)
export function useConsulta<T>(consulta: () => Promise<T>, inicial: T, dependencias: WatchSource[] = []): ShallowRef<T> {
  const valor = shallowRef(inicial) as ShallowRef<T>
  let suscripcion: { unsubscribe(): void } | undefined

  watch(
    dependencias,
    () => {
      suscripcion?.unsubscribe()
      valor.value = inicial // mientras llega lo nuevo, no se muestra lo de la consulta anterior
      suscripcion = liveQuery(consulta).subscribe({ next: (v) => (valor.value = v) })
    },
    { immediate: true },
  )
  onScopeDispose(() => suscripcion?.unsubscribe())

  return valor
}

export function cierresEntre(desde: string, hasta: string): Promise<Cierre[]> {
  return db.cierres.where('fecha').between(desde, hasta, true, true).toArray()
}

export function pagosEntre(desde: string, hasta: string): Promise<Pago[]> {
  return db.pagos.where('fecha').between(desde, hasta, true, true).toArray()
}

export async function cierreDelDia(fecha: string): Promise<Cierre | undefined> {
  return db.cierres.where('fecha').equals(fecha).first()
}

export async function pagosDelDia(fecha: string): Promise<Pago[]> {
  const pagos = await db.pagos.where('fecha').equals(fecha).toArray()
  return pagos.sort((a, b) => (a.hora ?? '').localeCompare(b.hora ?? ''))
}

// RF-49: la base del último cierre registrado
export async function ultimaBase(): Promise<number | null> {
  const cierres = await db.cierres.orderBy('fecha').reverse().toArray()
  return cierres.find((c) => c.tipo === 'normal' && !c.no_abrio && c.base !== null)?.base ?? null
}

export interface DatosNuevoCierre {
  base: number
  efectivo: number
  salida_caja: number
}

// RF-16 / RF-20. Si el día estaba "No abrió", la marca se quita (RN-31).
export async function guardarCierre(negocioId: string, fecha: string, datos: DatosNuevoCierre): Promise<void> {
  await db.transaction('rw', db.cierres, async () => {
    const existente = await cierreDelDia(fecha)
    const esCorreccion = existente !== undefined && !existente.no_abrio
    await db.cierres.put({
      id: existente?.id ?? nuevoId(),
      negocio_id: negocioId,
      fecha,
      tipo: 'normal',
      no_abrio: false,
      base: datos.base,
      efectivo: datos.efectivo,
      salida_caja: datos.salida_caja,
      total_historico: null,
      nequi_historico: null,
      modificado_en: esCorreccion ? new Date().toISOString() : (existente?.modificado_en ?? null),
      version: existente?.version ?? null,
      pendiente: 1,
    })
  })
}

// RN-35: día cargado del cuaderno o de Excel, solo con el total y, si se conoce, el Nequi.
// Si el día ya tenía cierre, lo reemplaza (el dueño lo escogió, RN-33) y queda como modificado.
export async function guardarHistorico(negocioId: string, fecha: string, total: number, nequi: number | null): Promise<void> {
  await db.transaction('rw', db.cierres, async () => {
    const existente = await cierreDelDia(fecha)
    await db.cierres.put({
      id: existente?.id ?? nuevoId(),
      negocio_id: negocioId,
      fecha,
      tipo: 'historico',
      no_abrio: false,
      base: null,
      efectivo: null,
      salida_caja: 0,
      total_historico: total,
      nequi_historico: nequi,
      modificado_en: existente && !existente.no_abrio ? new Date().toISOString() : null,
      version: existente?.version ?? null,
      pendiente: 1,
    })
  })
}

// RN-31: un día pasado sin cierre se puede marcar como "No abrió"
export async function marcarNoAbrio(negocioId: string, fecha: string): Promise<void> {
  const existente = await cierreDelDia(fecha)
  if (existente) return // ya tiene cierre, o ya estaba marcado: no hay nada que enviar
  await db.cierres.put({
    id: nuevoId(),
    negocio_id: negocioId,
    fecha,
    tipo: 'normal',
    no_abrio: true,
    base: null,
    efectivo: null,
    salida_caja: 0,
    total_historico: null,
    nequi_historico: null,
    modificado_en: null,
    version: null,
    pendiente: 1,
  })
}

export interface DatosNuevoPago {
  medio: 'nequi' | 'otro'
  valor: number
  hora: string | null
}

// RF-51 (Nequi escrito a mano) y RF-52 (otros pagos). Si el día ya tenía cierre,
// el cierre queda como modificado (RN-30). Devuelve true en ese caso, para avisar.
export async function agregarPago(negocioId: string, fecha: string, datos: DatosNuevoPago): Promise<boolean> {
  return db.transaction('rw', db.pagos, db.cierres, async () => {
    await db.pagos.add({
      id: nuevoId(),
      negocio_id: negocioId,
      fecha,
      hora: datos.hora,
      medio: datos.medio,
      origen: 'manual',
      estado: 'confirmado',
      valor: datos.valor,
      nombre_pagador: null,
      referencia: null,
      foto_ruta: null,
      anulado_en: null,
      motivo_anulacion: null,
      version: null,
      pendiente: 1,
    })
    return marcarModificado(fecha)
  })
}

export interface DatosPagoLeido {
  valor: number
  fecha: string
  hora: string | null
  nombre: string | null
  referencia: string | null
  abono?: { deudorId: string; valor: number } // RN-39: el dueño dijo que es "Abono de fiado"
}

// RF-18: el dueño confirma lo que leyó el OCR. El pago cuenta en el día del comprobante (RN-04)
// y, si ese día ya tenía cierre, el cierre queda como modificado (RN-30).
// Si es abono de un fiado, el pago cuenta una sola vez en el día y además baja la deuda (RN-18, RN-39).
export async function confirmarComprobante(negocioId: string, foto: FotoComprobante, datos: DatosPagoLeido): Promise<boolean> {
  return db.transaction('rw', [db.pagos, db.cierres, db.fotos, db.abonos], async () => {
    await db.pagos.add({
      id: foto.id,
      negocio_id: negocioId,
      fecha: datos.fecha,
      hora: datos.hora,
      medio: 'nequi',
      origen: foto.origen,
      estado: 'confirmado',
      valor: datos.valor,
      nombre_pagador: datos.nombre,
      referencia: datos.referencia,
      foto_ruta: foto.foto_ruta,
      anulado_en: null,
      motivo_anulacion: null,
      version: null,
      pendiente: 1,
    })
    if (datos.abono && datos.abono.valor > 0) {
      await db.abonos.add(nuevoAbono(negocioId, datos.abono.deudorId, { valor: datos.abono.valor, fecha: datos.fecha, nota: 'Pago Nequi', pago_id: foto.id }))
    }
    await db.fotos.delete(foto.id)
    return marcarModificado(datos.fecha)
  })
}

// RN-05: la referencia ya está en un pago que no se anuló
export async function referenciaCargada(referencia: string | null): Promise<boolean> {
  if (!referencia) return false
  return (await db.pagos.where('referencia').equals(referencia).filter((p) => !p.anulado_en).count()) > 0
}

// RN-40: un pago Nequi escrito a mano con el mismo valor y la misma hora puede ser el mismo
export async function pagoManualParecido(fecha: string, valor: number | null, hora: string | null): Promise<Pago | undefined> {
  if (!valor || !hora) return undefined
  const pagos = await db.pagos.where('fecha').equals(fecha).toArray()
  return pagos.find((p) => p.origen === 'manual' && p.medio === 'nequi' && !p.anulado_en && p.valor === valor && p.hora?.slice(0, 5) === hora)
}

// RN-36: no se borra; queda tachado con el motivo. Si el pago era el abono de un fiado,
// ese abono también se anula: la plata no llegó, así que la deuda vuelve a quedar como estaba.
export async function anularPago(id: string, motivo: string): Promise<void> {
  await db.transaction('rw', [db.pagos, db.cierres, db.abonos], async () => {
    const pago = await db.pagos.get(id)
    if (!pago || pago.anulado_en) return
    const ahora = new Date().toISOString()
    await db.pagos.update(id, { anulado_en: ahora, motivo_anulacion: motivo, pendiente: 1 })
    const abonos = await db.abonos.where('pago_id').equals(id).filter((a) => !a.anulado_en).toArray()
    for (const abono of abonos) await db.abonos.update(abono.id, { anulado_en: ahora, motivo_anulacion: motivo, pendiente: 1 })
    await marcarModificado(pago.fecha)
  })
}

export async function marcarModificado(fecha: string): Promise<boolean> {
  const cierre = await cierreDelDia(fecha)
  if (!cierre || cierre.no_abrio) return false
  await db.cierres.update(cierre.id, { modificado_en: new Date().toISOString(), pendiente: 1 })
  return true
}

// RNF-12: el dueño escoge qué versión del cierre se queda
export async function resolverConflicto(idServidor: string, quedarseConEste: boolean): Promise<void> {
  await db.transaction('rw', db.conflictos, db.cierres, async () => {
    const conflicto = await db.conflictos.get(idServidor)
    if (!conflicto) return
    if (quedarseConEste) {
      const { local, servidor } = conflicto
      await db.cierres.put({
        ...local,
        id: servidor.id,
        version: servidor.version, // con la versión actual, el servidor acepta el cambio
        modificado_en: new Date().toISOString(),
        pendiente: 1,
      })
    }
    await db.conflictos.delete(idServidor)
  })
}
