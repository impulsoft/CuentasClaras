<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute } from 'vue-router'
import { IconAlertTriangle, IconBuildingStore, IconChevronRight, IconCircleCheck, IconLock, IconPhone, IconPlus, IconSearch, IconUser } from '@tabler/icons-vue'
import AdminNavegacion from '../components/AdminNavegacion.vue'
import BarraSuperior from '../components/BarraSuperior.vue'
import CampoPesos from '../components/CampoPesos.vue'
import HojaInferior from '../components/HojaInferior.vue'
import { fechaCorta, hoy } from '../calculos'
import {
  COLOR_SUSCRIPCION,
  contarGrupos,
  filtrarNegocios,
  GRUPOS,
  negocioDesactivado,
  NOMBRE_SUSCRIPCION,
  textoUltimoCierre,
  useAdminApi,
  type GrupoNegocio,
  type NegocioAdmin,
} from '../admin'
import { ErrorApi } from '../api'
import { useSesionStore } from '../stores/sesion'
import { useDialogoStore } from '../stores/dialogo'

// RF-04 / RF-06: todos los negocios, con filtro por estado y búsqueda, y el registro de uno nuevo
const sesion = useSesionStore()
const dialogo = useDialogoStore()
const api = useAdminApi()
const route = useRoute()

const negocios = ref<NegocioAdmin[]>([])
const cargando = ref(true)
const error = ref('')

async function cargar() {
  error.value = ''
  try {
    negocios.value = await api.negocios()
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: el panel del admin necesita internet.' : 'No se pudo cargar la lista.'
  } finally {
    cargando.value = false
  }
}
onMounted(cargar)

// Filtro por estado y búsqueda. Desde el inicio se llega con el grupo ya escogido (?grupo=vencidos).
const busqueda = ref('')
const grupoInicial = GRUPOS.some((g) => g.valor === route.query.grupo) ? (route.query.grupo as GrupoNegocio) : 'todos'
const filtro = ref<GrupoNegocio | 'todos'>(grupoInicial)
const cuenta = computed(() => contarGrupos(negocios.value))
const grupos = computed(() => GRUPOS.filter((g) => g.valor === 'todos' || g.valor === filtro.value || cuenta.value[g.valor] > 0))
// Si el grupo escogido se queda sin negocios (por ejemplo, después de "Ya pagó"), se vuelve a "Todos"
const filtroActivo = computed(() => (filtro.value === 'todos' || cuenta.value[filtro.value] > 0 || cargando.value ? filtro.value : 'todos'))
const visibles = computed(() => filtrarNegocios(negocios.value, filtroActivo.value, busqueda.value))

const iniciales = (nombre: string) =>
  nombre
    .split(' ')
    .filter(Boolean)
    .slice(0, 2)
    .map((p) => p[0]!.toUpperCase())
    .join('')

// ---------- RF-04: registrar dueño, negocio y primer pago ----------
const vacio = { nombre_negocio: '', nombre_dueno: '', telefono: '', usuario: '', contrasena: '' }
const nuevo = reactive({ ...vacio })
const valorPago = ref<number | null>(null)
const fechaPago = ref(hoy())
// El admin decide si le deja el primer mes gratis: entonces no paga nada ahora y el mes corre igual
const primerMesGratis = ref(false)
const registrando = ref(false)
const errores = ref<ErrorApi | null>(null)
const enviando = ref(false)

function abrirRegistro() {
  Object.assign(nuevo, vacio)
  valorPago.value = negocios.value.find((n) => n.ultimo_valor)?.ultimo_valor ?? null
  fechaPago.value = hoy()
  primerMesGratis.value = false
  errores.value = null
  registrando.value = true
}

