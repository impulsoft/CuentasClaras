import { Capacitor } from '@capacitor/core'
import { LocalNotifications } from '@capacitor/local-notifications'
import { hoy as fechaHoy, horaActual, sumarDias } from './calculos'
import { cierresEntre } from './datos'

/*
 * Recordatorio diario del cierre (solo en la APK): "¿Ya hizo el cierre de hoy?" a la hora que
 * escoja el dueño (7:00 p. m. si no escoge). Lo programa el mismo celular, sin internet.
 *
 * Se programan los próximos 14 días que todavía no tienen cierre ni "No abrió": al guardar el cierre
 * se vuelve a programar y el aviso de ese día ya no sale. Si el dueño no abre la app en 14 días,
 * los avisos se acaban (no tiene sentido seguir insistiendo sin que la use).
 */

export interface AjusteRecordatorio {
  activo: boolean
  hora: string // "19:00"
}

export const RECORDATORIO_POR_DEFECTO: AjusteRecordatorio = { activo: true, hora: '19:00' }
export const DIAS_PROGRAMADOS = 14
const CLAVE = 'cierre-de-caja.recordatorio'
const PRIMER_ID = 7000 // los avisos de la app van del 7000 al 7099

// Lo que escogió el dueño en este celular (es un ajuste del celular, no del negocio)
export function leerAjuste(): AjusteRecordatorio {
  try {
    const guardado = JSON.parse(localStorage.getItem(CLAVE) ?? 'null')
    if (guardado && typeof guardado.activo === 'boolean' && /^\d{2}:\d{2}$/.test(guardado.hora)) return guardado
  } catch {
    // sin almacenamiento: se usa el ajuste por defecto
  }
  return { ...RECORDATORIO_POR_DEFECTO }
}

export function guardarAjuste(ajuste: AjusteRecordatorio): void {
  try {
    localStorage.setItem(CLAVE, JSON.stringify(ajuste))
  } catch {
    // sin almacenamiento: el ajuste dura mientras la app esté abierta
  }
}

/*
 * Días en que toca recordar: hoy (si la hora todavía no ha pasado) y los siguientes, sin los que
 * ya tienen cierre o "No abrió".
 */
export function diasARecordar(hoy: string, ahora: string, hora: string, conRegistro: Set<string>, dias = DIAS_PROGRAMADOS): string[] {
  const lista: string[] = []
  for (let i = 0; i < dias; i++) {
    const fecha = sumarDias(hoy, i)
    if (i === 0 && ahora >= hora) continue
    if (!conRegistro.has(fecha)) lista.push(fecha)
  }
  return lista
}

// La hora del aviso en hora de Colombia (UTC-5, sin horario de verano), igual que el resto de la app
export function momentoDelAviso(fecha: string, hora: string): Date {
  const [anio, mes, dia] = fecha.split('-').map(Number)
  const [h, m] = hora.split(':').map(Number)
  return new Date(Date.UTC(anio!, mes! - 1, dia!, h! + 5, m!))
}

/*
 * Ojo: el plugin se importa normal y nunca se devuelve desde una función async. Si una promesa
 * "resuelve" con el plugin, JavaScript le pregunta si tiene .then() y en Android eso falla con
 * '"LocalNotifications.then()" is not implemented' (así pasó la primera vez: no salía ningún aviso).
 */
export async function cancelarRecordatorios(): Promise<void> {
  if (!Capacitor.isNativePlatform()) return
  try {
    const { notifications } = await LocalNotifications.getPending()
    const nuestros = notifications.filter((n) => n.id >= PRIMER_ID && n.id < PRIMER_ID + 100)
    if (nuestros.length) await LocalNotifications.cancel({ notifications: nuestros.map((n) => ({ id: n.id })) })
  } catch {
    // si el celular no deja manejar avisos, no hay nada que cancelar
  }
}

// Vuelve a programar los avisos (al abrir la app, al guardar un cierre y al cambiar el ajuste)
export async function programarRecordatorios(): Promise<void> {
  if (!Capacitor.isNativePlatform()) return
  await cancelarRecordatorios()
  const ajuste = leerAjuste()
  if (!ajuste.activo) return

  const hoy = fechaHoy()
  const registrados = await cierresEntre(hoy, sumarDias(hoy, DIAS_PROGRAMADOS))
  const dias = diasARecordar(hoy, horaActual(), ajuste.hora, new Set(registrados.map((c) => c.fecha)))
  if (!dias.length) return

  try {
    // Desde la versión 8.3 el plugin pide el permiso de avisos si hace falta (Android 13 o más nuevo)
    await LocalNotifications.schedule({
      notifications: dias.map((fecha, i) => ({
        id: PRIMER_ID + i,
        title: '¿Ya hizo el cierre de hoy?',
        body: 'Toque aquí para registrar lo que vendió hoy.',
        schedule: { at: momentoDelAviso(fecha, ajuste.hora), allowWhileIdle: true },
        extra: { dia: fecha },
        smallIcon: 'ic_stat_cierre',
        iconColor: '#0F6E56',
      })),
    })
  } catch {
    // el dueño no dio permiso de avisos: la app sigue funcionando igual
  }
}
