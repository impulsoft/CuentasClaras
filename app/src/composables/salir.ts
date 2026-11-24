import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'

// RF-48: avisa antes de salir si hay cambios sin enviar, porque al salir se borran del celular
export function useSalir() {
  const sesion = useSesionStore()
  const sync = useSincronizacionStore()
  const dialogo = useDialogoStore()

  return async function salir() {
    if (sync.pendientes > 0) await sync.sincronizar()
    if (sync.pendientes > 0) {
      const confirmo = await dialogo.confirmar({
        titulo: 'Hay cambios sin enviar',
        mensaje: `${sync.pendientes} cambios todavía no llegan al servidor. Si sale ahora, se pierden. Conéctese a internet antes de salir.`,
        aceptar: 'Salir igual',
        cancelar: 'Quedarme',
        peligro: true,
      })
      if (!confirmo) return
    }
    await sesion.cerrar()
  }
}
