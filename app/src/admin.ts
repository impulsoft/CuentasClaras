import { pedir } from './api'
import { fechaLarga, pesos } from './calculos'
import { useSesionStore } from './stores/sesion'

/*
 * Panel del admin (sección 4.1). Todo necesita internet (RNF-01): no se guarda en el celular.
 * El admin nunca recibe nombres ni teléfonos de los deudores (RN-26).
 */

export type EstadoSuscripcion = 'activa' | 'por_vencer' | 'vencida' | 'sin_pago'

export const NOMBRE_SUSCRIPCION: Record<EstadoSuscripcion, string> = {
  activa: 'Al día',
  por_vencer: 'Por vencer',
  vencida: 'Vencida',
  sin_pago: 'Sin pago',
}

// Clase de la etiqueta de color para cada estado (style.css)
export const COLOR_SUSCRIPCION: Record<EstadoSuscripcion, string> = {
  activa: 'cerrado',
  por_vencer: 'aviso',
  vencida: 'peligro',
  sin_pago: 'peligro',
}

export interface NegocioAdmin {
  id: string
  nombre: string
  telefono: string | null
  estado: 'activo' | 'bloqueado'
  suscripcion: { inicio_el: string | null; vence_el: string | null; estado: EstadoSuscripcion; dias: number | null }
  datos_se_borran_el: string | null
  datos_borrados_en: string | null
  ultimo_valor: number
  deudas: { por_cobrar: number; deudores: number; vencidos: number } // RF-44
  dueno: { nombre: string; usuario: string; telefono: string | null; activo: boolean; debe_cambiar_contrasena: boolean } | null
  // Último día registrado (cierre o "No abrió") y última vez que la app habló con el servidor
  actividad: { ultimo_cierre: string | null; ultima_conexion: string | null }
}

/*
 * Negocios que necesitan ayuda: activos y con 3 días o más sin registrar el cierre (o que nunca han
 * hecho el primero). Una llamada a tiempo evita que el dueño deje de usar la app.
 */
export const DIAS_SIN_CIERRE = 3

export function diasDesde(fecha: string, hoy: string): number {
  return Math.round((Date.parse(`${hoy}T12:00:00Z`) - Date.parse(`${fecha.slice(0, 10)}T12:00:00Z`)) / 86_400_000)
}

// "hoy", "ayer", "hace 6 días", "hace 2 meses"
export function haceCuanto(fecha: string, hoy: string): string {
  const dias = diasDesde(fecha, hoy)
  if (dias <= 0) return 'hoy'
  if (dias === 1) return 'ayer'
  if (dias < 60) return `hace ${dias} días`
  return `hace ${Math.floor(dias / 30)} meses`
}

export function textoUltimoCierre(n: Pick<NegocioAdmin, 'actividad'>, hoy: string): string {
  const ultimo = n.actividad.ultimo_cierre
  return ultimo ? `Último cierre: ${haceCuanto(ultimo, hoy)}` : 'Todavía no ha hecho su primer cierre'
}

export function necesitaAyuda(n: NegocioAdmin, hoy: string): boolean {
  if (n.estado !== 'activo' || n.datos_borrados_en || n.dueno?.activo === false) return false
  const ultimo = n.actividad.ultimo_cierre
  return !ultimo || diasDesde(ultimo, hoy) >= DIAS_SIN_CIERRE
}

// Los que llevan más días sin cierre van primero; los que nunca han cerrado, al principio
export function porAyudar(negocios: NegocioAdmin[], hoy: string): NegocioAdmin[] {
  const dias = (n: NegocioAdmin) => (n.actividad.ultimo_cierre ? diasDesde(n.actividad.ultimo_cierre, hoy) : Infinity)
  return negocios.filter((n) => necesitaAyuda(n, hoy)).sort((a, b) => dias(b) - dias(a))
}

// Enlace de WhatsApp al dueño con el mensaje ya escrito (el admin solo tiene que enviarlo)
function enlaceWhatsApp(n: NegocioAdmin, texto: (nombre: string) => string): string | null {
  const telefono = (n.dueno?.telefono ?? n.telefono ?? '').replace(/\D/g, '')
  if (!telefono) return null
  const nombre = n.dueno?.nombre.split(' ')[0] ?? ''
  return `https://wa.me/57${telefono}?text=${encodeURIComponent(texto(nombre))}`
}

