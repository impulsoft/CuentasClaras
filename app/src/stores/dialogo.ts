import { defineStore } from 'pinia'
import { ref } from 'vue'

/*
 * Ventanas propias para confirmar o pedir un dato, en lugar de confirm() y prompt()
 * del navegador (más claras para personas sin experiencia), y avisos cortos que se van solos.
 */

export interface PeticionDialogo {
  titulo: string
  mensaje?: string
  aceptar: string
  cancelar: string
  peligro?: boolean
  pedirTexto?: { etiqueta: string; inicial: string }
}

export const useDialogoStore = defineStore('dialogo', () => {
  const abierto = ref<PeticionDialogo | null>(null)
  let responder: ((valor: string | boolean | null) => void) | null = null

  function abrir(peticion: PeticionDialogo): Promise<string | boolean | null> {
    responder?.(null)
    abierto.value = peticion
    return new Promise((resolver) => (responder = resolver))
  }

  function cerrar(valor: string | boolean | null): void {
    abierto.value = null
    responder?.(valor)
    responder = null
  }

  async function confirmar(opciones: Omit<PeticionDialogo, 'aceptar' | 'cancelar'> & { aceptar?: string; cancelar?: string }): Promise<boolean> {
    const respuesta = await abrir({ aceptar: 'Sí', cancelar: 'No', ...opciones })
    return respuesta === true
  }

  async function pedirTexto(titulo: string, etiqueta: string, inicial = '', aceptar = 'Aceptar'): Promise<string | null> {
    const respuesta = await abrir({ titulo, aceptar, cancelar: 'Cancelar', pedirTexto: { etiqueta, inicial } })
    return typeof respuesta === 'string' && respuesta.trim() ? respuesta.trim() : null
  }

  // Aviso corto abajo de la pantalla ("Cierre guardado")
  const aviso = ref<{ id: number; texto: string } | null>(null)
  let temporizador: ReturnType<typeof setTimeout> | undefined

  function avisar(texto: string): void {
    clearTimeout(temporizador)
    aviso.value = { id: Date.now(), texto }
    temporizador = setTimeout(() => (aviso.value = null), 2600)
  }

  return { abierto, cerrar, confirmar, pedirTexto, aviso, avisar }
})
