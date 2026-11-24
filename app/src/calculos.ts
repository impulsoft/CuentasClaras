// Cálculos del cierre de caja. Funciones puras: no leen la base ni la pantalla.

export interface DatosCierre {
  tipo: 'normal' | 'historico'
  no_abrio: boolean
  base: number | null
  efectivo: number | null
  salida_caja: number
  total_historico: number | null
  nequi_historico?: number | null // RN-35: si se conoce
}

export interface DatosPago {
  medio: 'nequi' | 'otro'
  estado: 'por_leer' | 'por_confirmar' | 'confirmado'
  valor: number | null
  anulado_en: string | null
}

export type EstadoDia = 'cerrado' | 'sin_cierre' | 'no_abrio' | 'futuro'

const ZONA = 'America/Bogota' // RNF-13

// ---------- Pesos ----------

// "$450.000": separador de miles con punto, sin decimales (RNF-05). Se hace a mano porque
// Intl en español no separa los números de 4 cifras ("4500") y en WebViews viejos varía.
export function pesos(valor: number): string {
  const signo = valor < 0 ? '-' : ''
  return `${signo}$${String(Math.abs(Math.round(valor))).replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`
}

// "410 mil", "5,6 millones": donde hay poco espacio (la gráfica), como lo dice la gente (RNF-06)
export function pesosCortos(valor: number): string {
  if (Math.abs(valor) >= 999_500) {
    const millones = (valor / 1_000_000).toFixed(1).replace('.', ',').replace(',0', '')
    return `${millones} ${millones === '1' ? 'millón' : 'millones'}`
  }
  if (Math.abs(valor) >= 1_000) {
    return `${Math.round(valor / 1_000)} mil`
  }
  return pesos(valor)
}

// Casillas del calendario: "431" con "mil" debajo; "1.250" mil para un día de más de un millón
export function valorEnMiles(valor: number): { texto: string; unidad: string } {
  if (Math.abs(valor) < 1_000) return { texto: pesos(valor), unidad: '' }
  return { texto: pesos(Math.round(valor / 1_000)).replace('$', ''), unidad: 'mil' }
}

// Lo que el dueño escribe en un campo de pesos: solo dígitos ("450.000" → 450000)
export function leerPesos(texto: string): number | null {
  const digitos = texto.replace(/\D/g, '')
  return digitos === '' ? null : Number(digitos)
}

// ---------- Totales (RN-02, RN-03) ----------

// RN-02: efectivo contado + plata que sacó de la caja - base
export function vendidoEnEfectivo(cierre: DatosCierre): number {
  if (cierre.base === null || cierre.efectivo === null) return 0
  return cierre.efectivo + cierre.salida_caja - cierre.base
}

// Solo cuentan los pagos confirmados y no anulados (RN-04, RN-36)
export function sumaPagos(pagos: DatosPago[], medio: 'nequi' | 'otro'): number {
  return pagos
    .filter((p) => p.medio === medio && p.estado === 'confirmado' && p.anulado_en === null && p.valor !== null)
    .reduce((suma, p) => suma + p.valor!, 0)
}

export interface TotalesDia {
  efectivo: number
  nequi: number
  otros: number
  sinDetalle: number // RN-35: la parte de un cierre histórico que no se pudo separar
  total: number
}

// RN-03: total del día = vendido en efectivo + Nequi + otros pagos
export function totalesDelDia(cierre: DatosCierre | undefined, pagos: DatosPago[]): TotalesDia {
  if (cierre?.tipo === 'historico') {
    // RN-35: solo el total y, si se conoce, el Nequi; lo demás sale como "sin detalle"
    const total = cierre.total_historico ?? 0
    const nequi = Math.min(cierre.nequi_historico ?? 0, total)
    return { efectivo: 0, nequi, otros: 0, sinDetalle: total - nequi, total }
  }
  const efectivo = cierre && !cierre.no_abrio ? vendidoEnEfectivo(cierre) : 0
  const nequi = sumaPagos(pagos, 'nequi')
  const otros = sumaPagos(pagos, 'otro')
  return { efectivo, nequi, otros, sinDetalle: 0, total: efectivo + nequi + otros }
}

// RN-06: el efectivo contado más lo que sacó no debería ser menor que la base
export function avisoBase(cierre: Pick<DatosCierre, 'base' | 'efectivo' | 'salida_caja'>): boolean {
  if (cierre.base === null || cierre.efectivo === null) return false
  return cierre.efectivo + cierre.salida_caja < cierre.base
}

