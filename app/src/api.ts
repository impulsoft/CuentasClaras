// Cliente de la API de Laravel (carpeta api/)
import { Capacitor } from '@capacitor/core'
const BASE = import.meta.env.VITE_API_URL ?? 'http://localhost:8000/api'

export class ErrorApi extends Error {
  estado: number
  codigo?: string
  errores: Record<string, string[]>

  constructor(mensaje: string, estado: number, codigo?: string, errores: Record<string, string[]> = {}) {
    super(mensaje)
    this.estado = estado
    this.codigo = codigo
    this.errores = errores
  }

  get sinConexion(): boolean {
    return this.estado === 0
  }

  // Primer error de validación de un campo, para mostrarlo debajo de él
  de(campo: string): string | undefined {
    return this.errores[campo]?.[0]
  }
}

interface Opciones {
  metodo?: 'GET' | 'POST' | 'PUT' | 'DELETE'
  cuerpo?: unknown
  token?: string | null
}

export async function pedir<T>(ruta: string, { metodo = 'GET', cuerpo, token }: Opciones = {}): Promise<T> {
  // Un FormData (fotos) se envía tal cual; lo demás, como JSON
  const esArchivo = cuerpo instanceof FormData
  let respuesta: Response
  try {
    respuesta = await fetch(BASE + ruta, {
      method: metodo,
      headers: {
        Accept: 'application/json',
        ...(cuerpo !== undefined && !esArchivo ? { 'Content-Type': 'application/json' } : {}),
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
      body: esArchivo ? cuerpo : cuerpo !== undefined ? JSON.stringify(cuerpo) : undefined,
    })
  } catch {
    throw new ErrorApi('No hay conexión con el servidor.', 0, 'sin_conexion')
  }

  if (respuesta.status === 204) {
    return undefined as T
  }

  const datos = await respuesta.json().catch(() => ({}))
  if (!respuesta.ok) {
    const mensaje = datos.mensaje ?? datos.message ?? 'Ocurrió un error. Intente de nuevo.'
    throw new ErrorApi(mensaje, respuesta.status, datos.codigo, datos.errors ?? {})
  }
  return datos as T
}

// Un archivo de la API (Excel o foto de un comprobante), con la sesión del usuario
export async function traerArchivo(ruta: string, token: string | null): Promise<Response> {
  let respuesta: Response
  try {
    respuesta = await fetch(BASE + ruta, { headers: { Accept: 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) } })
  } catch {
    throw new ErrorApi('No hay conexión con el servidor.', 0, 'sin_conexion')
  }
  if (!respuesta.ok) {
    const datos = await respuesta.json().catch(() => ({}))
    throw new ErrorApi(datos.message ?? 'No se pudo traer el archivo.', respuesta.status, datos.codigo, datos.errors ?? {})
  }
  return respuesta
}

/*
 * Descarga un archivo de la API (el Excel, RF-26) y lo guarda en el equipo con su nombre.
 * En el navegador abre la descarga normal; en la APK abre "Compartir".
 * Devuelve false si la persona cerró "Compartir" sin escoger nada (no es un error).
 */
export async function descargar(ruta: string, token: string | null, nombrePorDefecto: string): Promise<boolean> {
  const respuesta = await traerArchivo(ruta, token)
  const nombre = /filename="?([^";]+)"?/.exec(respuesta.headers.get('Content-Disposition') ?? '')?.[1] ?? nombrePorDefecto
  const archivo = await respuesta.blob()

  // En la APK no hay "descargas" del navegador: se guarda el archivo y se abre "Compartir",
  // para guardarlo en el celular, en Drive o enviarlo por WhatsApp o correo
  if (Capacitor.isNativePlatform()) {
    const { Filesystem, Directory } = await import('@capacitor/filesystem')
    const { Share } = await import('@capacitor/share')
    const { uri } = await Filesystem.writeFile({ path: nombre, data: await enBase64(archivo), directory: Directory.Cache })
    try {
      await Share.share({ title: nombre, url: uri, dialogTitle: 'Guardar o enviar el Excel' })
    } catch (e) {
      // Android responde "Share canceled" cuando se cierra el menú sin escoger una app
      if (/cancel/i.test(e instanceof Error ? e.message : String(e))) return false
      throw e
    }
    return true
  }

  const url = URL.createObjectURL(archivo)
  const enlace = document.createElement('a')
  enlace.href = url
  enlace.download = nombre
  document.body.append(enlace)
  enlace.click()
  enlace.remove()
  setTimeout(() => URL.revokeObjectURL(url), 10_000)
  return true
}

// Filesystem de Capacitor recibe el contenido en base64
function enBase64(archivo: Blob): Promise<string> {
  return new Promise((resolver, fallar) => {
    const lector = new FileReader()
    lector.onload = () => resolver(String(lector.result).split(',')[1] ?? '')
    lector.onerror = () => fallar(lector.error)
    lector.readAsDataURL(archivo)
  })
}
