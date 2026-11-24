<script setup lang="ts">
import { onBeforeUnmount, watch, ref } from 'vue'
import { IconCloudOff, IconReceipt } from '@tabler/icons-vue'
import { pesos } from '../calculos'
import { useComprobantesStore } from '../stores/comprobantes'
import { useDialogoStore } from '../stores/dialogo'

// Fotos de comprobantes que todavía no son pagos: esperando internet o esperando que el dueño las revise
const comprobantes = useComprobantesStore()
const dialogo = useDialogoStore()

// Miniaturas de las fotos guardadas en el celular
const miniaturas = ref(new Map<string, string>())
watch(
  () => comprobantes.fotos,
  (fotos) => {
    const nuevas = new Map<string, string>()
    for (const foto of fotos) nuevas.set(foto.id, miniaturas.value.get(foto.id) ?? URL.createObjectURL(foto.imagen))
    for (const [id, url] of miniaturas.value) if (!nuevas.has(id)) URL.revokeObjectURL(url)
    miniaturas.value = nuevas
  },
  { immediate: true },
)
onBeforeUnmount(() => miniaturas.value.forEach((url) => URL.revokeObjectURL(url)))

async function descartar(id: string) {
  const confirmo = await dialogo.confirmar({
    titulo: '¿Descartar este comprobante?',
    mensaje: 'La foto se borra y no se suma ningún pago.',
    aceptar: 'Descartar',
    cancelar: 'Cancelar',
    peligro: true,
  })
  if (confirmo) await comprobantes.descartar(id)
}
</script>

<template>
  <section v-if="comprobantes.fotos.length" class="tarjeta">
    <div class="titulo">
      <span class="icono" aria-hidden="true"><IconReceipt :size="22" /></span>
      <div>
        <h2>Comprobantes por revisar</h2>
        <p class="suave pequeno">No se suman hasta que usted los confirme.</p>
      </div>
    </div>

    <ul class="lista">
      <li v-for="foto in comprobantes.fotos" :key="foto.id">
        <img :src="miniaturas.get(foto.id)" alt="" class="mini" />
        <div class="datos">
          <template v-if="foto.estado === 'por_leer'">
            <strong class="esperando"><IconCloudOff :size="16" aria-hidden="true" /> Esperando internet</strong>
            <span class="suave pequeno">Se leerá cuando vuelva la conexión</span>
          </template>
          <template v-else>
            <strong class="monto">{{ foto.campos?.valor ? pesos(foto.campos.valor) : 'Sin valor leído' }}</strong>
            <span class="suave pequeno">{{ foto.repetido ? 'Ya estaba cargado' : 'Listo para revisar' }}</span>
          </template>
        </div>
        <div class="botones">
          <button v-if="foto.estado === 'por_confirmar'" class="boton pequeno-boton" @click="comprobantes.revisando = foto.id">Revisar</button>
          <button v-else class="boton secundario pequeno-boton" :disabled="comprobantes.leyendo" @click="comprobantes.reintentar(foto.id)">Leer</button>
          <button class="boton-texto pequeno" @click="descartar(foto.id)">Descartar</button>
        </div>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.titulo {
  display: flex;
  gap: 12px;
  align-items: center;
}

.icono {
  width: 44px;
  height: 44px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 14px;
  color: var(--aviso-texto);
  background: var(--aviso-fondo);
}

.lista {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 12px;
}

.lista li {
  display: flex;
  align-items: center;
  gap: 12px;
}

.mini {
  width: 52px;
  height: 64px;
  flex: none;
  object-fit: cover;
  border-radius: 10px;
  background: var(--superficie-suave);
  outline: 1px solid rgb(0 0 0 / 0.08);
  outline-offset: -1px;
}

.datos {
  flex: 1;
  min-width: 0;
  display: grid;
}

.esperando {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: var(--aviso-texto);
}

.botones {
  display: grid;
  justify-items: center;
}

.pequeno-boton {
  min-height: 44px;
  padding: 8px 16px;
  font-size: 0.95rem;
}
</style>