// WhatsApp al dueño con un mensaje amable ya escrito
export function enlaceAyuda(n: NegocioAdmin, hoy: string): string | null {
  // "no ha registrado el cierre desde hace 6 días"
  const desde = n.actividad.ultimo_cierre ? `no ha registrado el cierre desde ${haceCuanto(n.actividad.ultimo_cierre, hoy)}` : 'todavía no ha hecho su primer cierre'
  return enlaceWhatsApp(n, (nombre) => `Hola ${nombre}, le escribimos de Cierre de Caja. Vimos que ${desde}. ¿Le podemos ayudar con algo?`)
}

/*
 * Negocios por vencer (faltan 5 días o menos): conviene recordarles antes de que venza. Los que ya
 * no usan el servicio (dueño desactivado o datos borrados) no se cobran. Los que vencen antes, arriba.
 */
export function porVencer(negocios: NegocioAdmin[]): NegocioAdmin[] {
  return negocios
    .filter((n) => n.suscripcion.estado === 'por_vencer' && !n.datos_borrados_en && n.dueno?.activo !== false)
    .sort((a, b) => (a.suscripcion.dias ?? 0) - (b.suscripcion.dias ?? 0))
}

// Recordatorio de pago por WhatsApp, para los que están por vencer o ya vencieron
export function enlaceCobro(n: NegocioAdmin): string | null {
  const { vence_el: vence, dias } = n.suscripcion
  if (!vence || dias === null) return null
  const cuando = dias < 0 ? `venció el ${fechaLarga(vence)}` : `vence ${dias === 0 ? 'hoy' : dias === 1 ? 'mañana' : `el ${fechaLarga(vence)}`}`
  const valor = n.ultimo_valor > 0 ? ` El valor es ${pesos(n.ultimo_valor)}.` : ''
  const cierre = dias < 0 ? 'Cuando haga el pago nos avisa por aquí para dejar el servicio al día.' : 'Cuando haga el pago nos avisa por aquí.'
  return enlaceWhatsApp(n, (nombre) => `Hola ${nombre}, le escribimos de Cierre de Caja. Le recordamos que su mensualidad ${cuando}.${valor} ${cierre} ¡Gracias!`)
}

/*
 * Filtro de la lista de negocios y cifras del panel (las mismas cuentas en los dos lados).
 * Los desactivados (dueño desactivado o retirado) solo están en su grupo: ya no se les cobra.
 * Los demás van según su suscripción; un bloqueado también está en "Bloqueados".
 */
export type GrupoNegocio = 'al_dia' | 'por_vencer' | 'vencidos' | 'bloqueados' | 'desactivados'

export const GRUPOS: { valor: GrupoNegocio | 'todos'; texto: string }[] = [
  { valor: 'todos', texto: 'Todos' },
  { valor: 'al_dia', texto: 'Al día' },
  { valor: 'por_vencer', texto: 'Por vencer' },
  { valor: 'vencidos', texto: 'Vencidos' },
  { valor: 'bloqueados', texto: 'Bloqueados' },
  { valor: 'desactivados', texto: 'Desactivados' },
]

const desactivado = (n: NegocioAdmin) => n.dueno?.activo === false || !!n.datos_borrados_en

export function enGrupo(n: NegocioAdmin, grupo: GrupoNegocio | 'todos'): boolean {
  if (grupo === 'todos') return true
  if (grupo === 'desactivados') return desactivado(n)
  if (desactivado(n)) return false
  const estado = n.suscripcion.estado
  if (grupo === 'al_dia') return estado === 'activa'
  if (grupo === 'por_vencer') return estado === 'por_vencer'
  if (grupo === 'vencidos') return estado === 'vencida' || estado === 'sin_pago'
  return n.estado === 'bloqueado'
}

export function contarGrupos(negocios: NegocioAdmin[]): Record<GrupoNegocio | 'todos', number> {
  const cuenta = { todos: 0, al_dia: 0, por_vencer: 0, vencidos: 0, bloqueados: 0, desactivados: 0 }
  for (const grupo of Object.keys(cuenta) as (GrupoNegocio | 'todos')[]) cuenta[grupo] = negocios.filter((n) => enGrupo(n, grupo)).length
  return cuenta
}

