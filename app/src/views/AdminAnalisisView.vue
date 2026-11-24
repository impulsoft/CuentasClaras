<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { IconAlertTriangle, IconDownload, IconEye } from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import Analisis from '../components/Analisis.vue'
import DescargarExcel from '../components/DescargarExcel.vue'
import { hoy } from '../calculos'
import { useAdminApi, type MesNegocio } from '../admin'
import { ErrorApi } from '../api'
import type { CierreAnalisis, PagoAnalisis } from '../analisis'

// RF-07 / RF-22 a RF-26: el admin ve los análisis de un negocio en solo lectura y puede bajar el Excel
const props = defineProps<{ id: string }>()
const api = useAdminApi()

const nombre = ref('')
const datos = ref<{ cierres: CierreAnalisis[]; pagos: PagoAnalisis[] } | null>(null)
const error = ref('')
const descargando = ref(false)

async function cargar() {
  error.value = ''
  try {
    const [todo, negocio]: [MesNegocio, { nombre: string }] = await Promise.all([api.rango(props.id, '2000-01-01', hoy()), api.negocio(props.id)])
    nombre.value = negocio.nombre
    datos.value = { cierres: todo.cierres, pagos: todo.pagos.map((p) => ({ ...p, estado: 'confirmado' as const })) }
  } catch (e) {
    error.value = e instanceof ErrorApi && e.sinConexion ? 'Sin conexión: los análisis del admin necesitan internet.' : 'No se pudieron cargar los análisis.'
  }
}
onMounted(cargar)
</script>

<template>
  <div>
    <BarraVolver :titulo="nombre || 'Análisis'" :volver-a="{ name: 'admin-negocio', params: { id } }" />
    <main class="pantalla">
      <p class="mensaje exito"><IconEye :size="20" aria-hidden="true" /> Solo consulta.</p>
      <p v-if="error" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ error }}
        <button class="boton-texto" @click="cargar">Reintentar</button>
      </p>
      <p v-else-if="!datos" class="suave">Cargando…</p>
      <Analisis v-if="datos" :cierres="datos.cierres" :pagos="datos.pagos" :hoy="hoy()" />
      <button v-if="datos" class="boton secundario" @click="descargando = true"><IconDownload :size="22" aria-hidden="true" /> Descargar en Excel</button>
    </main>
    <DescargarExcel :abierta="descargando" :ruta="`/admin/negocios/${id}/excel`" @cerrar="descargando = false" />
  </div>
</template>
