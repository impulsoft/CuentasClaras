<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import {
  IconAlertTriangle,
  IconBrandWhatsapp,
  IconBuildingStore,
  IconChevronRight,
  IconCircleCheck,
  IconLock,
  IconPhone,
  IconPlus,
  IconSearch,
  IconUser,
} from '@tabler/icons-vue'
import BarraSuperior from '../components/BarraSuperior.vue'
import HojaInferior from '../components/HojaInferior.vue'
import CampoPesos from '../components/CampoPesos.vue'
import RegistrarPagoSuscripcion from '../components/RegistrarPagoSuscripcion.vue'
import { fechaCorta, hoy, pesos } from '../calculos'
import {
  COLOR_SUSCRIPCION,
  DIAS_SIN_CIERRE,
  enlaceAyuda,
  NOMBRE_SUSCRIPCION,
  porAyudar,
  textoUltimoCierre,
  textoVencimiento,
  useAdminApi,
  type NegocioAdmin,
  type ResumenAdmin,
} from '../admin'
import { ErrorApi } from '../api'
import { useSesionStore } from '../stores/sesion'
import { useDialogoStore } from '../stores/dialogo'

// RF-06, RF-11, RF-13: panel del admin
const sesion = useSesionStore()
const dialogo = useDialogoStore()
const api = useAdminApi()

const negocios = ref<NegocioAdmin[]>([])
const resumen = ref<ResumenAdmin | null>(null)
const cargando = ref(true)
const errorLista = ref('')