// En "Todos" los desactivados van al final, para que no se mezclen con los que están en servicio
export function filtrarNegocios(negocios: NegocioAdmin[], grupo: GrupoNegocio | 'todos', busqueda: string): NegocioAdmin[] {
  const texto = busqueda.trim().toLowerCase()
  const coincide = (n: NegocioAdmin) => !texto || [n.nombre, n.dueno?.nombre, n.dueno?.usuario].some((v) => v?.toLowerCase().includes(texto))
  const lista = negocios.filter((n) => coincide(n) && enGrupo(n, grupo))
  if (grupo !== 'todos') return lista
  return [...lista.filter((n) => !desactivado(n)), ...lista.filter(desactivado)]
}

// Lo que ha entrado por las suscripciones, mes a mes (del más viejo al actual)
export interface RecaudoAdmin {
  meses: { mes: string; total: number; pagos: number }[] // mes: "2026-11"
  total: number
}

export interface PagoSuscripcion {
  id: number
  fecha: string
  valor: number
  vencia_el: string | null
  vence_el: string
  nota: string | null
  // Si se registró por error: cuándo se anuló y por qué. No cuenta para el vencimiento ni lo recaudado
  anulado_en: string | null
  motivo_anulacion: string | null
}

/*
 * Los 12 meses de un año para la cuadrícula de pagos del negocio. Cada pago cuenta en el mes en
 * que se pagó (igual que "Lo recaudado" y el Excel). Un mes sin pago puede estar "al día" si un
 * pago anterior lo cubrió (por ejemplo, pagó el 31 de octubre y eso cubre hasta el 30 de noviembre).
 *   pagado: tuvo pagos (la suma, sin anulados)  ·  al_dia: sin pago, pero cubierto
 *   sin_pagar: ya era cliente y ningún pago cubrió ese mes  ·  antes: todavía no era cliente
 *   futuro: el mes no ha llegado
 */
export type EstadoMesPago = 'pagado' | 'al_dia' | 'sin_pagar' | 'antes' | 'futuro'

export interface MesPagos {
  mes: string // "2026-11"
  estado: EstadoMesPago
  total: number
  pagos: PagoSuscripcion[] // del mes, incluidos los anulados (se ven tachados)
}

// Desde cuándo cubre un pago (RN-12): si estaba al día, desde el vencimiento anterior; si no, desde el día del pago
function cubreDesde(p: PagoSuscripcion): string {
  return p.vencia_el && p.vencia_el >= p.fecha ? p.vencia_el : p.fecha
}

export function mesesDePagos(pagos: PagoSuscripcion[], anio: number, hoy: string, clienteDesde: string | null): MesPagos[] {
  const vigentes = pagos.filter((p) => !p.anulado_en)
  const primero = vigentes.reduce<string | null>((min, p) => (!min || p.fecha < min ? p.fecha : min), null)
  const inicio = (clienteDesde && (!primero || clienteDesde < primero) ? clienteDesde : primero)?.slice(0, 7) ?? null
  const mesHoy = hoy.slice(0, 7)

  return Array.from({ length: 12 }, (_, i) => {
    const mes = `${anio}-${String(i + 1).padStart(2, '0')}`
    const primerDia = `${mes}-01`
    const ultimoDia = `${mes}-${new Date(Date.UTC(anio, i + 1, 0)).getUTCDate()}`
    const delMes = pagos.filter((p) => p.fecha.startsWith(mes)).sort((a, b) => (a.fecha < b.fecha ? -1 : 1))
    const total = delMes.filter((p) => !p.anulado_en).reduce((suma, p) => suma + p.valor, 0)

    let estado: EstadoMesPago
    if (mes > mesHoy) estado = 'futuro'
    else if (delMes.some((p) => !p.anulado_en)) estado = 'pagado'
    else if (!inicio || mes < inicio) estado = 'antes'
    // En el mes actual cuenta si hoy sigue cubierto: si ya venció y no ha pagado, está sin pagar
    else if (mes === mesHoy ? vigentes.some((p) => cubreDesde(p) <= hoy && p.vence_el >= hoy) : vigentes.some((p) => cubreDesde(p) <= ultimoDia && p.vence_el > primerDia))
      estado = 'al_dia'
    else estado = 'sin_pagar'

    return { mes, estado, total, pagos: delMes }
  })
}

// Años que se pueden ver: desde el primer pago (o desde que es cliente) hasta el actual
export function aniosDePagos(pagos: PagoSuscripcion[], hoy: string, clienteDesde: string | null): number[] {
  const fechas = [...pagos.map((p) => p.fecha), ...(clienteDesde ? [clienteDesde] : [])]
  const actual = Number(hoy.slice(0, 4))
  const desde = fechas.length ? Math.min(actual, ...fechas.map((f) => Number(f.slice(0, 4)))) : actual
  return Array.from({ length: actual - desde + 1 }, (_, i) => desde + i)
}

