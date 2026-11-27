<script setup lang="ts">
import { IconUserDollar } from '@tabler/icons-vue'
import { pesos } from '../calculos'
import { abonosDelDia } from '../datosDeudores'
import { useConsulta } from '../datos'

// RF-43: quién abonó ese día y cuánto. No se suma al total: ya está en el efectivo o en el Nequi (RN-18)
const props = defineProps<{ fecha: string }>()
const abonos = useConsulta(() => abonosDelDia(props.fecha), [], [() => props.fecha])
</script>

<template>
  <section v-if="abonos.length" class="recuadro abonos">
    <div class="titulo">
      <span class="icono" aria-hidden="true"><IconUserDollar :size="22" /></span>
      <div class="nombre">
        <strong>Abonos de deudores</strong>
        <span class="suave pequeno">Ya están en el efectivo o en el Nequi; no se suman otra vez</span>
      </div>
    </div>
    <ul class="lista">
      <li v-for="{ abono, nombre } in abonos" :key="abono.id">
        <RouterLink :to="{ name: 'deudor', params: { id: abono.deudor_id } }" class="quien">Abonó {{ nombre }}</RouterLink>
        <span class="monto">{{ pesos(abono.valor) }}</span>
      </li>
    </ul>
  </section>
</template>

<style scoped>
.abonos {
  display: grid;
  gap: 10px;
}

.titulo {
  display: flex;
  align-items: center;
  gap: 12px;
}

.icono {
  width: 40px;
  height: 40px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 12px;
  color: var(--info-texto);
  background: var(--info-fondo);
}

.nombre {
  display: grid;
  line-height: 1.25;
}

.lista {
  list-style: none;
  margin: 0;
  padding: 0;
}

.lista li {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  min-height: 44px;
  border-top: 1px solid rgb(0 0 0 / 0.06);
}

.quien {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: var(--texto);
  text-decoration: none;
}

.monto {
  font-weight: 700;
}
</style>
