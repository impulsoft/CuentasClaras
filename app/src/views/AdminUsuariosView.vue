<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { IconAlertTriangle, IconPlus, IconSearch } from '@tabler/icons-vue'
import AdminNavegacion from '../components/AdminNavegacion.vue'
import BarraSuperior from '../components/BarraSuperior.vue'
import CrearCuenta from '../components/CrearCuenta.vue'
import FilaCuenta from '../components/FilaCuenta.vue'
import GestionCuenta from '../components/GestionCuenta.vue'
import { useAdminApi, type CuentaUsuario, type UsuariosAdmin } from '../admin'
import { ErrorApi } from '../api'
import { useSesionStore } from '../stores/sesion'

/*
 * Todas las cuentas del sistema: los administradores y las cuentas de los negocios (el dueño y las
 * que se le agregaron). Al tocar una se puede corregir, darle una contraseña temporal o desactivarla.
 */
const api = useAdminApi()
const sesion = useSesionStore()
const usuarios = ref<UsuariosAdmin | null>(null)
const error = ref('')

async function cargar() {
  error.value = ''
  try {
    usuarios.value = await api.usuarios()
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: el panel del admin necesita internet.' : 'No se pudieron cargar los usuarios.'
  }
}
onMounted(cargar)

// Cuentas de los negocios: búsqueda por nombre, usuario o negocio
const busqueda = ref('')
const cuentas = computed(() => {
  const texto = busqueda.value.trim().toLowerCase()
  const lista = usuarios.value?.cuentas ?? []
  if (!texto) return lista
  return lista.filter((c) => [c.nombre, c.usuario, c.negocio?.nombre].some((v) => v?.toLowerCase().includes(texto)))
})
const activas = computed(() => (usuarios.value?.cuentas ?? []).filter((c) => c.activo).length)

const abierta = ref<CuentaUsuario | null>(null)
const creando = ref(false)

// Lo que cambió en la ventana de la cuenta se ve de una vez en la lista
function alCambiar(cuenta: CuentaUsuario) {
  const u = usuarios.value
  if (!u) return
  for (const lista of [u.administradores, u.cuentas]) {
    const i = lista.findIndex((c) => c.id === cuenta.id)
    if (i !== -1) lista[i] = cuenta
  }
  abierta.value = cuenta
}

async function alCrear() {
  creando.value = false
  await cargar()
}
</script>

<template>
  <div class="con-navegacion">
    <BarraSuperior titulo="Usuarios" admin @salir="sesion.cerrar()">
      <p class="sub">Administradores y cuentas de los negocios</p>
    </BarraSuperior>
    <main class="pantalla">
      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>
      <p v-else-if="!usuarios" class="suave">Cargando…</p>

      <template v-if="usuarios">
        <section class="tarjeta">
          <div class="encabezado">
            <h2>Administradores</h2>
            <button class="boton" @click="creando = true"><IconPlus :size="22" :stroke-width="2.5" aria-hidden="true" /> Nuevo</button>
          </div>
          <p class="suave pequeno">Entran al panel del admin. Si uno olvida su contraseña, otro le puede dar una temporal.</p>
          <ul class="lista">
            <FilaCuenta v-for="c in usuarios.administradores" :key="c.id" :cuenta="c" @abrir="abierta = $event" />
          </ul>
        </section>

        <section class="tarjeta">
          <h2>Cuentas de los negocios</h2>
          <p class="suave pequeno">
            {{ usuarios.cuentas.length }} {{ usuarios.cuentas.length === 1 ? 'cuenta' : 'cuentas' }} · {{ activas }}
            {{ activas === 1 ? 'activa' : 'activas' }}. Para agregarle una cuenta a un negocio, ábralo desde el panel.
          </p>
          <label v-if="usuarios.cuentas.length > 4" class="campo buscar">
            <span class="control">
              <IconSearch class="icono" :size="22" aria-hidden="true" />
              <input v-model="busqueda" type="search" placeholder="Buscar persona o negocio" aria-label="Buscar por nombre, usuario o negocio" />
            </span>
          </label>
          <p v-if="!usuarios.cuentas.length" class="suave">Todavía no hay negocios registrados.</p>
          <p v-else-if="!cuentas.length" class="suave">Ninguna cuenta coincide con la búsqueda.</p>
          <ul v-else class="lista">
            <FilaCuenta v-for="c in cuentas" :key="c.id" :cuenta="c" con-negocio @abrir="abierta = $event" />
          </ul>
        </section>
      </template>
    </main>

    <GestionCuenta :cuenta="abierta" con-enlace-negocio @cerrar="abierta = null" @cambiada="alCambiar" />
    <CrearCuenta :abierta="creando" @cerrar="creando = false" @creada="alCrear" />
    <AdminNavegacion />
  </div>
</template>

<style scoped>
.sub {
  font-size: 0.8rem;
  color: var(--texto-suave);
}

.encabezado {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.encabezado h2 {
  margin-right: 12px;
}

.encabezado .boton {
  min-height: 48px;
  padding: 10px 16px;
}

.lista {
  list-style: none;
  margin: 0;
  padding: 0;
}

.buscar input {
  padding-left: 46px;
}
</style>
