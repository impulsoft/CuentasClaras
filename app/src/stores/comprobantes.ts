import { defineStore } from 'pinia'
import { liveQuery } from 'dexie'
import { ref } from 'vue'
import { db, type FotoComprobante } from '../db'
import { guardarFoto, leerFoto, reducirImagen } from '../comprobantes'
import { pedir } from '../api'
import { useSesionStore } from './sesion'
import { useDialogoStore } from './dialogo'

// Fotos de comprobantes en camino y la que el dueño está revisando (RF-17, RF-18)
export const useComprobantesStore = defineStore('comprobantes', () => {
  const fotos = ref<FotoComprobante[]>([])
  const revisando = ref<string | null>(null)
  const leyendo = ref(false)

  liveQuery(() => db.fotos.orderBy('creado_en').toArray()).subscribe({ next: (lista) => (fotos.value = lista) })

  async function agregar(origen: FotoComprobante['origen'], archivo: File): Promise<void> {
    const sesion = useSesionStore()
    const dialogo = useDialogoStore()
    leyendo.value = true
    try {
      const id = await guardarFoto(origen, await reducirImagen(archivo))
      // La ventana del pago se abre de una vez con la foto: mientras el servidor la lee, el dueño
      // revisa en su Nequi que la plata llegó, y los datos aparecen solos al terminar
      revisando.value = id
      const resultado = await leerFoto(id, sesion.token!)
      if (resultado === 'leida') return
      if (revisando.value === id) revisando.value = null
      if (resultado === 'sin_conexion') dialogo.avisar('Sin internet: la foto quedó guardada y se leerá después')
      else dialogo.avisar('No se pudo leer ahora; quedó guardada para revisar')
    } finally {
      leyendo.value = false
    }
  }

  // Leer otra vez una foto que no se pudo leer (por ejemplo, ya volvió el internet)
  async function reintentar(id: string): Promise<void> {
    leyendo.value = true
    try {
      const resultado = await leerFoto(id, useSesionStore().token!)
      if (resultado === 'leida') revisando.value = id
      else useDialogoStore().avisar(resultado === 'sin_conexion' ? 'Todavía no hay internet' : 'No se pudo leer la foto')
    } finally {
      leyendo.value = false
    }
  }

  // La foto descartada también se borra del servidor: no se volvió pago y es un dato personal.
  // Sin internet no se puede; el servidor la borra solo a los 7 días.
  async function descartar(id: string): Promise<void> {
    const foto = await db.fotos.get(id)
    await db.fotos.delete(id)
    if (revisando.value === id) revisando.value = null
    if (foto?.foto_ruta) {
      pedir(`/comprobantes/foto?ruta=${encodeURIComponent(foto.foto_ruta)}`, { metodo: 'DELETE', token: useSesionStore().token }).catch(() => {})
    }
  }

  return { fotos, revisando, leyendo, agregar, reintentar, descartar }
})
