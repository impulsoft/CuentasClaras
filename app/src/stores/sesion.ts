import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import { Capacitor } from '@capacitor/core'
import { pedir, ErrorApi } from '../api'
import { borrarDatosLocales, db } from '../db'

// Lo que el dueño sabe de su negocio: bloqueo (RF-12), suscripción (RF-10) y aviso de borrado (RN-34)
export interface NegocioDelDueno {
  id: string
  nombre: string
  estado: string
  suscripcion_vence_el?: string | null
  suscripcion_estado?: 'activa' | 'por_vencer' | 'vencida' | 'sin_pago'
  datos_se_borran_el?: string | null
}

export interface Usuario {
  id: number
  nombre: string
  usuario: string
  rol: 'admin' | 'dueno'
  debe_aceptar_terminos: boolean
  debe_cambiar_contrasena: boolean
  negocio: NegocioDelDueno | null
}

const CLAVE = 'cierre-de-caja.sesion'

interface Guardado {
  token: string
  usuario: Usuario
}

// La sesión queda en el dispositivo para abrir la app sin internet (RF-48).
// El almacenamiento puede fallar (modo privado); en ese caso la sesión dura mientras la app esté abierta.
function leer(): Guardado | null {
  try {
    const texto = localStorage.getItem(CLAVE)
    return texto ? (JSON.parse(texto) as Guardado) : null
  } catch {
    return null
  }
}

function escribir(valor: Guardado | null): void {
  try {
    if (valor) localStorage.setItem(CLAVE, JSON.stringify(valor))
    else localStorage.removeItem(CLAVE)
  } catch {
    /* sin almacenamiento disponible */
  }
}

export const useSesionStore = defineStore('sesion', () => {
  const guardado = leer()
  const token = ref<string | null>(guardado?.token ?? null)
  const usuario = ref<Usuario | null>(guardado?.usuario ?? null)

  const conSesion = computed(() => token.value !== null && usuario.value !== null)
  const esAdmin = computed(() => usuario.value?.rol === 'admin')

  function guardar(nuevoToken: string, nuevoUsuario: Usuario): void {
    token.value = nuevoToken
    usuario.value = nuevoUsuario
    escribir({ token: nuevoToken, usuario: nuevoUsuario })
  }

  function olvidar(): void {
    token.value = null
    usuario.value = null
    escribir(null)
  }

  async function iniciar(nombreUsuario: string, contrasena: string): Promise<void> {
    const respuesta = await pedir<{ token: string; usuario: Usuario }>('/sesion', {
      metodo: 'POST',
      cuerpo: {
        usuario: nombreUsuario.trim(),
        contrasena,
        dispositivo: Capacitor.getPlatform() === 'android' ? 'Celular Android' : 'Navegador',
      },
    })
    // Si en este celular había datos de otro negocio, se borran antes de empezar
    const negocioId = respuesta.usuario.negocio?.id ?? ''
    const anterior = await db.meta.get('negocio')
    if (anterior?.valor !== negocioId) {
      await borrarDatosLocales()
      await db.meta.put({ clave: 'negocio', valor: negocioId })
    }
    guardar(respuesta.token, respuesta.usuario)
  }

  // Trae los datos del servidor; sin internet sigue con los guardados (RF-48)
  async function actualizar(): Promise<void> {
    if (!token.value) return
    try {
      guardar(token.value, await pedir<Usuario>('/yo', { token: token.value }))
    } catch (error) {
      if (error instanceof ErrorApi && (error.estado === 401 || error.codigo === 'cuenta_desactivada')) {
        olvidar()
      }
    }
  }

  async function aceptarTerminos(): Promise<void> {
    const actualizado = await pedir<Usuario>('/terminos', {
      metodo: 'POST',
      cuerpo: { acepto: true },
      token: token.value,
    })
    guardar(token.value!, actualizado)
  }

  // La sincronización trae el estado del negocio; así el aviso de la suscripción queda al día
  function actualizarNegocio(negocio: NegocioDelDueno): void {
    if (token.value && usuario.value) guardar(token.value, { ...usuario.value, negocio })
  }

  // RF-46 / RF-47: el servidor da un token nuevo; los otros dispositivos se cierran al sincronizar
  async function cambiarContrasena(actual: string | null, nueva: string): Promise<void> {
    const respuesta = await pedir<{ token: string; usuario: Usuario }>('/contrasena', {
      metodo: 'POST',
      cuerpo: { actual, nueva },
      token: token.value,
    })
    guardar(respuesta.token, respuesta.usuario)
  }

  // RF-02. Si no hay internet, igual se cierra en el dispositivo. Los datos del celular
  // se borran; quien llama debe avisar antes si hay cambios sin sincronizar (RF-48).
  async function cerrar(): Promise<void> {
    try {
      await pedir('/sesion', { metodo: 'DELETE', token: token.value })
    } catch {
      /* el token se borra en el servidor la próxima vez, o vence */
    }
    await borrarDatosLocales()
    olvidar()
  }

  return { token, usuario, conSesion, esAdmin, iniciar, actualizar, aceptarTerminos, actualizarNegocio, cambiarContrasena, cerrar }
})
