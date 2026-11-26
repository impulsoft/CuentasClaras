<script setup lang="ts">
import { computed, ref } from 'vue'
import { IconAlertTriangle, IconChevronRight, IconPlus, IconSearch, IconUsers } from '@tabler/icons-vue'
import BarraSuperior from '../components/BarraSuperior.vue'
import EstadoSincronizacion from '../components/EstadoSincronizacion.vue'
import NavegacionInferior from '../components/NavegacionInferior.vue'
import AvisoSuscripcion from '../components/AvisoSuscripcion.vue'
import { fechaCorta, hoy as fechaHoy, pesos } from '../calculos'
import { coincideBusqueda, NOMBRE_ESTADO, pagaHoy, textoPlazo, type ResumenDeudor } from '../deudas'
import { useDeudoresStore } from '../stores/deudores'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useSalir } from '../composables/salir'

// RF-31 a RF-33 y RF-41: quién debe, cuánto y desde cuándo; los vencidos primero
const deudores = useDeudoresStore()
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const salir = useSalir()

// Solo la búsqueda por nombre o teléfono: la lista ya trae primero a los vencidos (RF-41)
const busqueda = ref('')
const visibles = computed(() => deudores.lista.filter((d) => coincideBusqueda(d, busqueda.value)))

const t = computed(() => deudores.totales)
const resumenTotales = computed(() => {
  const personas = t.value.personas === 1 ? '1 persona le debe' : `${t.value.personas} personas le deben`
  if (!t.value.vencidas) return personas
  return `${personas} · ${t.value.vencidas} ${t.value.vencidas === 1 ? 'vencida' : 'vencidas'}`
})

// "Fió el 28 oct · venció hace 12 días", "Fió el 18 nov · paga hoy"
const hoy = fechaHoy()
function detalle(d: ResumenDeudor): string {
  const fio = d.fioEl ? `Fió el ${fechaCorta(d.fioEl)}` : ''
  if (d.estado === 'pagado') return d.pagoEl ? `${fio} · pagó el ${fechaCorta(d.pagoEl)}` : fio
  if (!d.pagaEl) return fio
  return `${fio} · ${textoPlazo(d.pagaEl, d.estado === 'vencido', hoy)}`
}
</script>

<template>
  <div class="con-navegacion">
    <BarraSuperior :titulo="sesion.usuario?.negocio?.nombre ?? 'Mi negocio'" @salir="salir">
      <EstadoSincronizacion />
    </BarraSuperior>

    <main class="pantalla">
      <p v-if="sync.soloConsulta" class="mensaje aviso" role="alert">
        <IconAlertTriangle :size="22" aria-hidden="true" />
        Por ahora solo puede consultar. No puede registrar fiados ni abonos.
      </p>

      <AvisoSuscripcion />

      <div class="encabezado">
        <h2>Deudores</h2>
        <RouterLink v-if="!sync.soloConsulta" :to="{ name: 'nuevo-fiado' }" class="boton">
          <IconPlus :size="22" :stroke-width="2.5" aria-hidden="true" />
          Nuevo fiado
        </RouterLink>
      </div>

      <!-- RF-32 -->
      <section class="tarjeta total">
        <span class="suave">Total por cobrar</span>
        <strong class="monto">{{ pesos(t.total) }}</strong>
        <span class="suave">{{ resumenTotales }}</span>
      </section>

      <template v-if="deudores.lista.length">
        <!-- RF-33 -->
        <label class="campo buscar">
          <span class="oculto">Buscar</span>
          <span class="control">
            <IconSearch class="icono" :size="22" aria-hidden="true" />
            <input v-model="busqueda" type="search" placeholder="Nombre o teléfono" autocomplete="off" />
          </span>
        </label>
        <ul class="lista">
          <li v-for="d in visibles" :key="d.deudor.id">
            <RouterLink :to="{ name: 'deudor', params: { id: d.deudor.id } }" class="deudor" :class="d.estado">
              <span class="izquierda">
                <strong class="nombre">{{ d.nombre }}</strong>
                <span class="suave pequeno">{{ detalle(d) }}</span>
                <span v-if="d.estado === 'abono'" class="suave pequeno">Debía {{ pesos(d.debia) }}</span>
              </span>
              <span class="derecha">
                <strong class="monto">{{ pesos(d.saldo) }}</strong>
                <span v-if="pagaHoy(d, hoy)" class="etiqueta aviso">Paga hoy</span>
                <span v-else class="etiqueta" :class="d.estado">{{ NOMBRE_ESTADO[d.estado] }}</span>
              </span>
              <IconChevronRight class="flecha" :size="20" aria-hidden="true" />
            </RouterLink>
          </li>
        </ul>
        <p v-if="!visibles.length" class="vacio suave">No hay deudores con ese nombre o teléfono.</p>
      </template>

      <section v-else-if="!deudores.cargando" class="tarjeta vacio-inicial">
        <span class="icono-grande" aria-hidden="true"><IconUsers :size="32" /></span>
        <h3>Todavía no le ha fiado a nadie</h3>
        <p class="suave">Cuando le fíe a un cliente, toque «Nuevo fiado» y aquí verá cuánto le deben y cuándo le van a pagar.</p>
      </section>
    </main>

    <NavegacionInferior />
  </div>
</template>

<style scoped>
.encabezado {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}

.encabezado h2 {
  font-size: 1.45rem;
}

.encabezado .boton {
  min-height: 50px;
  padding: 10px 16px;
  text-decoration: none;
}

.total {
  gap: 2px;
  background: var(--verde-50);
  box-shadow: none;
}

.total strong {
  font-size: 2rem;
  line-height: 1.15;
  color: var(--verde-800);
}

.buscar input {
  padding-left: 46px;
}

.oculto {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip-path: inset(50%);
  white-space: nowrap;
}

.lista {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 10px;
}

.deudor {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 14px 10px 14px 16px;
  color: inherit;
  text-decoration: none;
  background: var(--superficie);
  border-radius: 16px;
  box-shadow: var(--sombra-1);
  border-left: 5px solid transparent;
  transition-property: transform, box-shadow;
  transition-duration: 150ms;
}

.deudor:active {
  transform: scale(0.98);
}

.deudor.vencido {
  border-left-color: var(--error);
}

.izquierda {
  flex: 1;
  min-width: 0;
  display: grid;
  gap: 2px;
}

.nombre {
  font-size: 1.05rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.derecha {
  display: grid;
  justify-items: end;
  gap: 4px;
}

.derecha .monto {
  font-size: 1.1rem;
}

.flecha {
  flex: none;
  color: var(--texto-tenue);
}

.vacio {
  text-align: center;
  padding: 12px;
}

.vacio-inicial {
  justify-items: center;
  text-align: center;
  padding: 28px 20px;
}

.icono-grande {
  width: 64px;
  height: 64px;
  display: grid;
  place-items: center;
  border-radius: 20px;
  color: var(--verde-700);
  background: var(--verde-50);
}
</style>