async function registrar() {
  errores.value = null
  enviando.value = true
  try {
    const creado = await api.registrar({
      ...nuevo,
      primer_mes_gratis: primerMesGratis.value,
      valor_pago: primerMesGratis.value ? null : valorPago.value,
      fecha_pago: fechaPago.value,
    })
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
</script>

<template>
  <div class="con-navegacion">
    <BarraSuperior titulo="Negocios" admin @salir="sesion.cerrar()">
      <p class="sub">{{ cargando ? 'Cargando…' : negocios.length === 1 ? '1 negocio' : `${negocios.length} negocios` }}</p>
    </BarraSuperior>

    <main class="pantalla ancha">
      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>

      <div class="encabezado">
        <label v-if="negocios.length > 4" class="campo buscar">
          <span class="control">
            <IconSearch class="icono" :size="22" aria-hidden="true" />
            <input v-model="busqueda" type="search" placeholder="Buscar negocio o dueño" aria-label="Buscar negocio o dueño" />
          </span>
        </label>
        <button class="boton nuevo" @click="abrirRegistro"><IconPlus :size="22" :stroke-width="2.5" aria-hidden="true" /> Nuevo</button>
      </div>

      <!-- Filtro por estado: aparece cuando hay negocios en más de un grupo -->
      <div v-if="grupos.length > 2" class="filtros" role="group" aria-label="Mostrar negocios">
        <button
          v-for="g in grupos"
          :key="g.valor"
          type="button"
          class="filtro"
          :class="{ activo: filtroActivo === g.valor }"
          :aria-pressed="filtroActivo === g.valor"
          @click="filtro = g.valor"
        >
          {{ g.texto }} <span class="cuenta">{{ cuenta[g.valor] }}</span>
        </button>
      </div>

      <p v-if="cargando" class="suave">Cargando…</p>
      <section v-else-if="!negocios.length && !error" class="tarjeta vacio">
        <span class="icono-grande" aria-hidden="true"><IconBuildingStore :size="30" /></span>
        <h3>Registre su primer negocio</h3>
        <p class="suave">Toque «Nuevo» para crear el negocio, la cuenta del dueño y su primer pago.</p>
      </section>
      <p v-else-if="negocios.length && !visibles.length" class="suave centro">Ningún negocio coincide con la búsqueda.</p>

      <ul v-else-if="visibles.length" class="lista">
        <li v-for="n in visibles" :key="n.id">
          <RouterLink :to="{ name: 'admin-negocio', params: { id: n.id } }" class="fila">
            <span class="avatar" aria-hidden="true">{{ iniciales(n.nombre) }}</span>
            <span class="datos">
              <strong>{{ n.nombre }}</strong>
              <span class="suave pequeno">{{ n.dueno?.nombre }} · {{ textoUltimoCierre(n, hoy()) }}</span>
              <span class="estado">
                <span v-if="n.estado === 'bloqueado'" class="etiqueta peligro">Bloqueado</span>
                <span v-else-if="negocioDesactivado(n)" class="etiqueta no_abrio">Desactivado</span>
                <span v-else class="etiqueta" :class="COLOR_SUSCRIPCION[n.suscripcion.estado]">{{ NOMBRE_SUSCRIPCION[n.suscripcion.estado] }}</span>
                <span v-if="n.suscripcion.vence_el" class="suave pequeno">vence el {{ fechaCorta(n.suscripcion.vence_el) }}</span>
              </span>
            </span>
            <IconChevronRight class="flecha" :size="20" aria-hidden="true" />
          </RouterLink>
        </li>
      </ul>
    </main>

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

      <h3 class="subtitulo">Primer mes de la suscripción</h3>
      <label class="casilla">
        <input v-model="primerMesGratis" type="checkbox" />
        <span>
          El primer mes es gratis
          <span class="suave pequeno nota-gratis">No paga nada ahora. Al mes le llega el aviso de pago como a todos.</span>
        </span>
      </label>
      <CampoPesos v-if="!primerMesGratis" v-model="valorPago" etiqueta="Valor pagado" />
      <span v-if="errores?.de('valor_pago')" class="error">{{ errores.de('valor_pago') }}</span>
      <label class="campo">
        {{ primerMesGratis ? 'Empieza el' : 'Fecha del pago' }}
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

    <AdminNavegacion />
  </div>
</template>

<style scoped>
.sub {
  font-size: 0.8rem;
  color: var(--texto-suave);
}

/* Búsqueda y "Nuevo" en el mismo renglón. Sin "gap" en flex (Android 7) */
.encabezado {
  display: flex;
  align-items: flex-end;
}

.buscar {
  flex: 1;
  min-width: 0;
  margin-right: 10px;
}

.buscar input {
  padding-left: 46px;
}

.nuevo {
  min-height: 52px;
  margin-left: auto;
  padding: 10px 18px;
}

/* Botones del filtro en un renglón que se desliza de lado */
.filtros {
  display: flex;
  overflow-x: auto;
  margin: -4px -16px 0;
  padding: 2px 16px 4px;
  -webkit-overflow-scrolling: touch;
  scrollbar-width: none;
}

.filtros::-webkit-scrollbar {
  display: none;
}

.filtro {
  flex: none;
  min-height: 38px;
  margin-right: 8px;
  padding: 0 14px;
  font: inherit;
  font-weight: 600;
  font-size: 0.88rem;
  color: var(--texto-suave);
  background: var(--superficie);
  border: 1px solid var(--borde-campo);
  border-radius: var(--radio-pildora);
  cursor: pointer;
  white-space: nowrap;
}

.filtro:last-child {
  margin-right: 0;
}

.filtro .cuenta {
  margin-left: 4px;
  color: var(--texto-tenue);
  font-variant-numeric: tabular-nums;
}

.filtro.activo {
  color: #ffffff;
  background: var(--texto);
  border-color: var(--texto);
}

.filtro.activo .cuenta {
  color: rgb(255 255 255 / 0.7);
}

/* Una sola tarjeta con renglones */
.lista {
  list-style: none;
  margin: 0;
  padding: 0 0 0 14px;
  background: var(--superficie);
  border: 1px solid var(--linea);
  border-radius: var(--radio-tarjeta);
  box-shadow: var(--sombra-1);
  overflow: hidden;
}

.lista li + li .fila {
  border-top: 1px solid var(--linea);
}

.fila {
  display: flex;
  align-items: center;
  padding: 14px 12px 14px 0;
  color: inherit;
  text-decoration: none;
}

.fila:active {
  background: var(--superficie-suave);
}

.avatar {
  width: 44px;
  height: 44px;
  flex: none;
  margin-right: 12px;
  display: grid;
  place-items: center;
  border-radius: 12px;
  font-size: 0.9rem;
  font-weight: 650;
  color: var(--verde-700);
  background: var(--verde-50);
}

.datos {
  flex: 1;
  min-width: 0;
  display: grid;
}

.datos > strong {
  font-weight: 600;
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
  margin-top: 6px;
}

.estado .etiqueta {
  margin-right: 8px;
}

.flecha {
  flex: none;
  margin-left: 8px;
  color: var(--texto-tenue);
}

.centro {
  text-align: center;
}

.vacio {
  justify-items: center;
  text-align: center;
  padding: 28px 20px;
}

.icono-grande {
  width: 60px;
  height: 60px;
  display: grid;
  place-items: center;
  border-radius: 18px;
  color: var(--verde-700);
  background: var(--verde-50);
}

.subtitulo {
  margin-top: 6px;
  color: var(--texto-suave);
}

.nota-gratis {
  display: block;
  font-weight: 400;
}
</style>
