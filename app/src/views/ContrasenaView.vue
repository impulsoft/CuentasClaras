<script setup lang="ts">
import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { IconAlertTriangle, IconEye, IconEyeOff, IconKey } from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import { ErrorApi } from '../api'
import { useSesionStore } from '../stores/sesion'
import { useDialogoStore } from '../stores/dialogo'

// RF-47 (cambiar la contraseña) y RF-46 (cambiar la temporal que dio el admin). Requiere internet.
const router = useRouter()
const sesion = useSesionStore()
const dialogo = useDialogoStore()

const temporal = computed(() => sesion.usuario?.debe_cambiar_contrasena === true)
const actual = ref('')
const nueva = ref('')
const repetida = ref('')
const ver = ref(false)
const error = ref<ErrorApi | null>(null)
const mensaje = ref('')
const enviando = ref(false)

const inicio = computed(() => (sesion.esAdmin ? 'admin' : 'inicio'))

async function guardar() {
  error.value = null
  mensaje.value = ''
  if (!temporal.value && !actual.value) return (mensaje.value = 'Escriba su contraseña actual.')
  if (nueva.value.length < 8) return (mensaje.value = 'La contraseña nueva debe tener al menos 8 letras o números.')
  if (nueva.value !== repetida.value) return (mensaje.value = 'Las dos contraseñas nuevas no son iguales. Escríbalas otra vez.')
  enviando.value = true
  try {
    await sesion.cambiarContrasena(temporal.value ? null : actual.value, nueva.value)
    dialogo.avisar('Contraseña cambiada')
    router.replace({ name: inicio.value })
  } catch (e) {
    if (e instanceof ErrorApi && e.sinConexion) mensaje.value = 'Sin conexión. Para cambiar la contraseña necesita internet.'
    else if (e instanceof ErrorApi) error.value = e
    else mensaje.value = 'No se pudo cambiar. Intente de nuevo.'
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div>
    <header v-if="temporal" class="cabecera">
      <span class="icono" aria-hidden="true"><IconKey :size="32" /></span>
      <h1>Cree su contraseña</h1>
      <p>Entró con una contraseña temporal. Escoja una nueva que solo usted conozca.</p>
    </header>
    <BarraVolver v-else titulo="Cambiar contraseña" :volver-a="{ name: inicio }" />

    <main class="pantalla">
      <form class="tarjeta formulario" novalidate @submit.prevent="guardar">
        <label v-if="!temporal" class="campo">
          Contraseña actual
          <span class="control"><input v-model="actual" :type="ver ? 'text' : 'password'" autocomplete="current-password" /></span>
          <span v-if="error?.de('actual')" class="error">{{ error.de('actual') }}</span>
        </label>
        <label class="campo">
          Contraseña nueva
          <span class="control"><input v-model="nueva" :type="ver ? 'text' : 'password'" autocomplete="new-password" placeholder="Mínimo 8 letras o números" /></span>
          <span v-if="error?.de('nueva')" class="error">{{ error.de('nueva') }}</span>
        </label>
        <label class="campo">
          Repita la contraseña nueva
          <span class="control"><input v-model="repetida" :type="ver ? 'text' : 'password'" autocomplete="new-password" /></span>
        </label>
        <button type="button" class="boton-texto ver" @click="ver = !ver">
          <component :is="ver ? IconEyeOff : IconEye" :size="20" aria-hidden="true" />
          {{ ver ? 'Ocultar contraseñas' : 'Ver lo que escribí' }}
        </button>

        <p v-if="mensaje || (error && !error.de('actual') && !error.de('nueva'))" class="mensaje error" role="alert">
          <IconAlertTriangle :size="20" aria-hidden="true" />{{ mensaje || error?.message }}
        </p>

        <button class="boton" :disabled="enviando">{{ enviando ? 'Guardando…' : 'Guardar contraseña' }}</button>
        <p class="suave pequeno">Si tiene la app abierta en otro celular, allí tendrá que entrar de nuevo con la contraseña nueva.</p>
        <button v-if="temporal" type="button" class="boton-texto" @click="sesion.cerrar()">Salir</button>
      </form>
    </main>
  </div>
</template>

<style scoped>
.cabecera {
  display: grid;
  justify-items: center;
  gap: 8px;
  padding: 36px 24px 56px;
  padding-top: max(36px, env(safe-area-inset-top));
  text-align: center;
  color: #ffffff;
  background: var(--verde-700);
}

.cabecera p {
  max-width: 34ch;
  color: var(--verde-50);
}

.cabecera + .pantalla {
  margin-top: -40px;
}

.icono {
  width: 64px;
  height: 64px;
  display: grid;
  place-items: center;
  border-radius: 20px;
  background: rgb(255 255 255 / 0.14);
}

.ver {
  justify-self: start;
  margin: -6px 0 0 -12px;
}
</style>