// ---------- Fechas (hora de Colombia, RNF-13) ----------

function partes(fecha: Date): Record<string, string> {
  const formato = new Intl.DateTimeFormat('en-US', {
    timeZone: ZONA,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
  })
  return Object.fromEntries(formato.formatToParts(fecha).map((p) => [p.type, p.value]))
}

// "2026-11-18"
export function hoy(ahora: Date = new Date()): string {
  const p = partes(ahora)
  return `${p.year}-${p.month}-${p.day}`
}

// "08:42"
export function horaActual(ahora: Date = new Date()): string {
  const p = partes(ahora)
  return `${p.hour === '24' ? '00' : p.hour}:${p.minute}`
}

const MESES = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre']

export function nombreMes(mes: number): string {
  return MESES[mes - 1]!
}

// "18 de noviembre"
export function fechaLarga(fecha: string): string {
  const [, mes, dia] = fecha.split('-').map(Number)
  return `${dia} de ${nombreMes(mes!)}`
}

// "5 nov" (listas de deudores, donde hay poco espacio)
export function fechaCorta(fecha: string): string {
  const [, mes, dia] = fecha.split('-').map(Number)
  return `${dia} ${nombreMes(mes!).slice(0, 3)}`
}

// Suma días a una fecha "AAAA-MM-DD" (para sugerir la fecha de pago de un fiado)
export function sumarDias(fecha: string, dias: number): string {
  const d = new Date(`${fecha}T12:00:00Z`)
  d.setUTCDate(d.getUTCDate() + dias)
  return d.toISOString().slice(0, 10)
}

const dosCifras = (n: number) => String(n).padStart(2, '0')

export function fechaDe(anio: number, mes: number, dia: number): string {
  return `${anio}-${dosCifras(mes)}-${dosCifras(dia)}`
}

// Semanas del mes para el calendario, de lunes a domingo; null en las casillas vacías
export function semanasDelMes(anio: number, mes: number): (string | null)[][] {
  const dias = new Date(Date.UTC(anio, mes, 0)).getUTCDate()
  const primerDia = (new Date(Date.UTC(anio, mes - 1, 1)).getUTCDay() + 6) % 7 // 0 = lunes
  const casillas: (string | null)[] = Array(primerDia).fill(null)
  for (let dia = 1; dia <= dias; dia++) casillas.push(fechaDe(anio, mes, dia))
  while (casillas.length % 7 !== 0) casillas.push(null)

  const semanas: (string | null)[][] = []
  for (let i = 0; i < casillas.length; i += 7) semanas.push(casillas.slice(i, i + 7))
  return semanas
}

export function mesVecino(anio: number, mes: number, paso: 1 | -1): { anio: number; mes: number } {
  const indice = anio * 12 + (mes - 1) + paso
  return { anio: Math.floor(indice / 12), mes: (indice % 12) + 1 }
}

// ---------- Estado de cada día del calendario (RF-14, RN-31) ----------

export function estadoDelDia(fecha: string, fechaHoy: string, cierre: DatosCierre | undefined): EstadoDia {
  if (fecha > fechaHoy) return 'futuro'
  if (cierre?.no_abrio) return 'no_abrio'
  return cierre ? 'cerrado' : 'sin_cierre'
}

export interface ResumenDia {
  fecha: string
  estado: EstadoDia
  total: number
}

// RF-22: los días sin cierre y los "No abrió" no cuentan para el mejor ni el peor día
export function mejorYPeor(dias: ResumenDia[]): { mejor: string | null; peor: string | null } {
  const cerrados = dias.filter((d) => d.estado === 'cerrado')
  if (cerrados.length < 2) return { mejor: null, peor: null }
  const orden = [...cerrados].sort((a, b) => a.total - b.total)
  return { mejor: orden[orden.length - 1]!.fecha, peor: orden[0]!.fecha }
}

// Total del mes: días cerrados y los pagos que llegaron en días "No abrió" (RN-31).
// Los pagos de un día sin cierre entran cuando se registra el cierre (RN-30).
export function totalDelMes(dias: ResumenDia[]): number {
  return dias.filter((d) => d.estado === 'cerrado' || d.estado === 'no_abrio').reduce((suma, d) => suma + d.total, 0)
}
