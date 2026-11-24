import { defineStore } from 'pinia'
import { liveQuery } from 'dexie'
import { computed, ref } from 'vue'
import { db, type Abono, type Cierre, type Deudor, type Fiado, type Pago } from '../db'
import { pedir, ErrorApi } from '../api'
import { useSesionStore, type NegocioDelDueno } from './sesion'
import { leerPendientes } from '../comprobantes'

/*
 * RF-30: envía al servidor lo guardado en el celular y trae lo que cambió.
 * Se sincroniza al abrir la app, al volver el internet, después de guardar y cada 5 minutos.
 */

const SIETE_DIAS = 7 * 24 * 60 * 60 * 1000 // RN-27
const CADA_CINCO_MINUTOS = 5 * 60 * 1000

interface Respuesta {
  hasta: string
  negocio: NegocioDelDueno
  cierres: Record<string, unknown>[]
  pagos: Record<string, unknown>[]
  deudores: Record<string, unknown>[]
  fiados: Record<string, unknown>[]
  abonos: Record<string, unknown>[]
  conflictos: { tabla: 'cierres'; id_local: string; servidor: Record<string, unknown> }[]
}

const numero = (v: unknown): number | null => (v === null || v === undefined ? null : Number(v))

function cierreDelServidor(s: Record<string, unknown>): Cierre {
  return {
    id: String(s.id),
    negocio_id: String(s.negocio_id),
    fecha: String(s.fecha).slice(0, 10),
    tipo: s.tipo === 'historico' ? 'historico' : 'normal',
    no_abrio: Boolean(s.no_abrio),
    base: numero(s.base),
    efectivo: numero(s.efectivo),
    salida_caja: numero(s.salida_caja) ?? 0,
    total_historico: numero(s.total_historico),
    nequi_historico: numero(s.nequi_historico),
    modificado_en: (s.modificado_en as string | null) ?? null,
    version: (s.version as string | null) ?? null,
    pendiente: 0,
  }
}

function pagoDelServidor(s: Record<string, unknown>): Pago {
  return {
    id: String(s.id),
    negocio_id: String(s.negocio_id),
    fecha: String(s.fecha).slice(0, 10),
    hora: s.hora ? String(s.hora).slice(0, 5) : null,
    medio: s.medio === 'otro' ? 'otro' : 'nequi',
    origen: s.origen as Pago['origen'],
    estado: s.estado as Pago['estado'],
    valor: numero(s.valor),
    nombre_pagador: (s.nombre_pagador as string | null) ?? null,
    referencia: (s.referencia as string | null) ?? null,
    foto_ruta: (s.foto_ruta as string | null) ?? null,
    anulado_en: (s.anulado_en as string | null) ?? null,
    motivo_anulacion: (s.motivo_anulacion as string | null) ?? null,
    version: (s.version as string | null) ?? null,
    pendiente: 0,
  }
}

const texto = (v: unknown): string | null => (v === null || v === undefined ? null : String(v))
const fecha = (v: unknown): string => String(v).slice(0, 10)

function deudorDelServidor(s: Record<string, unknown>): Deudor {
  return {
    id: String(s.id),
    negocio_id: String(s.negocio_id),
    nombre: texto(s.nombre),
    telefono: texto(s.telefono),
    autorizacion_en: texto(s.autorizacion_en),
    anonimizado_en: texto(s.anonimizado_en),
    version: texto(s.version),
    pendiente: 0,
  }
}

function fiadoDelServidor(s: Record<string, unknown>): Fiado {
  return {
    id: String(s.id),
    negocio_id: String(s.negocio_id),
    deudor_id: String(s.deudor_id),
    fecha: fecha(s.fecha),
    descripcion: String(s.descripcion ?? ''),
    valor: numero(s.valor) ?? 0,
    fecha_pago: fecha(s.fecha_pago),
    anulado_en: texto(s.anulado_en),
    motivo_anulacion: texto(s.motivo_anulacion),
    version: texto(s.version),
    pendiente: 0,
  }
}

function abonoDelServidor(s: Record<string, unknown>): Abono {
  return {
    id: String(s.id),
    negocio_id: String(s.negocio_id),
    deudor_id: String(s.deudor_id),
    pago_id: texto(s.pago_id),
    fecha: fecha(s.fecha),
    valor: numero(s.valor) ?? 0,
    nota: texto(s.nota),
    anulado_en: texto(s.anulado_en),
    motivo_anulacion: texto(s.motivo_anulacion),
    version: texto(s.version),
    pendiente: 0,
  }
}

type Registro = Cierre | Pago | Deudor | Fiado | Abono
type Tabla = 'cierres' | 'pagos' | 'deudores' | 'fiados' | 'abonos'
const TABLAS: Tabla[] = ['cierres', 'pagos', 'deudores', 'fiados', 'abonos']

