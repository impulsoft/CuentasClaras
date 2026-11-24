import { estadoDelDia, fechaDe, mejorYPeor, totalesDelDia, type DatosCierre, type DatosPago, type EstadoDia } from './calculos'

/*
 * Análisis diario, mensual y anual (RF-21 a RF-25). Funciones puras: las usa el dueño con los
 * datos de su celular y el admin con los que trae del servidor.
 * Un día cuenta cuando tiene cierre; si está marcado "No abrió", cuentan los pagos que le llegaron
 * (RN-31). Los pagos de un día sin cierre entran cuando se registre el cierre (RN-30).
 */

export interface CierreAnalisis extends DatosCierre {
  fecha: string
}

export interface PagoAnalisis extends DatosPago {
  fecha: string
}

export interface Desglose {
  efectivo: number
  nequi: number
  otros: number
  sinDetalle: number // RN-35
  total: number
}

export interface DiaAnalisis {
  fecha: string
  estado: EstadoDia
  desglose: Desglose
}

export interface PeriodoAnalisis {
  clave: string // "2026-11-18", "2026-11" o "2026"
  desglose: Desglose
  dias: number // días con cierre
}

const vacio = (): Desglose => ({ efectivo: 0, nequi: 0, otros: 0, sinDetalle: 0, total: 0 })

function sumar(a: Desglose, b: Desglose): Desglose {
  return {
    efectivo: a.efectivo + b.efectivo,
    nequi: a.nequi + b.nequi,
    otros: a.otros + b.otros,
    sinDetalle: a.sinDetalle + b.sinDetalle,
    total: a.total + b.total,
  }
}

// Días con cierre, cada uno con su desglose por medio de pago (RF-25)
export function diasConCierre(cierres: CierreAnalisis[], pagos: PagoAnalisis[], hoy: string): Map<string, DiaAnalisis> {
  const pagosPorDia = new Map<string, PagoAnalisis[]>()
  for (const p of pagos) pagosPorDia.set(p.fecha, [...(pagosPorDia.get(p.fecha) ?? []), p])

  const dias = new Map<string, DiaAnalisis>()
  for (const cierre of cierres) {
    if (cierre.fecha > hoy) continue
    const { efectivo, nequi, otros, sinDetalle, total } = totalesDelDia(cierre, pagosPorDia.get(cierre.fecha) ?? [])
    dias.set(cierre.fecha, { fecha: cierre.fecha, estado: estadoDelDia(cierre.fecha, hoy, cierre), desglose: { efectivo, nequi, otros, sinDetalle, total } })
  }
  return dias
}

// RF-22: cada día del mes, el total del mes y el día de mayor y de menor venta
export function analisisDiario(dias: Map<string, DiaAnalisis>, anio: number, mes: number, hoy: string) {
  const ultimo = new Date(Date.UTC(anio, mes, 0)).getUTCDate()
  const lista: (DiaAnalisis & { total: number })[] = []
  for (let d = 1; d <= ultimo; d++) {
    const fecha = fechaDe(anio, mes, d)
    const dia = dias.get(fecha)
    const estado = dia?.estado ?? estadoDelDia(fecha, hoy, undefined)
    lista.push({ fecha, estado, desglose: dia?.desglose ?? vacio(), total: dia?.desglose.total ?? 0 })
  }
  const { mejor, peor } = mejorYPeor(lista)
  return { dias: lista, mejor, peor, desglose: lista.reduce((s, d) => sumar(s, d.desglose), vacio()), diasConCierre: lista.filter((d) => d.estado === 'cerrado').length }
}

/*
 * RF-23: total de cada mes del año y el mes de mayor y de menor venta. Para el de menor venta solo
 * cuentan los meses que ya terminaron y que tienen cierres: el mes en curso todavía no está completo.
 */
export function analisisMensual(dias: Map<string, DiaAnalisis>, anio: number, hoy: string) {
  const meses: PeriodoAnalisis[] = Array.from({ length: 12 }, (_, i) => ({ clave: `${anio}-${String(i + 1).padStart(2, '0')}`, desglose: vacio(), dias: 0 }))
  for (const dia of dias.values()) {
    if (!dia.fecha.startsWith(`${anio}-`)) continue
    const m = meses[Number(dia.fecha.slice(5, 7)) - 1]!
    m.desglose = sumar(m.desglose, dia.desglose)
    if (dia.estado === 'cerrado') m.dias++
  }
  const conCierres = meses.filter((m) => m.dias > 0)
  const terminados = conCierres.filter((m) => m.clave < hoy.slice(0, 7))
  const mejor = conCierres.length >= 2 ? conCierres.reduce((a, b) => (b.desglose.total > a.desglose.total ? b : a)).clave : null
  const peor = terminados.length >= 2 ? terminados.reduce((a, b) => (b.desglose.total < a.desglose.total ? b : a)).clave : null
  return { meses, mejor, peor: peor === mejor ? null : peor, desglose: meses.reduce((s, m) => sumar(s, m.desglose), vacio()) }
}

/*
 * RF-24: para comparar dos años solo se usan los meses que tienen cierres en los dos. Si uno de esos
 * meses es el mes en curso, en los dos años se cuenta hasta el mismo día de hoy. Sin esto, un año
 * con 4 meses cargados contra otro con 11 parece que vendió más del doble.
 */
export interface PeriodoComun {
  meses: number[] // 1 a 12
  hastaDia: number | null // el mes en curso se cuenta hasta este día en los dos años
}

function mesesConCierres(dias: Map<string, DiaAnalisis>, anio: string): Set<number> {
  const meses = new Set<number>()
  for (const dia of dias.values()) {
    if (dia.estado === 'cerrado' && dia.fecha.startsWith(`${anio}-`)) meses.add(Number(dia.fecha.slice(5, 7)))
  }
  return meses
}