// El panel del admin necesita internet (RNF-01)
async function cargar() {
  errorLista.value = ''
  try {
    ;[negocios.value, resumen.value] = await Promise.all([api.negocios(), api.resumen()])
  } catch (e) {
    errorLista.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: el panel del admin necesita internet.' : 'No se pudo cargar la información.'
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

const cifras = computed(() => [
  { valor: resumen.value?.activos, texto: 'Al día', clase: 'activo' },
  { valor: resumen.value?.por_vencer, texto: 'Por vencer', clase: 'aviso' },
  { valor: resumen.value?.vencidos, texto: 'Vencidos', clase: 'peligro' },
])

// RF-11: "Suscripción vencida: Tienda Don José" con "Ya pagó" o "Bloquear"
const vencidos = computed(() =>
  negocios.value.filter((n) => (n.suscripcion.estado === 'vencida' || n.suscripcion.estado === 'sin_pago') && !n.datos_borrados_en),
)

// Negocios que llevan días sin registrar el cierre: el admin los llama antes de que dejen la app
const ayudar = computed(() => porAyudar(negocios.value, hoy()))
const telefonoDe = (n: NegocioAdmin) => (n.dueno?.telefono ?? n.telefono ?? '').replace(/\D/g, '')

const busqueda = ref('')
const visibles = computed(() => {
  const texto = busqueda.value.trim().toLowerCase()
  if (!texto) return negocios.value
  return negocios.value.filter((n) => [n.nombre, n.dueno?.nombre, n.dueno?.usuario].some((v) => v?.toLowerCase().includes(texto)))
})

const pagando = ref<NegocioAdmin | null>(null)
async function alPagar() {
  pagando.value = null
  await cargar()
}

async function bloquear(negocio: NegocioAdmin) {
  const confirmo = await dialogo.confirmar({
    titulo: `¿Bloquear ${negocio.nombre}?`,
    mensaje: 'El dueño podrá ver y descargar su información, pero no registrar cierres, fiados ni abonos hasta que pague.',
    aceptar: 'Bloquear',
    cancelar: 'Cancelar',
    peligro: true,
  })
  if (!confirmo) return
  try {
    await api.accion(negocio.id, 'bloquear')
    dialogo.avisar(`${negocio.nombre} quedó bloqueado`)
    await cargar()
  } catch {
    dialogo.avisar('No se pudo bloquear. Revise la conexión.')
  }
}

// ---------- RF-04: registrar dueño, negocio y primer pago ----------
const vacio = { nombre_negocio: '', nombre_dueno: '', telefono: '', usuario: '', contrasena: '' }
const nuevo = reactive({ ...vacio })
const valorPago = ref<number | null>(null)
const fechaPago = ref(hoy())
const registrando = ref(false)
const errores = ref<ErrorApi | null>(null)
const enviando = ref(false)

function abrirRegistro() {
  Object.assign(nuevo, vacio)
  valorPago.value = negocios.value.find((n) => n.ultimo_valor)?.ultimo_valor ?? null
  fechaPago.value = hoy()
  errores.value = null
  registrando.value = true
}

async function registrar() {
  errores.value = null
  enviando.value = true
  try {
    const creado = await api.registrar({ ...nuevo, valor_pago: valorPago.value, fecha_pago: fechaPago.value })
    dialogo.avisar(`${creado.nombre} quedó registrado`)
    registrando.value = false
    await cargar()
  } catch (e) {
    errores.value = e instanceof ErrorApi ? e : new ErrorApi('Ocurrió un error. Intente de nuevo.', 500)
  } finally {
    enviando.value = false
  }
}

const campos = [
  { clave: 'nombre_negocio', etiqueta: 'Nombre del negocio', tipo: 'text', icono: IconBuildingStore, ejemplo: 'Ej.: Tienda Don José' },
  { clave: 'nombre_dueno', etiqueta: 'Nombre del dueño', tipo: 'text', icono: IconUser, ejemplo: 'Ej.: José Martínez' },
  { clave: 'telefono', etiqueta: 'Teléfono', tipo: 'tel', icono: IconPhone, ejemplo: 'Ej.: 3101234567' },
  { clave: 'usuario', etiqueta: 'Usuario para entrar', tipo: 'text', icono: IconUser, ejemplo: 'Ej.: tienda.jose' },
  { clave: 'contrasena', etiqueta: 'Contraseña inicial', tipo: 'password', icono: IconLock, ejemplo: 'Mínimo 8 caracteres' },
] as const

const iniciales = (nombre: string) =>
  nombre
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join('')
</script>

<template>
  <div>
    <BarraSuperior titulo="Panel del admin" admin @salir="sesion.cerrar()">
      <p class="sub">{{ sesion.usuario?.nombre }}</p>
    </BarraSuperior>

    <main class="pantalla ancha">
      <p v-if="errorLista" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ errorLista }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>

      <!-- RF-13 -->
      <section class="resumen" aria-label="Resumen">
        <div v-for="c in cifras" :key="c.texto" class="cifra tarjeta">
          <span class="valor monto" :class="c.clase">{{ cargando ? '–' : c.valor }}</span>
          <span class="suave">{{ c.texto }}</span>
        </div>
        <div class="cifra tarjeta recaudado">
          <span class="valor monto">{{ cargando ? '–' : pesos(resumen?.recaudado_mes ?? 0) }}</span>
          <span class="suave">Recaudado este mes</span>
        </div>
      </section>

      <!-- RF-11 -->
      <section v-if="vencidos.length" class="tarjeta alertas" aria-label="Suscripciones vencidas">
        <h2>Suscripciones vencidas</h2>
        <ul class="lista">
          <li v-for="n in vencidos" :key="n.id" class="alerta">
            <div class="datos">
              <strong>{{ n.nombre }}</strong>
              <span class="suave pequeno">
                {{ textoVencimiento(n.suscripcion.dias) }}<template v-if="n.estado === 'bloqueado'"> · bloqueado</template>
              </span>
            </div>
            <div class="botones-alerta">
              <button class="boton" @click="pagando = n">Ya pagó</button>
              <button v-if="n.estado !== 'bloqueado'" class="boton secundario peligro" @click="bloquear(n)">Bloquear</button>
            </div>
          </li>
        </ul>
      </section>

      <!-- Negocios sin actividad -->
      <section v-if="ayudar.length" class="tarjeta alertas" aria-label="Negocios que necesitan ayuda">
        <h2>Necesitan ayuda</h2>
        <p class="suave pequeno">Llevan {{ DIAS_SIN_CIERRE }} días o más sin registrar el cierre. Una llamada a tiempo evita que dejen de usar la app.</p>
        <ul class="lista">
          <li v-for="n in ayudar" :key="n.id" class="alerta">
            <div class="datos">
              <strong>{{ n.nombre }}</strong>
              <span class="suave pequeno">{{ textoUltimoCierre(n, hoy()) }}</span>
            </div>
            <div v-if="telefonoDe(n)" class="botones-alerta">
              <a class="boton secundario" :href="`tel:${telefonoDe(n)}`"><IconPhone :size="20" aria-hidden="true" /> Llamar</a>
              <a class="boton secundario whatsapp" :href="enlaceAyuda(n, hoy()) ?? undefined" target="_blank" rel="noopener">
                <IconBrandWhatsapp :size="20" aria-hidden="true" /> WhatsApp
              </a>
            </div>
          </li>
        </ul>
      </section>

      <!-- RF-06 -->
      <section class="tarjeta">
        <div class="encabezado">
          <h2>Negocios</h2>
          <button class="boton" @click="abrirRegistro"><IconPlus :size="22" :stroke-width="2.5" aria-hidden="true" /> Nuevo negocio</button>
        </div>
        <label v-if="negocios.length > 4" class="campo buscar">
          <span class="control">
            <IconSearch class="icono" :size="22" aria-hidden="true" />
            <input v-model="busqueda" type="search" placeholder="Buscar negocio o dueño" aria-label="Buscar negocio o dueño" />
          </span>
        </label>
        <p v-if="cargando" class="suave">Cargando…</p>
        <p v-else-if="!negocios.length && !errorLista" class="suave">Todavía no hay negocios registrados.</p>
        <ul v-else class="lista">
          <li v-for="n in visibles" :key="n.id">
            <RouterLink :to="{ name: 'admin-negocio', params: { id: n.id } }" class="fila">
              <span class="avatar" aria-hidden="true">{{ iniciales(n.nombre) }}</span>
              <span class="datos">
                <strong>{{ n.nombre }}</strong>
                <span class="suave pequeno">{{ n.dueno?.nombre }}</span>
                <span class="estado">
                  <span v-if="n.estado === 'bloqueado'" class="etiqueta peligro">Bloqueado</span>
                  <span v-else-if="n.dueno && !n.dueno.activo" class="etiqueta no_abrio">Desactivado</span>
                  <span v-else class="etiqueta" :class="COLOR_SUSCRIPCION[n.suscripcion.estado]">{{ NOMBRE_SUSCRIPCION[n.suscripcion.estado] }}</span>
                  <span v-if="n.suscripcion.vence_el" class="suave pequeno">vence el {{ fechaCorta(n.suscripcion.vence_el) }}</span>
                </span>
                <span class="suave pequeno">{{ textoUltimoCierre(n, hoy()) }}</span>
              </span>
              <IconChevronRight class="flecha" :size="20" aria-hidden="true" />
            </RouterLink>
          </li>
        </ul>
      </section>
    </main>

    <RegistrarPagoSuscripcion :negocio="pagando" @cerrar="pagando = null" @pagado="alPagar" />

    <!-- RF-04 -->
    <HojaInferior :abierta="registrando" titulo="Registrar negocio" @cerrar="registrando = false" @enviar="registrar">
      <p class="suave pequeno">El dueño entra con este usuario y contraseña. Al entrar por primera vez acepta los términos.</p>
      <label v-for="campo in campos" :key="campo.clave" class="campo">
        {{ campo.etiqueta }}
        <span class="control">
          <component :is="campo.icono" class="icono" :size="22" aria-hidden="true" />
          <input
            v-model="nuevo[campo.clave]"
            :type="campo.tipo"
            :inputmode="campo.clave === 'telefono' ? 'numeric' : undefined"
            :placeholder="campo.ejemplo"
            autocomplete="off"
          />
        </span>
        <span v-if="errores?.de(campo.clave)" class="error">{{ errores.de(campo.clave) }}</span>
      </label>

      <h3 class="subtitulo">Primer pago de la suscripción</h3>
      <CampoPesos v-model="valorPago" etiqueta="Valor pagado" />
      <span v-if="errores?.de('valor_pago')" class="error">{{ errores.de('valor_pago') }}</span>
      <label class="campo">
        Fecha del pago
        <span class="control"><input v-model="fechaPago" type="date" :max="hoy()" /></span>
        <span v-if="errores?.de('fecha_pago')" class="error">{{ errores.de('fecha_pago') }}</span>
      </label>

      <p v-if="errores && Object.keys(errores.errores).length === 0" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ errores.message }}
      </p>
      <template #acciones>
        <button type="button" class="boton secundario" @click="registrando = false">Cancelar</button>
        <button class="boton" :disabled="enviando"><IconCircleCheck :size="22" aria-hidden="true" /> Registrar</button>
      </template>
    </HojaInferior>
  </div>
