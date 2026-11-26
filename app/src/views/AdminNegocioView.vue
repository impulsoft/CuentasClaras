<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import {
  IconAlertTriangle,
  IconCalendarEvent,
  IconChartBar,
  IconCash,
  IconDownload,
  IconKey,
  IconLock,
  IconLockOpen,
  IconPencil,
  IconTrash,
  IconUserCheck,
  IconUserOff,
} from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import DescargarExcel from '../components/DescargarExcel.vue'
import FilaPagoSuscripcion from '../components/FilaPagoSuscripcion.vue'
import MesesSuscripcion from '../components/MesesSuscripcion.vue'
import HojaInferior from '../components/HojaInferior.vue'
import RegistrarPagoSuscripcion from '../components/RegistrarPagoSuscripcion.vue'
import { fechaCorta, fechaLarga, hoy, pesos } from '../calculos'
import { COLOR_SUSCRIPCION, haceCuanto, necesitaAyuda, NOMBRE_SUSCRIPCION, textoVencimiento, useAdminApi, type DetalleNegocio, type PagoSuscripcion } from '../admin'
import { ErrorApi } from '../api'
import { useDialogoStore } from '../stores/dialogo'

// RF-05, RF-08, RF-09, RF-12, RF-44, RF-46: un negocio visto por el admin
const props = defineProps<{ id: string }>()
const api = useAdminApi()
const dialogo = useDialogoStore()

const negocio = ref<DetalleNegocio | null>(null)
const error = ref('')

async function cargar() {
  error.value = ''
  try {
    negocio.value = await api.negocio(props.id)
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: el panel del admin necesita internet.' : 'No se pudo cargar el negocio.'
  }
}
onMounted(cargar)
watch(() => props.id, cargar)

const conAnio = (fecha: string) => `${fechaLarga(fecha)} de ${fecha.slice(0, 4)}`

// Acciones que solo cambian un estado, con su confirmación
async function hacer(accion: 'bloquear' | 'desbloquear' | 'activar' | 'desactivar') {
  const n = negocio.value!
  const textos = {
    bloquear: {
      titulo: `¿Bloquear ${n.nombre}?`,
      mensaje: 'El dueño podrá ver y descargar su información, pero no registrar cierres, fiados ni abonos.',
      aceptar: 'Bloquear',
      listo: 'Negocio bloqueado',
    },
    desbloquear: { titulo: `¿Desbloquear ${n.nombre}?`, mensaje: 'El dueño podrá volver a registrar.', aceptar: 'Desbloquear', listo: 'Negocio desbloqueado' },
    desactivar: {
      titulo: `¿Desactivar la cuenta de ${n.dueno?.nombre}?`,
      mensaje: 'No podrá entrar. Su información se guarda 6 meses y después se borra.',
      aceptar: 'Desactivar',
      listo: 'Cuenta desactivada',
    },
    activar: { titulo: `¿Activar la cuenta de ${n.dueno?.nombre}?`, mensaje: 'Podrá volver a entrar.', aceptar: 'Activar', listo: 'Cuenta activada' },
  }[accion]
  const confirmo = await dialogo.confirmar({
    titulo: textos.titulo,
    mensaje: textos.mensaje,
    aceptar: textos.aceptar,
    cancelar: 'Cancelar',
    peligro: accion === 'bloquear' || accion === 'desactivar',
  })
  if (!confirmo) return
  try {
    negocio.value = await api.accion(n.id, accion)
    dialogo.avisar(textos.listo)
  } catch {
    dialogo.avisar('No se pudo hacer el cambio. Revise la conexión.')
  }
}

// RF-46: el sistema crea la contraseña temporal; el admin se la dicta al dueño
const temporal = ref<string | null>(null)
async function restablecer() {
  const n = negocio.value!
  const confirmo = await dialogo.confirmar({
    titulo: '¿Restablecer la contraseña?',
    mensaje: `Se crea una contraseña temporal para ${n.dueno?.nombre}. Al entrar con ella, tendrá que crear una nueva. Sus otros dispositivos se cierran cuando se conecten.`,
    aceptar: 'Restablecer',
    cancelar: 'Cancelar',
  })
  if (!confirmo) return
  try {
    temporal.value = (await api.restablecer(n.id)).contrasena_temporal
    await cargar()
  } catch {
    dialogo.avisar('No se pudo restablecer. Revise la conexión.')
  }
}

