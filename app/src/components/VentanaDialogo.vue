<script setup lang="ts">
import { nextTick, ref, watch } from 'vue'
import { IconAlertTriangle, IconCircleCheck } from '@tabler/icons-vue'
import { useDialogoStore } from '../stores/dialogo'

const dialogo = useDialogoStore()
const texto = ref('')
const campo = ref<HTMLInputElement | null>(null)
const botonAceptar = ref<HTMLButtonElement | null>(null)

watch(
  () => dialogo.abierto,
  async (peticion) => {
    if (!peticion) return
    texto.value = peticion.pedirTexto?.inicial ?? ''
    await nextTick()
    if (peticion.pedirTexto) campo.value?.select()
    else botonAceptar.value?.focus()
  },
)

function aceptar() {
  dialogo.cerrar(dialogo.abierto?.pedirTexto ? texto.value : true)
}
</script>

<template>
  <Transition name="dialogo">
    <div v-if="dialogo.abierto" class="fondo" @click.self="dialogo.cerrar(null)" @keydown.esc="dialogo.cerrar(null)">
      <form class="ventana" role="dialog" aria-modal="true" :aria-labelledby="'titulo-dialogo'" @submit.prevent="aceptar">
        <span class="icono" :class="{ peligro: dialogo.abierto.peligro }" aria-hidden="true">
          <IconAlertTriangle v-if="dialogo.abierto.peligro" :size="28" />
          <IconCircleCheck v-else :size="28" />
        </span>
        <h2 id="titulo-dialogo">{{ dialogo.abierto.titulo }}</h2>
        <p v-if="dialogo.abierto.mensaje" class="suave">{{ dialogo.abierto.mensaje }}</p>

        <label v-if="dialogo.abierto.pedirTexto" class="campo">
          {{ dialogo.abierto.pedirTexto.etiqueta }}
          <input ref="campo" v-model="texto" autocomplete="off" />
        </label>

        <div class="acciones">
          <button type="button" class="boton secundario" @click="dialogo.cerrar(null)">{{ dialogo.abierto.cancelar }}</button>
          <button ref="botonAceptar" class="boton" :class="{ peligro: dialogo.abierto.peligro }">{{ dialogo.abierto.aceptar }}</button>
        </div>
      </form>
    </div>
  </Transition>

  <!-- Avisos cortos ("Cierre guardado") -->
  <Transition name="aviso">
    <div v-if="dialogo.aviso" :key="dialogo.aviso.id" class="aviso" role="status">
      <IconCircleCheck :size="22" aria-hidden="true" />
      {{ dialogo.aviso.texto }}
    </div>
  </Transition>
</template>

<style scoped>
.fondo {
  position: fixed;
  inset: 0;
  z-index: 50;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  padding: 16px;
  background: rgb(4 30 24 / 0.45);
}

@media (min-width: 600px) {
  .fondo {
    align-items: center;
  }
}

.ventana {
  width: 100%;
  max-width: 440px;
  display: grid;
  gap: 12px;
  padding: 24px 20px 20px;
  background: var(--superficie);
  border-radius: 24px;
  box-shadow: var(--sombra-3);
}

.icono {
  width: 52px;
  height: 52px;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: var(--verde-50);
  color: var(--verde-700);
}

.icono.peligro {
  background: var(--aviso-fondo);
  color: var(--aviso-texto);
}

.acciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  margin-top: 6px;
}

.boton.peligro {
  background: var(--error);
}

.dialogo-enter-active,
.dialogo-leave-active {
  transition-property: opacity;
  transition-duration: 200ms;
}

.dialogo-enter-active .ventana,
.dialogo-leave-active .ventana {
  transition-property: transform, opacity;
  transition-duration: 220ms;
  transition-timing-function: cubic-bezier(0.2, 0.9, 0.3, 1);
}

.dialogo-enter-from,
.dialogo-leave-to {
  opacity: 0;
}

.dialogo-enter-from .ventana {
  transform: translateY(24px);
}

.dialogo-leave-to .ventana {
  transform: translateY(8px);
  opacity: 0;
}

/* Centrado con márgenes automáticos (y no con "translate", que los WebViews viejos no tienen, RNF-03) */
.aviso {
  position: fixed;
  left: 16px;
  right: 16px;
  bottom: 24px;
  z-index: 60;
  display: flex;
  align-items: center;
  width: max-content;
  max-width: calc(100% - 32px);
  margin: 0 auto;
  padding: 14px 20px;
  border-radius: 20px;
  background: var(--verde-900);
  color: #ffffff;
  font-weight: 700;
  line-height: 1.3;
  box-shadow: var(--sombra-3);
  text-wrap: balance;
}

.aviso svg {
  flex: none;
  margin-right: 10px;
}

.aviso-enter-active,
.aviso-leave-active {
  transition-property: transform, opacity;
  transition-duration: 220ms;
}

.aviso-enter-from {
  opacity: 0;
  transform: translateY(16px);
}

.aviso-leave-to {
  opacity: 0;
  transform: translateY(6px);
}
</style>
