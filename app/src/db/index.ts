import Dexie, { type EntityTable } from 'dexie'

/*
 * Base de datos local del celular (IndexedDB). Guarda lo que el dueño registra sin
 * internet hasta que se sincroniza (RNF-01, RF-30, RF-45). Los campos son los mismos
 * de las tablas de la API; los valores van en pesos enteros (RN-06).
 */

export interface Cierre {
  id: string
  negocio_id: string
  fecha: string // AAAA-MM-DD
  tipo: 'normal' | 'historico' // RN-35
  no_abrio: boolean // RN-31
  base: number | null
  efectivo: number | null
  salida_caja: number // RN-38
  total_historico: number | null
  nequi_historico: number | null
  modificado_en: string | null
  version: string | null // última versión conocida del servidor (RNF-12)
  pendiente: 0 | 1 // 1 = falta enviarlo al servidor
}

export interface Pago {
  id: string
  negocio_id: string
  fecha: string
  hora: string | null
  medio: 'nequi' | 'otro' // RNF-14
  origen: 'foto' | 'pantallazo' | 'manual'
  estado: 'por_leer' | 'por_confirmar' | 'confirmado'
  valor: number | null
  nombre_pagador: string | null
  referencia: string | null
  foto_ruta?: string | null // foto del comprobante en el servidor (RN-43)
  anulado_en: string | null // RN-36
  motivo_anulacion: string | null
  version: string | null
  pendiente: 0 | 1
}

export interface Deudor {
  id: string
  negocio_id: string
  nombre: string | null // vacío si se borraron sus datos (RN-42)
  telefono: string | null // solo números (RN-32)
  autorizacion_en: string | null // RN-41
  anonimizado_en: string | null
  version: string | null
  pendiente: 0 | 1
}

export interface Fiado {
  id: string
  negocio_id: string
  deudor_id: string
  fecha: string
  descripcion: string
  valor: number
  fecha_pago: string // RN-24
  anulado_en: string | null // RN-36
  motivo_anulacion: string | null
  version: string | null
  pendiente: 0 | 1
}

export interface Abono {
  id: string
  negocio_id: string
  deudor_id: string
  pago_id: string | null // abono que llegó por Nequi (RN-39)
  fecha: string
  valor: number
  nota: string | null
  anulado_en: string | null // RN-36
  motivo_anulacion: string | null
  version: string | null
  pendiente: 0 | 1
}

// Un cierre que cambió en otro dispositivo mientras este tenía cambios sin enviar (RNF-12)
export interface Conflicto {
  id: string // id del cierre en el servidor
  fecha: string
  local: Cierre
  servidor: Cierre
}

// Foto o pantallazo de un comprobante Nequi que todavía no es un pago (RF-17, RF-18).
// Sin internet queda "por_leer"; cuando el OCR la lee queda "por_confirmar" hasta que el dueño la revise.
export interface DatosLeidos {
  valor: number | null
  fecha: string | null
  hora: string | null
  nombre: string | null
  referencia: string | null
}

export interface FotoComprobante {
  id: string // será el id del pago
  creado_en: string
  origen: 'foto' | 'pantallazo'
  imagen: Blob
  estado: 'por_leer' | 'por_confirmar'
  foto_ruta: string | null
  leido: boolean
  campos: DatosLeidos | null
  repetido: boolean // RN-05: la referencia ya está cargada
}

export interface Meta {
  clave: 'desde' | 'ultima_sincronizacion' | 'estado_negocio' | 'negocio'
  valor: string
}

export const db = new Dexie('cierre-de-caja') as Dexie & {
  cierres: EntityTable<Cierre, 'id'>
  pagos: EntityTable<Pago, 'id'>
  deudores: EntityTable<Deudor, 'id'>
  fiados: EntityTable<Fiado, 'id'>
  abonos: EntityTable<Abono, 'id'>
  conflictos: EntityTable<Conflicto, 'id'>
  meta: EntityTable<Meta, 'clave'>
  fotos: EntityTable<FotoComprobante, 'id'>
}

db.version(1).stores({
  cierres: 'id, &[negocio_id+fecha], updated_at', // RN-01
  pagos: 'id, [negocio_id+fecha], referencia, updated_at',
  deudores: 'id, [negocio_id+telefono], updated_at', // RN-32
  fiados: 'id, deudor_id, fecha, updated_at',
  abonos: 'id, deudor_id, pago_id, fecha, updated_at',
})

// v2: sincronización (fase 1)
db.version(2).stores({
  cierres: 'id, &[negocio_id+fecha], fecha, pendiente',
  pagos: 'id, [negocio_id+fecha], fecha, referencia, pendiente',
  conflictos: 'id, fecha',
  meta: 'clave',
})

// v3: fotos de comprobantes (fase 2)
db.version(3).stores({
  fotos: 'id, estado, creado_en',
})

// v4: deudores (fase 3)
db.version(4).stores({
  deudores: 'id, telefono, pendiente',
  fiados: 'id, deudor_id, fecha, pendiente',
  abonos: 'id, deudor_id, pago_id, fecha, pendiente',
})

// Al cerrar sesión se borra todo: otro dueño podría entrar en el mismo celular
export async function borrarDatosLocales(): Promise<void> {
  await Promise.all(db.tables.map((tabla) => tabla.clear()))
}

// Los id (UUID v4) los genera el dispositivo para poder guardar sin internet.
// crypto.randomUUID no existe en WebViews viejos de Android 7, por eso la alternativa.
export function nuevoId(): string {
  if (typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  const b = crypto.getRandomValues(new Uint8Array(16))
  b[6] = (b[6]! & 0x0f) | 0x40
  b[8] = (b[8]! & 0x3f) | 0x80
  const h = Array.from(b, (n) => n.toString(16).padStart(2, '0')).join('')
  return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`
}
