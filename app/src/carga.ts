import { db } from './db'
import { agregarPago, guardarCierre, guardarHistorico, marcarNoAbrio } from './datos'

/*
 * Carga de datos viejos desde Excel (RF-27) o desde una foto del cuaderno (RF-28).
 * Nada se guarda hasta que el dueño revisa y confirma (RN-08, RF-29). Un día que ya tiene cierre
 * se omite, a menos que el dueño escoja reemplazarlo (RN-33).
 */

export interface FilaCarga {
  id: number
  origen: string // "Fila 5" del Excel o el renglón leído del cuaderno
  fecha: string
  tipo: 'normal' | 'historico' | 'no_abrio' | null
  base: number | null
  efectivo: number | null
  salida_caja: number | null
  nequi: number | null
  otros: number | null
  total: number | null
  incluir: boolean
  reemplazar: boolean // RN-33: por defecto se omite
}

export interface RevisionFila {
  errores: string[]
  yaTiene: boolean // ese día ya tiene cierre
}

const FECHA = /^\d{4}-\d{2}-\d{2}$/

function fechaValida(fecha: string): boolean {
  if (!FECHA.test(fecha)) return false
  const d = new Date(`${fecha}T12:00:00Z`)
  return !Number.isNaN(d.getTime()) && d.toISOString().slice(0, 10) === fecha
}

// Se vuelve a revisar cada vez que el dueño corrige algo
export function revisarFilas(filas: FilaCarga[], conCierre: Set<string>, hoy: string): Map<number, RevisionFila> {
  const vistas = new Map<string, string>()
  const revision = new Map<number, RevisionFila>()
  for (const f of filas) {
    const errores: string[] = []
    if (!fechaValida(f.fecha)) errores.push('La fecha no es válida.')
    else if (f.fecha > hoy) errores.push('La fecha es un día que no ha llegado.') // RN-07
    else if (f.incluir && vistas.has(f.fecha)) errores.push(`Ese día está repetido (${vistas.get(f.fecha)}).`)
    if (f.incluir && fechaValida(f.fecha) && !vistas.has(f.fecha)) vistas.set(f.fecha, f.origen)

    if (f.tipo === null) errores.push('La fila no tiene valores.')
    if (f.tipo === 'normal' && (f.base === null || f.efectivo === null)) errores.push('Falta la base inicial o el efectivo contado.')
    if (f.tipo === 'historico' && !f.total) errores.push('Escriba el total del día.')

    revision.set(f.id, { errores, yaTiene: conCierre.has(f.fecha) })
  }
  return revision
}

export async function diasConCierre(): Promise<Set<string>> {
  const cierres = await db.cierres.toArray()
  return new Set(cierres.map((c) => c.fecha))
}

// Guarda las filas incluidas y sin errores. Devuelve cuántas se guardaron.
export async function guardarFilas(negocioId: string, filas: FilaCarga[], hoy: string): Promise<number> {
  const conCierre = await diasConCierre()
  const revision = revisarFilas(filas, conCierre, hoy)
  let guardadas = 0

  for (const f of filas) {
    const r = revision.get(f.id)!
    if (!f.incluir || r.errores.length || (r.yaTiene && !f.reemplazar)) continue

    if (f.tipo === 'normal') {
      // Si se reemplaza un día que ya tenía cierre, se conservan sus pagos (no se cargan otra vez)
      if (!r.yaTiene) {
        if (f.nequi) await agregarPago(negocioId, f.fecha, { medio: 'nequi', valor: f.nequi, hora: null })
        if (f.otros) await agregarPago(negocioId, f.fecha, { medio: 'otro', valor: f.otros, hora: null })
      }
      await guardarCierre(negocioId, f.fecha, { base: f.base!, efectivo: f.efectivo!, salida_caja: f.salida_caja ?? 0 })
    } else if (f.tipo === 'historico') {
      await guardarHistorico(negocioId, f.fecha, f.total!, f.nequi) // RN-35
    } else if (f.tipo === 'no_abrio') {
      await marcarNoAbrio(negocioId, f.fecha)
    }
    guardadas++
  }
  return guardadas
}