export function periodoComun(dias: Map<string, DiaAnalisis>, antes: string, despues: string, hoy: string): PeriodoComun {
  const deAntes = mesesConCierres(dias, antes)
  const meses = [...mesesConCierres(dias, despues)].filter((m) => deAntes.has(m)).sort((a, b) => a - b)
  const mesHoy = Number(hoy.slice(5, 7))
  const tocaHoy = (antes === hoy.slice(0, 4) || despues === hoy.slice(0, 4)) && meses.includes(mesHoy)
  return { meses, hastaDia: tocaHoy ? Number(hoy.slice(8, 10)) : null }
}

function sumarPeriodo(dias: Map<string, DiaAnalisis>, anio: string, periodo: PeriodoComun, hoy: string): Desglose {
  const mesHoy = Number(hoy.slice(5, 7))
  let total = vacio()
  for (const dia of dias.values()) {
    if (!dia.fecha.startsWith(`${anio}-`)) continue
    const mes = Number(dia.fecha.slice(5, 7))
    if (!periodo.meses.includes(mes)) continue
    if (periodo.hastaDia !== null && mes === mesHoy && Number(dia.fecha.slice(8, 10)) > periodo.hastaDia) continue
    total = sumar(total, dia.desglose)
  }
  return total
}

// "septiembre a noviembre", "marzo y mayo"; corto: "sep a nov"
const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']
export function textoMeses(meses: number[], corto = false): string {
  const nombre = (m: number) => (corto ? MESES[m - 1]!.slice(0, 3) : MESES[m - 1]!)
  if (!meses.length) return ''
  if (meses.length === 12) return 'todo el año'
  const seguidos = meses.every((m, i) => i === 0 || m === meses[i - 1]! + 1)
  if (meses.length === 1) return nombre(meses[0]!)
  if (seguidos) return `${nombre(meses[0]!)} a ${nombre(meses[meses.length - 1]!)}`
  return `${meses.slice(0, -1).map(nombre).join(', ')} y ${nombre(meses[meses.length - 1]!)}`
}

// RF-24: total de cada año y su cambio frente al anterior, con los mismos meses en los dos
export function analisisAnual(dias: Map<string, DiaAnalisis>, hoy: string) {
  const porAnio = new Map<string, PeriodoAnalisis>()
  for (const dia of dias.values()) {
    const clave = dia.fecha.slice(0, 4)
    const a = porAnio.get(clave) ?? { clave, desglose: vacio(), dias: 0 }
    a.desglose = sumar(a.desglose, dia.desglose)
    if (dia.estado === 'cerrado') a.dias++
    porAnio.set(clave, a)
  }
  const anios = [...porAnio.values()].sort((a, b) => a.clave.localeCompare(b.clave))
  return anios.map((a, i) => {
    const anterior = anios[i - 1]
    if (!anterior) return { ...a, cambio: null, periodo: null }
    const periodo = periodoComun(dias, anterior.clave, a.clave, hoy)
    const antes = sumarPeriodo(dias, anterior.clave, periodo, hoy).total
    const despues = sumarPeriodo(dias, a.clave, periodo, hoy).total
    return { ...a, cambio: antes > 0 ? (despues - antes) / antes : null, periodo }
  })
}

// RF-25: cada medio con su valor y su porcentaje del total (solo los que tienen algo)
export function porMedio(d: Desglose): { clave: keyof Omit<Desglose, 'total'>; nombre: string; valor: number; porcentaje: number }[] {
  const medios = [
    { clave: 'efectivo' as const, nombre: 'Efectivo' },
    { clave: 'nequi' as const, nombre: 'Nequi' },
    { clave: 'otros' as const, nombre: 'Otros pagos' },
    { clave: 'sinDetalle' as const, nombre: 'Sin detalle' },
  ]
  return medios
    .map((m) => ({ ...m, valor: d[m.clave], porcentaje: d.total > 0 ? d[m.clave] / d.total : 0 }))
    .filter((m) => m.valor > 0)
}

// "12,5 %"
export function porcentaje(valor: number): string {
  const p = Math.round(valor * 1000) / 10
  return `${String(p).replace('.', ',')} %`
}

// RF-24: dos años cualesquiera lado a lado, en total y por medio de pago, con el cambio del primero
// al segundo. Solo con los meses que tienen cierres en los dos (ver periodoComun).
export function compararAnios(dias: Map<string, DiaAnalisis>, antes: string, despues: string, hoy: string) {
  const periodo = periodoComun(dias, antes, despues, hoy)
  if (!periodo.meses.length) return { periodo, filas: [], soloTotal: false }
  const a = sumarPeriodo(dias, antes, periodo, hoy)
  const b = sumarPeriodo(dias, despues, periodo, hoy)
  const fila = (nombre: string, va: number, vb: number) => ({ nombre, antes: va, despues: vb, cambio: va > 0 ? (vb - va) / va : null })
  // Si en esos meses algún año tiene días cargados del cuaderno o de Excel solo con el total (RN-35),
  // comparar por medio de pago engaña ("Efectivo $0 → $20 millones"): solo se compara el total
  const soloTotal = a.sinDetalle > 0 || b.sinDetalle > 0
  const filas = [
    fila('Total vendido', a.total, b.total),
    ...(soloTotal
      ? []
      : [fila('Efectivo', a.efectivo, b.efectivo), fila('Nequi', a.nequi, b.nequi), fila('Otros pagos', a.otros, b.otros)].filter((f) => f.antes > 0 || f.despues > 0)),
  ]
  return { periodo, filas, soloTotal }
}