// Envío por partes. 400 está por debajo del límite más bajo del servidor (500 cierres por envío).
export const TAMANO_LOTE = 400
const MAX_ENVIOS = 50 // tope de seguridad por sincronización; lo que falte va en la siguiente
const ORDEN_ENVIO: Tabla[] = ['deudores', 'cierres', 'pagos', 'fiados', 'abonos']

// Lo que viaja al servidor: el registro sin los campos que solo usa el celular
function paraEnviar<T extends { pendiente: 0 | 1; negocio_id: string }>(registro: T) {
  const { pendiente: _p, negocio_id: _n, ...resto } = registro
  return resto
}

export const useSincronizacionStore = defineStore('sincronizacion', () => {
  const estado = ref<'en_espera' | 'sincronizando' | 'sin_conexion' | 'error'>('en_espera')
  const ultima = ref<string | null>(null)
  const estadoNegocio = ref<string>('activo')
  const pendientes = ref(0)
  const reloj = ref(Date.now())

  db.meta.bulkGet(['ultima_sincronizacion', 'estado_negocio']).then(([u, e]) => {
    ultima.value = u?.valor ?? null
    if (e) estadoNegocio.value = e.valor
  })

  liveQuery(async () => {
    const cuentas = await Promise.all(TABLAS.map((t) => db.table(t).where('pendiente').equals(1).count()))
    return cuentas.reduce((a, b) => a + b, 0)
  }).subscribe({
    next: (n) => (pendientes.value = n),
  })

  // Por qué la app solo deja consultar: negocio bloqueado (RF-12) o 7 días sin sincronizar (RN-27)
  const motivoSoloConsulta = computed<'bloqueado' | 'sin_sincronizar' | null>(() => {
    if (estadoNegocio.value === 'bloqueado') return 'bloqueado'
    if (ultima.value && reloj.value - Date.parse(ultima.value) > SIETE_DIAS) return 'sin_sincronizar'
    return null
  })
  const soloConsulta = computed(() => motivoSoloConsulta.value !== null)

  let enCurso: Promise<void> | null = null

  function sincronizar(): Promise<void> {
    enCurso ??= ejecutar().finally(() => (enCurso = null))
    return enCurso
  }

  async function ejecutar(): Promise<void> {
    const sesion = useSesionStore()
    if (!sesion.token || sesion.esAdmin || sesion.usuario?.debe_aceptar_terminos || sesion.usuario?.debe_cambiar_contrasena) return

    estado.value = 'sincronizando'
    try {
      // Por partes: si hay muchos cambios (por ejemplo, años cargados desde Excel), un solo envío
      // pasaría el límite del servidor y se rechazaría completo cada vez.
      let respuesta: Respuesta
      let envios = 0
      for (;;) {
        const lote = await tomarLote()
        respuesta = await pedir<Respuesta>('/sincronizar', { metodo: 'POST', token: sesion.token, cuerpo: lote.cuerpo })
        await aplicar(respuesta, lote.registros)
        envios++
        if (lote.registros.length < TAMANO_LOTE || envios >= MAX_ENVIOS) break
      }
      await leerPendientes(sesion.token) // fotos de comprobantes tomadas sin internet (RF-18)
      ultima.value = new Date().toISOString()
      estadoNegocio.value = respuesta.negocio.estado
      sesion.actualizarNegocio(respuesta.negocio)
      reloj.value = Date.now()
      estado.value = 'en_espera'
    } catch (error) {
      if (error instanceof ErrorApi && error.sinConexion) {
        estado.value = 'sin_conexion'
      } else {
        estado.value = 'error'
        if (error instanceof ErrorApi && (error.estado === 401 || error.estado === 403)) await sesion.actualizar()
      }
    }
  }

  /*
   * Hasta TAMANO_LOTE registros pendientes, tomados en el orden en que el servidor los guarda:
   * un fiado o un abono solo viaja cuando su deudor ya viajó, y un abono por Nequi cuando su pago
   * ya viajó (RN-39). Se leen en una sola transacción para tomar una foto fija de lo pendiente.
   */
  async function tomarLote(): Promise<{ cuerpo: Record<string, unknown>; registros: Registro[] }> {
    return db.transaction('r', [db.meta, ...ORDEN_ENVIO.map((t) => db.table(t))], async () => {
      const cuerpo: Record<string, unknown> = { desde: (await db.meta.get('desde'))?.valor ?? null }
      const registros: Registro[] = []
      for (const tabla of ORDEN_ENVIO) {
        const deEstaTabla = await db.table<Registro, string>(tabla).where('pendiente').equals(1).limit(TAMANO_LOTE - registros.length).toArray()
        cuerpo[tabla] = deEstaTabla.map(paraEnviar)
        registros.push(...deEstaTabla)
      }
      return { cuerpo, registros }
    })
  }

  async function aplicar(respuesta: Respuesta, enviados: Registro[]): Promise<void> {
    const copiaEnviada = new Map(enviados.map((r) => [r.id, JSON.stringify(r)]))

    await db.transaction('rw', [db.cierres, db.pagos, db.deudores, db.fiados, db.abonos, db.conflictos, db.meta], async () => {
      // RNF-12: se guarda la versión del servidor y la de este celular queda para que el dueño escoja
      for (const conflicto of respuesta.conflictos) {
        const servidor = cierreDelServidor(conflicto.servidor)
        const local = await db.cierres.get(conflicto.id_local)
        if (local) {
          await db.conflictos.put({ id: servidor.id, fecha: servidor.fecha, local, servidor })
          if (local.id !== servidor.id) await db.cierres.delete(local.id)
        }
        await db.cierres.put(servidor)
        copiaEnviada.delete(conflicto.id_local)
      }

      for (const s of respuesta.cierres) {
        const cierre = cierreDelServidor(s)
        const otroDelMismoDia = await db.cierres.where('fecha').equals(cierre.fecha).first()
        if (otroDelMismoDia && otroDelMismoDia.id !== cierre.id && otroDelMismoDia.pendiente === 0) {
          await db.cierres.delete(otroDelMismoDia.id)
        }
        await guardarDelServidor('cierres', cierre, copiaEnviada)
      }
      for (const s of respuesta.pagos) {
        await guardarDelServidor('pagos', pagoDelServidor(s), copiaEnviada)
      }

      const unidos = (respuesta.deudores ?? []).filter((s) => s.unido_a)
      for (const s of respuesta.deudores ?? []) {
        if (!s.unido_a) await guardarDelServidor('deudores', deudorDelServidor(s), copiaEnviada)
      }
      for (const s of respuesta.fiados ?? []) {
        await guardarDelServidor('fiados', fiadoDelServidor(s), copiaEnviada)
      }
      for (const s of respuesta.abonos ?? []) {
        await guardarDelServidor('abonos', abonoDelServidor(s), copiaEnviada)
      }
      // RN-32: un deudor que se unió a otro con el mismo teléfono; lo que quede con su id pasa a ese
      for (const s of unidos) await unirDeudor(String(s.id), String(s.unido_a))

      await db.meta.bulkPut([
        { clave: 'desde', valor: respuesta.hasta },
        { clave: 'ultima_sincronizacion', valor: new Date().toISOString() },
        { clave: 'estado_negocio', valor: respuesta.negocio.estado },
      ])
    })
  }

  // Si el registro cambió en el celular mientras se enviaba, se conserva el cambio local
  async function guardarDelServidor(nombre: Tabla, registro: Registro, copiaEnviada: Map<string, string>) {
    const tabla = db.table<Registro, string>(nombre)
    const local = await tabla.get(registro.id)
    if (local?.pendiente === 1) {
      const enviado = copiaEnviada.get(registro.id)
      if (enviado === undefined) return // cambios que todavía no se enviaron: van en la próxima
      if (enviado !== JSON.stringify(local)) {
        await tabla.update(registro.id, { version: registro.version })
        return
      }
    }
    await tabla.put(registro)
  }

  async function unirDeudor(repetido: string, real: string): Promise<void> {
    await db.fiados.where('deudor_id').equals(repetido).modify({ deudor_id: real })
    await db.abonos.where('deudor_id').equals(repetido).modify({ deudor_id: real })
    await db.deudores.delete(repetido)
  }

  // ---------- Sincronización automática ----------

  let temporizador: ReturnType<typeof setTimeout> | undefined
  let intervalo: ReturnType<typeof setInterval> | undefined

  // Después de guardar algo: espera un momento por si hay más cambios seguidos
  function programar(): void {
    clearTimeout(temporizador)
    temporizador = setTimeout(sincronizar, 1500)
  }

  const alVolverInternet = () => sincronizar()

  function iniciar(): void {
    detener()
    window.addEventListener('online', alVolverInternet)
    intervalo = setInterval(() => {
      reloj.value = Date.now()
      sincronizar()
    }, CADA_CINCO_MINUTOS)
    sincronizar()
  }

  function detener(): void {
    window.removeEventListener('online', alVolverInternet)
    clearInterval(intervalo)
    clearTimeout(temporizador)
  }

  function reiniciar(): void {
    detener()
    ultima.value = null
    estadoNegocio.value = 'activo'
    estado.value = 'en_espera'
  }

  return { estado, ultima, pendientes, motivoSoloConsulta, soloConsulta, sincronizar, programar, iniciar, detener, reiniciar }
})