// RF-05: corregir datos del negocio y del dueño
const editando = ref(false)
const datos = reactive({ nombre_negocio: '', nombre_dueno: '', telefono: '', usuario: '' })
const erroresEdicion = ref<ErrorApi | null>(null)

function abrirEdicion() {
  const n = negocio.value!
  Object.assign(datos, { nombre_negocio: n.nombre, nombre_dueno: n.dueno?.nombre ?? '', telefono: n.telefono ?? '', usuario: n.dueno?.usuario ?? '' })
  erroresEdicion.value = null
  editando.value = true
}

async function guardarEdicion() {
  erroresEdicion.value = null
  try {
    negocio.value = await api.editar(props.id, { ...datos })
    editando.value = false
    dialogo.avisar('Datos guardados')
  } catch (e) {
    erroresEdicion.value = e instanceof ErrorApi ? e : null
  }
}

const pagando = ref(false)
// Además de la cuadrícula por meses, la lista de todos los pagos (plegada)
const listaPagos = ref(false)
function alPagar(actualizado: DetalleNegocio) {
  negocio.value = actualizado
  pagando.value = false
}

// Un pago registrado por error se anula con su motivo; no se borra y el vencimiento se recalcula
async function anular(pago: PagoSuscripcion) {
  const n = negocio.value!
  const motivo = await dialogo.pedirTexto(
    `¿Anular el pago de ${pesos(pago.valor)} del ${fechaCorta(pago.fecha)} ${pago.fecha.slice(0, 4)}?`,
    'Escriba por qué se anula (por ejemplo: "lo registré dos veces")',
    '',
    'Anular pago',
  )
  if (!motivo) return
  try {
    negocio.value = await api.anularPago(n.id, pago.id, motivo)
    const quedo = negocio.value.suscripcion.estado
    dialogo.avisar(quedo === 'vencida' || quedo === 'sin_pago' ? 'Pago anulado. Ojo: la suscripción quedó vencida.' : 'Pago anulado')
  } catch {
    dialogo.avisar('No se pudo anular el pago. Revise la conexión.')
  }
}

/*
 * El dueño pidió cancelar el servicio y borrar su información (Ley 1581). Primero se le ofrece su
 * Excel; para borrar hay que escribir el nombre del negocio, así no se hace por un toque sin querer.
 */
const retirando = ref(false)
const descargando = ref(false)
const confirmacion = ref('')
const errorRetiro = ref('')
const borrando = ref(false)
// Igual que en la API: sin importar mayúsculas, tildes ni espacios de más
const normalizar = (texto: string) =>
  texto
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .trim()
    .replace(/\s+/g, ' ')
    .toLowerCase()
const nombreCoincide = computed(() => !!negocio.value && normalizar(confirmacion.value) === normalizar(negocio.value.nombre))

function abrirRetiro() {
  confirmacion.value = ''
  errorRetiro.value = ''
  retirando.value = true
}

// El Excel se abre en su propia ventana; al cerrarla se vuelve a la del retiro
function descargarAntes() {
  retirando.value = false
  descargando.value = true
}
function alCerrarExcel() {
  descargando.value = false
  retirando.value = true
}

async function retirar() {
  if (!nombreCoincide.value || borrando.value) return
  errorRetiro.value = ''
  borrando.value = true
  try {
    negocio.value = await api.retirar(props.id, confirmacion.value)
    retirando.value = false
    dialogo.avisar('Negocio retirado y datos borrados')
  } catch (e) {
    const sinConexion = e instanceof ErrorApi && e.sinConexion
    errorRetiro.value = sinConexion ? 'Sin conexión. Para retirar el negocio necesita internet.' : (e instanceof ErrorApi && e.de('confirmacion')) || 'No se pudo retirar. Intente de nuevo.'
  } finally {
    borrando.value = false
  }
}
</script>