</template>

<style scoped>
.sub {
  font-size: 0.85rem;
  color: var(--verde-50);
}

.resumen {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.cifra {
  gap: 2px;
  padding: 14px 10px;
  text-align: center;
  font-size: 0.85rem;
}

.cifra .valor {
  font-size: 1.8rem;
  font-weight: 800;
  line-height: 1.1;
}

.cifra .activo {
  color: var(--verde-700);
}

.cifra .aviso {
  color: var(--sin-cierre-texto);
}

.cifra .peligro {
  color: var(--error);
}

.recaudado {
  grid-column: 1 / -1;
  background: var(--verde-50);
  box-shadow: none;
}

.recaudado .valor {
  color: var(--verde-800);
}

.alertas {
  border: 1.5px solid var(--error-fondo);
}

.encabezado {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.encabezado .boton {
  min-height: 48px;
  padding: 10px 16px;
}

.buscar input {
  padding-left: 46px;
}

.lista {
  list-style: none;
  margin: 0;
  padding: 0;
}

.lista li {
  border-top: 1px solid var(--linea);
}

.lista li:first-child {
  border-top: none;
}

.fila {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 0;
  color: inherit;
  text-decoration: none;
}

.alerta {
  display: grid;
  gap: 10px;
  padding: 12px 0;
}

.botones-alerta {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}

.botones-alerta .boton {
  min-height: 44px;
  padding: 8px 14px;
  font-size: 0.95rem;
}

.boton.peligro {
  color: var(--error);
}

.whatsapp {
  color: #0b6b3a;
}

.avatar {
  width: 44px;
  height: 44px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 14px;
  font-weight: 800;
  color: var(--verde-800);
  background: var(--verde-50);
}

.datos {
  flex: 1;
  min-width: 0;
  display: grid;
}

.datos > strong,
.datos > span {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.estado {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-top: 4px;
}

.flecha {
  flex: none;
  color: var(--texto-tenue);
}

.subtitulo {
  margin-top: 6px;
  color: var(--texto-suave);
}
</style>
