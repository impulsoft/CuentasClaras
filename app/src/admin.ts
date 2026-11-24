import { pedir } from './api'
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

// WhatsApp al dueño con un mensaje amable ya escrito
export function enlaceAyuda(n: NegocioAdmin, hoy: string): string | null {
  const telefono = (n.dueno?.telefono ?? n.telefono ?? '').replace(/\D/g, '')
  if (!telefono) return null
  const nombre = n.dueno?.nombre.split(' ')[0] ?? ''
  // "no ha registrado el cierre desde hace 6 días"
  const desde = n.actividad.ultimo_cierre ? `no ha registrado el cierre desde ${haceCuanto(n.actividad.ultimo_cierre, hoy)}` : 'todavía no ha hecho su primer cierre'
  const texto = `Hola ${nombre}, le escribimos de Cierre de Caja. Vimos que ${desde}. ¿Le podemos ayudar con algo?`
  return `https://wa.me/57${telefono}?text=${encodeURIComponent(texto)}`
}

export interface PagoSuscripcion {
  id: number
  fecha: string
  valor: number
  vencia_el: string | null
  vence_el: string
  nota: string | null
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
    negocios: () => get<NegocioAdmin[]>('/negocios'),
    negocio: (id: string) => get<DetalleNegocio>(`/negocios/${id}`),
    mes: (id: string, anio: number, mes: number) => get<MesNegocio>(`/negocios/${id}/mes?anio=${anio}&mes=${mes}`),
    rango: (id: string, desde: string, hasta: string) => get<MesNegocio>(`/negocios/${id}/mes?desde=${desde}&hasta=${hasta}`),
    registrar: (datos: Record<string, unknown>) => enviar<NegocioAdmin>('/negocios', datos),
    editar: (id: string, datos: Record<string, unknown>) => enviar<DetalleNegocio>(`/negocios/${id}`, datos, 'PUT'),
    pagar: (id: string, datos: { valor: number; fecha: string; nota: string | null }) => enviar<DetalleNegocio>(`/negocios/${id}/pagos`, datos),
    accion: (id: string, accion: 'bloquear' | 'desbloquear' | 'activar' | 'desactivar') => enviar<DetalleNegocio>(`/negocios/${id}/${accion}`),
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
