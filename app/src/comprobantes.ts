import { db, nuevoId, type FotoComprobante, type DatosLeidos } from './db'
import { pedir, ErrorApi } from './api'
import { referenciaCargada } from './datos'

/*
 * Fotos y pantallazos de comprobantes Nequi (RF-17). La foto se guarda primero en el celular;
 * si hay internet se envía al servidor, que la lee con el OCR. Sin internet queda "por leer"
 * y se envía cuando vuelva la conexión (RF-18).
 */

// Achica la foto antes de guardarla y enviarla: menos datos para el celular y para el plan de internet
export async function reducirImagen(archivo: Blob, ladoMayor = 1600): Promise<Blob> {
  const url = URL.createObjectURL(archivo)
  try {
    const imagen = await new Promise<HTMLImageElement>((resolver, fallar) => {
      const img = new Image()
      img.onload = () => resolver(img)
      img.onerror = fallar
      img.src = url
    })
    const escala = Math.min(1, ladoMayor / Math.max(imagen.naturalWidth, imagen.naturalHeight))
    const lienzo = document.createElement('canvas')
    lienzo.width = Math.round(imagen.naturalWidth * escala)
    lienzo.height = Math.round(imagen.naturalHeight * escala)
    lienzo.getContext('2d')!.drawImage(imagen, 0, 0, lienzo.width, lienzo.height)
    return await new Promise<Blob>((resolver) => lienzo.toBlob((blob) => resolver(blob ?? archivo), 'image/jpeg', 0.85))
  } catch {
    return archivo // si no se puede achicar, se envía como viene
  } finally {
    URL.revokeObjectURL(url)
  }
}

export async function guardarFoto(origen: FotoComprobante['origen'], imagen: Blob): Promise<string> {
  const id = nuevoId()
  await db.fotos.add({
    id,
    creado_en: new Date().toISOString(),
    origen,
    imagen,
    estado: 'por_leer',
    foto_ruta: null,
    leido: false,
    campos: null,
    repetido: false,
  })
  return id
}

interface RespuestaLectura {
  foto_ruta: string
  leido: boolean
  campos: DatosLeidos
  repetido: boolean
}

export type ResultadoLectura = 'leida' | 'sin_conexion' | 'error'

// Envía la foto al servidor y guarda lo que leyó el OCR
export async function leerFoto(id: string, token: string): Promise<ResultadoLectura> {
  const foto = await db.fotos.get(id)
  if (!foto || foto.estado !== 'por_leer') return 'leida'

  const formulario = new FormData()
  formulario.append('foto', foto.imagen, 'comprobante.jpg')
  try {
    const respuesta = await pedir<RespuestaLectura>('/comprobantes', { metodo: 'POST', cuerpo: formulario, token })
    // RN-05: también se revisa lo cargado en este celular que todavía no llega al servidor
    const repetido = respuesta.repetido || (await referenciaCargada(respuesta.campos.referencia))
    await db.fotos.update(id, {
      estado: 'por_confirmar',
      foto_ruta: respuesta.foto_ruta,
      leido: respuesta.leido,
      campos: respuesta.campos,
      repetido,
    })
    return 'leida'
  } catch (error) {
    return error instanceof ErrorApi && error.sinConexion ? 'sin_conexion' : 'error'
  }
}

// Las fotos tomadas sin internet se leen cuando vuelve la conexión (RF-18)
export async function leerPendientes(token: string): Promise<void> {
  const porLeer = await db.fotos.where('estado').equals('por_leer').sortBy('creado_en')
  for (const foto of porLeer) {
    if ((await leerFoto(foto.id, token)) === 'sin_conexion') return
  }
}
