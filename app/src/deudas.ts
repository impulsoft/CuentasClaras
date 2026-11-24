import type { Abono, Deudor, Fiado } from './db'
import { fechaCorta } from './calculos'

/*
 * Cálculos del módulo de deudores (sección 4.2). Funciones puras, sin base de datos,
 * para poder probarlas y usarlas igual en la lista, el detalle y el cierre del día.
 */

export type EstadoDeudor = 'vencido' | 'abono' | 'pendiente' | 'pagado'

export const NOMBRE_ESTADO: Record<EstadoDeudor, string> = {
  vencido: 'Vencido',
  abono: 'Abonó',
  pendiente: 'Pendiente',
  pagado: 'Pagado',
}

export interface FiadoConSaldo {
  fiado: Fiado
  abonado: number // lo que le tocó de los abonos (RN-28)
  saldo: number
}

export interface ResumenDeudor {
  deudor: Deudor
  nombre: string // "Cliente anónimo" si se borraron sus datos (RN-42)
  saldo: number // RN-17
  estado: EstadoDeudor
  fiados: FiadoConSaldo[] // los que cuentan, del más antiguo al más nuevo
  abonos: Abono[] // los que cuentan, del más antiguo al más nuevo
  anulados: (Fiado | Abono)[] // RN-36: quedan tachados en el historial
  debia: number // lo fiado en los fiados que todavía tienen saldo ("Debía $30.000")
  fioEl: string | null // fecha del fiado más antiguo con saldo (o del último, si ya pagó)
  pagaEl: string | null // fecha de pago más cercana entre los fiados con saldo
  pagoEl: string | null // fecha del último abono, cuando ya pagó todo
}

const porFecha = <T extends { fecha: string; id: string }>(a: T, b: T) => a.fecha.localeCompare(b.fecha) || a.id.localeCompare(b.id)

// RN-28: cada abono cubre primero el fiado más antiguo; lo que sobra pasa al siguiente
export function repartirAbonos(fiados: Fiado[], totalAbonado: number): FiadoConSaldo[] {
  let disponible = totalAbonado
  return [...fiados].sort(porFecha).map((fiado) => {
    const abonado = Math.min(fiado.valor, disponible)
    disponible -= abonado
    return { fiado, abonado, saldo: fiado.valor - abonado }
  })
}

export function resumirDeudor(deudor: Deudor, fiadosDelDeudor: Fiado[], abonosDelDeudor: Abono[], hoy: string): ResumenDeudor {
  const fiados = fiadosDelDeudor.filter((f) => !f.anulado_en)
  const abonos = abonosDelDeudor.filter((a) => !a.anulado_en).sort(porFecha)
  const totalAbonado = abonos.reduce((suma, a) => suma + a.valor, 0)
  const conSaldo = repartirAbonos(fiados, totalAbonado)
  const debiendo = conSaldo.filter((f) => f.saldo > 0)
  const saldo = Math.max(0, debiendo.reduce((suma, f) => suma + f.saldo, 0))

  // RN-37: Pagado → Vencido → Abonó → Pendiente
  let estado: EstadoDeudor = 'pendiente'
  if (saldo === 0) estado = 'pagado'
  else if (debiendo.some((f) => f.fiado.fecha_pago < hoy)) estado = 'vencido' // RN-23
  else if (debiendo.some((f) => f.abonado > 0)) estado = 'abono'

  const pagaEl = debiendo.map((f) => f.fiado.fecha_pago).sort()[0] ?? null
  return {
    deudor,
    nombre: nombreDeudor(deudor),
    saldo,
    estado,
    fiados: conSaldo,
    abonos,
    anulados: [...fiadosDelDeudor, ...abonosDelDeudor].filter((r) => r.anulado_en).sort(porFecha),
    debia: debiendo.reduce((suma, f) => suma + f.fiado.valor, 0),
    fioEl: debiendo[0]?.fiado.fecha ?? conSaldo[conSaldo.length - 1]?.fiado.fecha ?? null,
    pagaEl,
    pagoEl: saldo === 0 ? (abonos[abonos.length - 1]?.fecha ?? null) : null,
  }
}

/*
 * Cuándo paga, como lo dice la gente: "venció hace 12 días", "venció ayer", "paga hoy",
 * "paga mañana" o "paga el 26 nov". Así el dueño ve de una vez a quién cobrarle primero.
 */
export function textoPlazo(pagaEl: string, vencido: boolean, hoy: string): string {
  const dias = Math.round((Date.parse(`${hoy}T12:00:00Z`) - Date.parse(`${pagaEl}T12:00:00Z`)) / 86_400_000)
  if (vencido) {
    if (dias <= 1) return 'venció ayer'
    if (dias < 60) return `venció hace ${dias} días`
    return `venció hace ${Math.floor(dias / 30)} meses`
  }
  if (dias === 0) return 'paga hoy'
  if (dias === -1) return 'paga mañana'
  return `paga el ${fechaCorta(pagaEl)}`
}

