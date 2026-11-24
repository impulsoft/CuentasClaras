<script setup lang="ts">
import { onMounted, reactive, ref, watch } from 'vue'
import {
  IconAlertTriangle,
  IconCalendarEvent,
  IconChartBar,
  IconCash,
  IconKey,
  IconLock,
  IconLockOpen,
  IconPencil,
  IconUserCheck,
  IconUserOff,
} from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import HojaInferior from '../components/HojaInferior.vue'
import RegistrarPagoSuscripcion from '../components/RegistrarPagoSuscripcion.vue'
import { fechaCorta, fechaLarga, hoy, pesos } from '../calculos'
import { COLOR_SUSCRIPCION, haceCuanto, necesitaAyuda, NOMBRE_SUSCRIPCION, textoVencimiento, useAdminApi, type DetalleNegocio } from '../admin'
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
function alPagar(actualizado: DetalleNegocio) {
  negocio.value = actualizado
  pagando.value = false
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
          Los datos de este negocio se borraron por llevar 6 meses sin servicio.
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

          <h3>Pagos</h3>
          <ul v-if="negocio.pagos.length" class="lista">
            <li v-for="p in negocio.pagos" :key="p.id">
              <span class="datos">
                <span>{{ fechaCorta(p.fecha) }} {{ p.fecha.slice(0, 4) }}<template v-if="p.nota"> · {{ p.nota }}</template></span>
                <span class="suave pequeno">Hasta el {{ fechaCorta(p.vence_el) }} {{ p.vence_el.slice(0, 4) }}</span>
              </span>
              <strong class="monto">{{ pesos(p.valor) }}</strong>
            </li>
          </ul>
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
      </template>
    </main>

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

.lista li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  min-height: 52px;
  padding: 8px 0;
  border-bottom: 1px solid var(--linea);
}

.datos {
  min-width: 0;
  display: grid;
}

.boton {
  text-decoration: none;
}

.dos-botones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
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
