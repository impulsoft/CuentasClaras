<script setup lang="ts">
import { ref } from 'vue'
import { IconCamera, IconDownload, IconFileSpreadsheet } from '@tabler/icons-vue'
import BarraSuperior from '../components/BarraSuperior.vue'
import EstadoSincronizacion from '../components/EstadoSincronizacion.vue'
import NavegacionInferior from '../components/NavegacionInferior.vue'
import Analisis from '../components/Analisis.vue'
import DescargarExcel from '../components/DescargarExcel.vue'
import { hoy } from '../calculos'
import { useConsulta } from '../datos'
import { db } from '../db'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useSalir } from '../composables/salir'

// RF-21 a RF-25 con los datos del celular (se ven aunque no haya internet), y Excel y cuaderno (RF-26 a RF-28)
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const salir = useSalir()

const datos = useConsulta(async () => ({ cierres: await db.cierres.toArray(), pagos: await db.pagos.toArray() }), null)
const descargando = ref(false)
</script>

<template>
  <div class="con-navegacion">
    <BarraSuperior :titulo="sesion.usuario?.negocio?.nombre ?? 'Mi negocio'" @salir="salir">
      <EstadoSincronizacion />
    </BarraSuperior>

    <main class="pantalla">
      <h2 class="titulo">Análisis</h2>
      <Analisis v-if="datos" :cierres="datos.cierres" :pagos="datos.pagos" :hoy="hoy()" />

      <section class="tarjeta">
        <h2>Sus datos</h2>
        <button class="boton secundario" @click="descargando = true"><IconDownload :size="22" aria-hidden="true" /> Descargar en Excel</button>
        <template v-if="!sync.soloConsulta">
          <h3>Pasar datos viejos</h3>
          <p class="suave pequeno">Cargue los días que tiene en el cuaderno o en un Excel. Antes de guardar, usted revisa lo que se leyó.</p>
          <div class="cargar">
            <RouterLink :to="{ name: 'cargar', params: { tipo: 'cuaderno' } }" class="opcion">
              <IconCamera :size="26" aria-hidden="true" />
              <span><strong>Foto del cuaderno</strong><span class="suave pequeno">Una página a la vez</span></span>
            </RouterLink>
            <RouterLink :to="{ name: 'cargar', params: { tipo: 'excel' } }" class="opcion">
              <IconFileSpreadsheet :size="26" aria-hidden="true" />
              <span><strong>Desde Excel</strong><span class="suave pequeno">Con la plantilla</span></span>
            </RouterLink>
          </div>
        </template>
      </section>
    </main>

    <DescargarExcel :abierta="descargando" ruta="/excel/cierres" :antes="() => sync.sincronizar()" @cerrar="descargando = false" />
    <NavegacionInferior />
  </div>
</template>

<style scoped>
.titulo {
  font-size: 1.45rem;
}

h3 {
  margin-top: 6px;
}

.cargar {
  display: grid;
  gap: 8px;
}

.opcion {
  display: flex;
  align-items: center;
  gap: 14px;
  min-height: 64px;
  padding: 12px 16px;
  color: var(--verde-700);
  text-decoration: none;
  border: 1.5px solid var(--borde-campo);
  border-radius: 14px;
  transition-property: border-color, background-color, transform;
  transition-duration: 150ms;
}

.opcion:hover {
  border-color: var(--verde-600);
  background: var(--verde-50);
}

.opcion:active {
  transform: scale(0.98);
}

.opcion > span {
  display: grid;
  color: var(--texto);
}
</style>