<template>
  <div>
    <BarraVolver :titulo="negocio?.nombre ?? 'Negocio'" :volver-a="{ name: 'admin' }" />

    <main class="pantalla">
      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>

      <template v-if="negocio">
        <p v-if="negocio.estado === 'bloqueado'" class="mensaje error" role="status">
          <IconLock :size="20" aria-hidden="true" />
          Negocio bloqueado: el dueño solo puede consultar su información. Al registrar un pago se desbloquea.
        </p>

        <!-- RN-34 -->
        <p v-if="negocio.datos_borrados_en" class="mensaje error" role="status">
          <IconAlertTriangle :size="20" aria-hidden="true" />
          Los datos de este negocio se borraron el {{ conAnio(negocio.datos_borrados_en.slice(0, 10)) }}.
        </p>
        <p v-else-if="negocio.datos_se_borran_el" class="mensaje error" role="status">
          <IconAlertTriangle :size="20" aria-hidden="true" />
          Si no renueva, sus datos se borrarán el {{ conAnio(negocio.datos_se_borran_el) }}. Llame al negocio para preguntarle qué hacer.
        </p>

        <!-- RF-08 / RF-09 -->
        <section class="tarjeta">
          <div class="encabezado">
            <h2>Suscripción</h2>
            <span class="etiqueta" :class="COLOR_SUSCRIPCION[negocio.suscripcion.estado]">{{ NOMBRE_SUSCRIPCION[negocio.suscripcion.estado] }}</span>
          </div>
          <div class="vence" :class="negocio.suscripcion.estado">
            <strong>{{ textoVencimiento(negocio.suscripcion.dias) }}</strong>
            <span v-if="negocio.suscripcion.vence_el">Al día hasta el {{ conAnio(negocio.suscripcion.vence_el) }}</span>
            <span v-if="negocio.suscripcion.inicio_el" class="pequeno">Cliente desde el {{ conAnio(negocio.suscripcion.inicio_el) }}</span>
          </div>
          <button class="boton" @click="pagando = true"><IconCash :size="22" aria-hidden="true" /> Registrar pago</button>

          <h3>Pagos por mes</h3>
          <template v-if="negocio.pagos.length">
            <MesesSuscripcion :pagos="negocio.pagos" :hoy="hoy()" :cliente-desde="negocio.suscripcion.inicio_el" @anular="anular" />
            <button v-if="negocio.pagos.length > 1" type="button" class="boton-texto ver-lista" :aria-expanded="listaPagos" @click="listaPagos = !listaPagos">
              {{ listaPagos ? 'Ocultar la lista de pagos' : `Ver los ${negocio.pagos.length} pagos en una lista` }}
            </button>
            <ul v-if="listaPagos" class="lista">
              <FilaPagoSuscripcion v-for="p in negocio.pagos" :key="p.id" :pago="p" @anular="anular" />
            </ul>
          </template>
          <p v-else class="suave">Todavía no hay pagos registrados.</p>
        </section>

        <!-- RF-07 / RF-44 -->
        <section class="tarjeta">
          <h2>Sus números</h2>
          <!-- Actividad: si dejó de registrar, conviene llamarlo -->
          <ul class="actividad">
            <li>
              <span class="suave">Último cierre</span>
              <strong :class="{ alerta: necesitaAyuda(negocio, hoy()) }">
                {{ negocio.actividad.ultimo_cierre ? `${fechaLarga(negocio.actividad.ultimo_cierre)} (${haceCuanto(negocio.actividad.ultimo_cierre, hoy())})` : 'Todavía ninguno' }}
              </strong>
            </li>
            <li>
              <span class="suave">Usó la app con internet</span>
              <strong>{{ negocio.actividad.ultima_conexion ? haceCuanto(negocio.actividad.ultima_conexion, hoy()) : 'Todavía no' }}</strong>
            </li>
          </ul>
          <div class="dos-botones">
            <RouterLink :to="{ name: 'admin-cierres', params: { id } }" class="boton secundario">
              <IconCalendarEvent :size="22" aria-hidden="true" /> Cierres
            </RouterLink>
            <RouterLink :to="{ name: 'admin-analisis', params: { id } }" class="boton secundario">
              <IconChartBar :size="22" aria-hidden="true" /> Análisis
            </RouterLink>
          </div>
          <div class="recuadro deudas">
            <span class="suave">Le deben sus clientes</span>
            <strong class="monto">{{ pesos(negocio.deudas.por_cobrar) }}</strong>
            <span class="suave pequeno">
              {{ negocio.deudas.deudores }} {{ negocio.deudas.deudores === 1 ? 'deudor' : 'deudores' }} · {{ negocio.deudas.vencidos }}
              {{ negocio.deudas.vencidos === 1 ? 'con deuda vencida' : 'con deudas vencidas' }}
            </span>
          </div>
          <p class="suave pequeno">Los nombres y teléfonos de los deudores solo los ve el dueño.</p>
        </section>

        <!-- RF-05 -->
        <section class="tarjeta">
          <div class="encabezado">
            <h2>Dueño</h2>
            <span class="etiqueta" :class="negocio.dueno?.activo ? 'cerrado' : 'no_abrio'">{{ negocio.dueno?.activo ? 'Activo' : 'Desactivado' }}</span>
          </div>
          <dl class="ficha">
            <dt>Nombre</dt>
            <dd>{{ negocio.dueno?.nombre }}</dd>
            <dt>Usuario</dt>
            <dd>{{ negocio.dueno?.usuario }}</dd>
            <dt>Teléfono</dt>
            <dd>{{ negocio.telefono }}</dd>
          </dl>
          <p v-if="negocio.dueno?.debe_cambiar_contrasena" class="mensaje aviso">
            <IconKey :size="20" aria-hidden="true" /> Tiene una contraseña temporal sin cambiar.
          </p>
          <div class="botones">
            <button class="boton secundario" @click="abrirEdicion"><IconPencil :size="22" aria-hidden="true" /> Corregir datos</button>
            <button class="boton secundario" @click="restablecer"><IconKey :size="22" aria-hidden="true" /> Restablecer contraseña</button>
            <button v-if="negocio.estado === 'bloqueado'" class="boton secundario" @click="hacer('desbloquear')">
              <IconLockOpen :size="22" aria-hidden="true" /> Desbloquear negocio
            </button>
            <button v-else class="boton secundario peligro" @click="hacer('bloquear')"><IconLock :size="22" aria-hidden="true" /> Bloquear negocio</button>
            <button v-if="negocio.dueno?.activo" class="boton secundario peligro" @click="hacer('desactivar')">
              <IconUserOff :size="22" aria-hidden="true" /> Desactivar cuenta
            </button>
            <button v-else class="boton secundario" @click="hacer('activar')"><IconUserCheck :size="22" aria-hidden="true" /> Activar cuenta</button>
          </div>
        </section>

        <!-- A pedido del dueño (Ley 1581): no se puede deshacer -->
        <section v-if="!negocio.datos_borrados_en" class="tarjeta">
          <h2>Cancelar el servicio</h2>
          <p class="suave">Si el dueño pide dejar de usar Cierre de Caja y que se borre su información, hágalo aquí.</p>
          <button class="boton secundario peligro" @click="abrirRetiro"><IconTrash :size="22" aria-hidden="true" /> Retirar negocio</button>
        </section>
      </template>
    </main>

    <HojaInferior :abierta="retirando" titulo="Retirar negocio" @cerrar="retirando = false" @enviar="retirar">
      <p class="mensaje error">
        <IconAlertTriangle :size="20" aria-hidden="true" />
        Se borran para siempre los cierres, pagos, fotos de comprobantes, fiados y deudores de {{ negocio?.nombre }}, y la cuenta de
        {{ negocio?.dueno?.nombre }} queda desactivada. No se puede deshacer.
      </p>
      <p><strong>1.</strong> Antes, descargue su información en Excel para entregársela al dueño.</p>
      <button type="button" class="boton secundario" @click="descargarAntes"><IconDownload :size="22" aria-hidden="true" /> Descargar Excel</button>
      <label class="campo">
        <span class="paso"><strong>2.</strong> Para confirmar, escriba el nombre del negocio: <strong>{{ negocio?.nombre }}</strong></span>
        <span class="control"><input v-model="confirmacion" autocomplete="off" autocapitalize="off" spellcheck="false" /></span>
        <span v-if="errorRetiro" class="error">{{ errorRetiro }}</span>
      </label>
      <template #acciones>
        <button type="button" class="boton secundario" @click="retirando = false">Cancelar</button>
        <button class="boton borrar" :disabled="!nombreCoincide || borrando">{{ borrando ? 'Borrando…' : 'Borrar todo' }}</button>
      </template>
    </HojaInferior>

    <DescargarExcel :abierta="descargando" :ruta="`/admin/negocios/${id}/excel`" inicial="todo" @cerrar="alCerrarExcel" />

    <RegistrarPagoSuscripcion :negocio="pagando ? negocio : null" @cerrar="pagando = false" @pagado="alPagar" />

    <!-- RF-46: la contraseña temporal se muestra una sola vez -->
    <HojaInferior :abierta="!!temporal" titulo="Contraseña temporal" @cerrar="temporal = null" @enviar="temporal = null">
      <p>Díctele esta contraseña a {{ negocio?.dueno?.nombre }}. Al entrar, la app le pedirá crear una nueva.</p>
      <p class="temporal monto">{{ temporal }}</p>
      <p class="suave pequeno">No se vuelve a mostrar. Si se pierde, restablézcala otra vez.</p>
      <template #acciones>
        <button class="boton listo">Listo</button>
      </template>
    </HojaInferior>

    <!-- RF-05 -->
    <HojaInferior :abierta="editando" titulo="Corregir datos" @cerrar="editando = false" @enviar="guardarEdicion">
      <label v-for="[clave, etiqueta] in [['nombre_negocio', 'Nombre del negocio'], ['nombre_dueno', 'Nombre del dueño'], ['telefono', 'Teléfono'], ['usuario', 'Usuario para entrar']] as const" :key="clave" class="campo">
        {{ etiqueta }}
        <span class="control"><input v-model="datos[clave]" autocomplete="off" :inputmode="clave === 'telefono' ? 'numeric' : undefined" /></span>
        <span v-if="erroresEdicion?.de(clave)" class="error">{{ erroresEdicion.de(clave) }}</span>
      </label>
      <template #acciones>
        <button type="button" class="boton secundario" @click="editando = false">Cancelar</button>
        <button class="boton">Guardar</button>
      </template>
    </HojaInferior>
  </div>
