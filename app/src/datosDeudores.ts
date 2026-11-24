import { db, nuevoId, type Abono, type Deudor, type Fiado } from './db'
import { hoy } from './calculos'
import { nombreDeudor, normalizarTelefono, resumirDeudor, type ResumenDeudor } from './deudas'
import { marcarModificado } from './datos'

/*
 * Lecturas y escrituras del módulo de deudores en la base local. Funciona sin internet
 * y queda pendiente hasta que se sincroniza (RF-45).
 */

// ---------- Lecturas ----------

export async function resumenDeTodos(fecha = hoy()): Promise<ResumenDeudor[]> {
  const [deudores, fiados, abonos] = await Promise.all([db.deudores.toArray(), db.fiados.toArray(), db.abonos.toArray()])
  const fiadosPor = agrupar(fiados)
  const abonosPor = agrupar(abonos)
  return deudores.map((d) => resumirDeudor(d, fiadosPor.get(d.id) ?? [], abonosPor.get(d.id) ?? [], fecha))
}

export async function resumenDe(deudorId: string, fecha = hoy()): Promise<ResumenDeudor | undefined> {
  const deudor = await db.deudores.get(deudorId)
  if (!deudor) return undefined
  const [fiados, abonos] = await Promise.all([
    db.fiados.where('deudor_id').equals(deudorId).toArray(),
    db.abonos.where('deudor_id').equals(deudorId).toArray(),
  ])
  return resumirDeudor(deudor, fiados, abonos, fecha)
}

// RN-32: el teléfono identifica a la persona dentro del negocio
export async function deudorConTelefono(telefono: string): Promise<Deudor | undefined> {
  const numeros = normalizarTelefono(telefono)
  if (!numeros) return undefined
  return db.deudores.where('telefono').equals(numeros).first()
}

// RF-43: quién abonó ese día y cuánto (solo lo ve el dueño)
export async function abonosDelDia(fecha: string): Promise<{ abono: Abono; nombre: string }[]> {
  const abonos = (await db.abonos.where('fecha').equals(fecha).toArray()).filter((a) => !a.anulado_en)
  const deudores = await db.deudores.bulkGet(abonos.map((a) => a.deudor_id))
  return abonos.map((abono, i) => ({ abono, nombre: deudores[i] ? nombreDeudor(deudores[i]) : 'Cliente' }))
}

// ---------- Fiados ----------

export interface DatosFiado {
  fecha: string
  descripcion: string
  valor: number
  fecha_pago: string
}

// RF-34: fiado a una persona nueva. La casilla del aviso de privacidad es obligatoria (RN-41)
export async function fiarAPersonaNueva(negocioId: string, persona: { nombre: string; telefono: string }, datos: DatosFiado): Promise<string> {
  const deudorId = nuevoId()
  await db.transaction('rw', db.deudores, db.fiados, async () => {
    await db.deudores.add({
      id: deudorId,
      negocio_id: negocioId,
      nombre: persona.nombre.trim(),
      telefono: normalizarTelefono(persona.telefono),
      autorizacion_en: new Date().toISOString(),
      anonimizado_en: null,
      version: null,
      pendiente: 1,
    })
    await db.fiados.add(nuevoFiado(negocioId, deudorId, datos))
  })
  return deudorId
}

// RF-35: otro fiado a alguien que ya está registrado; su saldo se suma (RN-21, RN-22)
export async function agregarFiado(negocioId: string, deudorId: string, datos: DatosFiado): Promise<void> {
  await db.fiados.add(nuevoFiado(negocioId, deudorId, datos))
}

function nuevoFiado(negocioId: string, deudorId: string, datos: DatosFiado): Fiado {
  return {
    id: nuevoId(),
    negocio_id: negocioId,
    deudor_id: deudorId,
    fecha: datos.fecha,
    descripcion: datos.descripcion.trim(),
    valor: datos.valor,
    fecha_pago: datos.fecha_pago,
    anulado_en: null,
    motivo_anulacion: null,
    version: null,
    pendiente: 1,
  }
}

// RF-40: se corrige la descripción o la fecha de pago; el valor no (se anula y se registra de nuevo)
export async function corregirFiado(id: string, cambios: Pick<Fiado, 'descripcion' | 'fecha_pago'>): Promise<void> {
  await db.fiados.update(id, { descripcion: cambios.descripcion.trim(), fecha_pago: cambios.fecha_pago, pendiente: 1 })
}

// RF-40: nombre y teléfono del deudor
export async function corregirDeudor(id: string, cambios: { nombre: string; telefono: string }): Promise<void> {
  await db.deudores.update(id, { nombre: cambios.nombre.trim(), telefono: normalizarTelefono(cambios.telefono), pendiente: 1 })
}

// RN-36: no se borra; queda tachado con el motivo
export async function anularFiado(id: string, motivo: string): Promise<void> {
  await db.fiados.update(id, { anulado_en: new Date().toISOString(), motivo_anulacion: motivo, pendiente: 1 })
}

// ---------- Abonos ----------

export interface DatosAbono {
  valor: number
  fecha: string
  nota: string | null
  pago_id?: string | null
}

// RF-38. El abono no se suma al total del día: ya está en el efectivo o en el Nequi (RN-18).
// Que no pase del saldo (RN-19) lo revisa la pantalla antes de guardar.
export async function registrarAbono(negocioId: string, deudorId: string, datos: DatosAbono): Promise<void> {
  await db.abonos.add(nuevoAbono(negocioId, deudorId, datos))
}

export function nuevoAbono(negocioId: string, deudorId: string, datos: DatosAbono): Abono {
  return {
    id: nuevoId(),
    negocio_id: negocioId,
    deudor_id: deudorId,
    pago_id: datos.pago_id ?? null,
    fecha: datos.fecha,
    valor: datos.valor,
    nota: datos.nota?.trim() || null,
    anulado_en: null,
    motivo_anulacion: null,
    version: null,
    pendiente: 1,
  }
}

// RF-39 / RN-29: marcar como pagado = un abono por todo el saldo, con la fecha de hoy
export async function marcarPagado(negocioId: string, deudorId: string): Promise<number> {
  const resumen = await resumenDe(deudorId)
  if (!resumen || resumen.saldo === 0) return 0
  await registrarAbono(negocioId, deudorId, { valor: resumen.saldo, fecha: hoy(), nota: 'Pagó todo' })
  return resumen.saldo
}

// RN-36: si el día del abono ya tenía cierre, el cierre queda como modificado. Devuelve true en ese caso.
export async function anularAbono(id: string, motivo: string): Promise<boolean> {
  return db.transaction('rw', db.abonos, db.cierres, async () => {
    const abono = await db.abonos.get(id)
    if (!abono || abono.anulado_en) return false
    await db.abonos.update(id, { anulado_en: new Date().toISOString(), motivo_anulacion: motivo, pendiente: 1 })
    return marcarModificado(abono.fecha)
  })
}

// ---------- Borrar datos (RF-53, RN-42) ----------

// Solo si ya no debe nada: se borran nombre y teléfono y se conservan los valores
export async function borrarDatosDeudor(id: string): Promise<boolean> {
  const resumen = await resumenDe(id)
  if (!resumen || resumen.saldo > 0) return false
  await db.deudores.update(id, { nombre: null, telefono: null, anonimizado_en: new Date().toISOString(), pendiente: 1 })
  return true
}

function agrupar<T extends { deudor_id: string }>(registros: T[]): Map<string, T[]> {
  const mapa = new Map<string, T[]>()
  for (const r of registros) mapa.set(r.deudor_id, [...(mapa.get(r.deudor_id) ?? []), r])
  return mapa
}