export interface DetalleNegocio extends NegocioAdmin {
  pagos: PagoSuscripcion[]
}

export interface ResumenAdmin {
  total: number
  activos: number
  por_vencer: number
  vencidos: number
  bloqueados: number
  recaudado_mes: number
}

export interface MesNegocio {
  cierres: {
    fecha: string
    tipo: 'normal' | 'historico'
    no_abrio: boolean
    base: number | null
    efectivo: number | null
    salida_caja: number
    total_historico: number | null
    nequi_historico: number | null
    modificado_en: string | null
  }[]
  pagos: { fecha: string; hora: string | null; medio: 'nequi' | 'otro'; valor: number | null; anulado_en: string | null }[]
  abonos_por_dia: Record<string, number>
}

export function useAdminApi() {
  const sesion = useSesionStore()
  const get = <T>(ruta: string) => pedir<T>(`/admin${ruta}`, { token: sesion.token })
  const enviar = <T>(ruta: string, cuerpo: unknown = {}, metodo: 'POST' | 'PUT' = 'POST') => pedir<T>(`/admin${ruta}`, { metodo, cuerpo, token: sesion.token })

  return {
    resumen: () => get<ResumenAdmin>('/resumen'),
    recaudo: (meses = 12) => get<RecaudoAdmin>(`/recaudo?meses=${meses}`),
    negocios: () => get<NegocioAdmin[]>('/negocios'),
    negocio: (id: string) => get<DetalleNegocio>(`/negocios/${id}`),
    mes: (id: string, anio: number, mes: number) => get<MesNegocio>(`/negocios/${id}/mes?anio=${anio}&mes=${mes}`),
    rango: (id: string, desde: string, hasta: string) => get<MesNegocio>(`/negocios/${id}/mes?desde=${desde}&hasta=${hasta}`),
    registrar: (datos: Record<string, unknown>) => enviar<NegocioAdmin>('/negocios', datos),
    editar: (id: string, datos: Record<string, unknown>) => enviar<DetalleNegocio>(`/negocios/${id}`, datos, 'PUT'),
    pagar: (id: string, datos: { valor: number; fecha: string; nota: string | null }) => enviar<DetalleNegocio>(`/negocios/${id}/pagos`, datos),
    accion: (id: string, accion: 'bloquear' | 'desbloquear' | 'activar' | 'desactivar') => enviar<DetalleNegocio>(`/negocios/${id}/${accion}`),
    anularPago: (id: string, pago: number, motivo: string) => enviar<DetalleNegocio>(`/negocios/${id}/pagos/${pago}/anular`, { motivo }),
    // A pedido del dueño: borra sus datos y desactiva la cuenta. Pide escribir el nombre del negocio
    retirar: (id: string, confirmacion: string) => enviar<DetalleNegocio>(`/negocios/${id}/retirar`, { confirmacion }),
    restablecer: (id: string) => enviar<{ contrasena_temporal: string }>(`/negocios/${id}/contrasena`),
  }
}

// "2027-01-31" + 1 mes = "2027-02-28": si el día no existe en el mes siguiente, queda el último
export function sumarMes(fecha: string): string {
  const [anio, mes, dia] = fecha.split('-').map(Number) as [number, number, number]
  const siguiente = new Date(Date.UTC(anio, mes, 1)) // primer día del mes siguiente
  const ultimo = new Date(Date.UTC(siguiente.getUTCFullYear(), siguiente.getUTCMonth() + 1, 0)).getUTCDate()
  siguiente.setUTCDate(Math.min(dia, ultimo))
  return siguiente.toISOString().slice(0, 10)
}

// RN-12: al día, un mes desde el vencimiento anterior; vencida, un mes desde el día del pago
export function nuevoVencimiento(venceEl: string | null, fechaPago: string): string {
  return sumarMes(venceEl && venceEl >= fechaPago ? venceEl : fechaPago)
}

// Texto corto del vencimiento: "vence en 3 días", "venció hace 5 días"
export function textoVencimiento(dias: number | null): string {
  if (dias === null) return 'Sin pagos registrados'
  if (dias === 0) return 'Vence hoy'
  if (dias === 1) return 'Vence mañana'
  if (dias > 0) return `Vence en ${dias} días`
  return dias === -1 ? 'Venció ayer' : `Venció hace ${-dias} días`
}