// Debe y la fecha de pago es hoy: se resalta en la lista para cobrarle hoy
export function pagaHoy(d: Pick<ResumenDeudor, 'estado' | 'pagaEl'>, hoy: string): boolean {
  return d.estado !== 'pagado' && d.estado !== 'vencido' && d.pagaEl === hoy
}

export function nombreDeudor(deudor: Pick<Deudor, 'nombre'>): string {
  return deudor.nombre?.trim() || 'Cliente anónimo'
}

// RF-41: primero los vencidos, luego los que deben (por la fecha de pago más cercana) y al final los que ya pagaron
const ORDEN: Record<EstadoDeudor, number> = { vencido: 0, abono: 1, pendiente: 1, pagado: 2 }
export function ordenarDeudores(lista: ResumenDeudor[]): ResumenDeudor[] {
  return [...lista].sort(
    (a, b) =>
      ORDEN[a.estado] - ORDEN[b.estado] ||
      (a.pagaEl ?? '9999').localeCompare(b.pagaEl ?? '9999') ||
      (b.pagoEl ?? '').localeCompare(a.pagoEl ?? '') ||
      a.nombre.localeCompare(b.nombre),
  )
}

// RF-32: total por cobrar, cuántas personas deben y cuántas tienen deudas vencidas (cuentan deudores, RN-37)
export function totalPorCobrar(lista: ResumenDeudor[]): { total: number; personas: number; vencidas: number } {
  return {
    total: lista.reduce((suma, d) => suma + d.saldo, 0),
    personas: lista.filter((d) => d.saldo > 0).length,
    vencidas: lista.filter((d) => d.estado === 'vencido').length,
  }
}

// RF-33: busca por nombre o teléfono, sin importar tildes, mayúsculas ni espacios del teléfono
export function coincideBusqueda(resumen: ResumenDeudor, busqueda: string): boolean {
  const texto = sinTildes(busqueda.trim())
  if (!texto) return true
  const numeros = busqueda.replace(/\D/g, '')
  return sinTildes(resumen.nombre).includes(texto) || (numeros.length >= 3 && (resumen.deudor.telefono ?? '').includes(numeros))
}

// RN-32: solo los números; "+57 310 456 7890" y "3104567890" son el mismo teléfono
export function normalizarTelefono(texto: string): string {
  let numeros = texto.replace(/\D/g, '')
  if (numeros.length === 12 && numeros.startsWith('57')) numeros = numeros.slice(2)
  return numeros
}

// "3104567890" → "310 456 7890"
export function telefonoLegible(telefono: string | null): string {
  if (!telefono) return ''
  return telefono.length === 10 ? `${telefono.slice(0, 3)} ${telefono.slice(3, 6)} ${telefono.slice(6)}` : telefono
}

// RN-39: si el pago es mayor que lo que debe, el abono cubre todo y lo que sobra es venta del día
export function repartirPago(valor: number, saldo: number): { abono: number; venta: number } {
  const abono = Math.min(valor, saldo)
  return { abono, venta: valor - abono }
}

/*
 * RF-18 / RN-39: busca al deudor cuyo nombre se parece al de quien pagó.
 * Nequi a veces oculta parte del nombre ("Bra*** Sti*** Fig*****"): cada parte con
 * asteriscos se compara como el comienzo de una palabra del nombre del deudor.
 */
export function deudorSugerido(nombrePagador: string | null, candidatos: ResumenDeudor[]): ResumenDeudor | undefined {
  const partes = palabras(nombrePagador ?? '')
  if (!partes.length) return undefined

  let mejor: { resumen: ResumenDeudor; puntos: number } | undefined
  for (const resumen of candidatos) {
    if (!resumen.deudor.nombre) continue
    const delDeudor = palabras(resumen.deudor.nombre).map((p) => p.texto)
    const puntos = partes.filter((p) => delDeudor.some((d) => (p.oculta ? d.startsWith(p.texto) : d === p.texto))).length
    // Con nombre y apellido basta; si el deudor tiene una sola palabra registrada, esa palabra debe coincidir
    const suficiente = puntos >= Math.min(2, delDeudor.length)
    if (suficiente && (!mejor || puntos > mejor.puntos)) mejor = { resumen, puntos }
  }
  return mejor?.resumen
}

function palabras(nombre: string): { texto: string; oculta: boolean }[] {
  return sinTildes(nombre)
    .split(/\s+/)
    .map((p) => ({ texto: p.replace(/[^a-zñ]/g, ''), oculta: p.includes('*') }))
    .filter((p) => p.texto.length >= 2)
}

function sinTildes(texto: string): string {
  return texto
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
}