</template>

<style scoped>
.encabezado {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.vence {
  display: grid;
  gap: 2px;
  padding: 16px 18px;
  border-radius: var(--radio-control);
  color: var(--verde-900);
  background: var(--verde-50);
}

.vence strong {
  font-size: 1.3rem;
}

.vence.por_vencer {
  color: var(--aviso-texto);
  background: var(--aviso-fondo);
}

.vence.vencida,
.vence.sin_pago {
  color: var(--error);
  background: var(--error-fondo);
}

h3 {
  color: var(--texto-suave);
}

.lista {
  list-style: none;
  margin: -6px 0 0;
  padding: 0;
}

.ver-lista {
  justify-self: center;
  min-height: 44px;
}

/* El paso 2 se lee igual que el paso 1 (la etiqueta de un campo es más pequeña) */
.paso {
  font-size: 1rem;
  font-weight: 400;
  color: var(--texto);
}

.borrar {
  color: #fff;
  background: var(--error);
  border-color: var(--error);
}

.borrar:disabled {
  opacity: 0.45;
}

.boton {
  text-decoration: none;
}

/* minmax(0, 1fr): con 1fr solo, a 320 px los dos botones empujaban la página de lado */
.dos-botones {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.dos-botones .boton {
  padding-left: 10px;
  padding-right: 10px;
}

.actividad {
  list-style: none;
  margin: 0;
  padding: 0;
}

.actividad li {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  gap: 12px;
  padding: 10px 0;
  border-bottom: 1px solid var(--linea);
}

.actividad strong {
  text-align: right;
}

/* 3 días o más sin cierre: se resalta para que el admin lo llame */
.actividad .alerta {
  color: var(--error);
}

.deudas {
  display: grid;
  gap: 2px;
}

.deudas strong {
  font-size: 1.5rem;
}

.ficha {
  display: grid;
  grid-template-columns: auto minmax(0, 1fr);
  gap: 6px 16px;
  margin: 0;
}

.ficha dt {
  color: var(--texto-suave);
}

.ficha dd {
  margin: 0;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.botones {
  display: grid;
  gap: 10px;
}

.peligro {
  color: var(--error);
}

.temporal {
  padding: 16px;
  text-align: center;
  font-size: 2rem;
  font-weight: 800;
  letter-spacing: 0.06em;
  color: var(--verde-900);
  background: var(--verde-50);
  border-radius: var(--radio-control);
  user-select: all;
}

.listo {
  grid-column: 1 / -1;
}
</style>
