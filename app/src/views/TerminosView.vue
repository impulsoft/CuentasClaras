<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { IconAlertTriangle, IconCheck, IconEye, IconFileText, IconShieldCheck, IconUsers, IconWallet } from '@tabler/icons-vue'
import { useSesionStore } from '../stores/sesion'
import { ErrorApi } from '../api'

const sesion = useSesionStore()
const router = useRouter()

const acepto = ref(false)
const error = ref('')
const enviando = ref(false)

// Texto provisional: el definitivo lo redacta y revisa un abogado (sección 7.1 del documento)
const puntos = [
  {
    icono: IconWallet,
    titulo: 'Pago mensual',
    texto: 'El servicio se paga cada mes. Si no se paga, el negocio queda bloqueado: podrá ver y descargar su información, pero no registrar.',
  },
  {
    icono: IconEye,
    titulo: 'El administrador ve sus números',
    texto: 'Puede ver sus cierres y reportes para acompañarlo, pero no puede cambiarlos.',
  },
  {
    icono: IconShieldCheck,
    titulo: 'Sus datos están protegidos',
    texto: 'Se tratan según la Ley 1581 de 2012 de protección de datos personales.',
  },
  {
    icono: IconUsers,
    titulo: 'Los datos de sus clientes',
    texto: 'Usted debe pedirles permiso a sus deudores para guardar su nombre y teléfono. La plataforma los guarda con seguridad y no los usa para nada más.',
  },
]

// RF-03: sin aceptarlos no puede usar el sistema (RN-15)
async function aceptar() {
  error.value = ''
  enviando.value = true
  try {
    await sesion.aceptarTerminos()
    router.replace('/')
  } catch (e) {
    error.value =
      e instanceof ErrorApi && e.sinConexion
        ? 'Necesita internet para aceptar los términos.'
        : e instanceof ErrorApi
          ? e.message
          : 'Ocurrió un error. Intente de nuevo.'
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div>
    <header class="cabecera">
      <span class="logo" aria-hidden="true"><IconFileText :size="30" /></span>
      <h1>Antes de empezar</h1>
      <p>Hola, {{ sesion.usuario?.nombre }}. Lea estos puntos sobre el servicio.</p>
    </header>

    <main class="pantalla">
      <section class="tarjeta">
        <h2>Términos y condiciones</h2>
        <ul class="puntos">
          <li v-for="punto in puntos" :key="punto.titulo">
            <span class="icono" aria-hidden="true"><component :is="punto.icono" :size="22" /></span>
            <div>
              <strong>{{ punto.titulo }}</strong>
              <p class="suave">{{ punto.texto }}</p>
            </div>
          </li>
        </ul>
      </section>

      <form class="tarjeta formulario" @submit.prevent="aceptar">
        <label class="acepto" :class="{ marcado: acepto }">
          <input v-model="acepto" type="checkbox" class="oculto" />
          <span class="caja" aria-hidden="true"><IconCheck v-if="acepto" :size="20" :stroke-width="3" /></span>
          <span>Leí y acepto los términos y condiciones y la política de tratamiento de datos.</span>
        </label>

        <p v-if="error" class="mensaje error" role="alert">
          <IconAlertTriangle :size="20" aria-hidden="true" />
          {{ error }}
        </p>

        <button class="boton" :disabled="!acepto || enviando">{{ enviando ? 'Guardando…' : 'Aceptar y continuar' }}</button>
        <p v-if="!acepto" class="pista suave pequeno">Marque la casilla para continuar.</p>
        <button type="button" class="boton-texto" @click="sesion.cerrar()">Salir</button>
      </form>
    </main>
  </div>
</template>

<style scoped>
.cabecera {
  display: grid;
  justify-items: center;
  gap: 6px;
  padding: 36px 24px 64px;
  padding-top: max(36px, env(safe-area-inset-top));
  text-align: center;
  color: #ffffff;
  background: var(--verde-700);
}

.cabecera p {
  color: var(--verde-50);
}

.logo {
  width: 60px;
  height: 60px;
  display: grid;
  place-items: center;
  margin-bottom: 6px;
  border-radius: 20px;
  background: rgb(255 255 255 / 0.14);
}

.pantalla {
  margin-top: -44px;
}

.puntos {
  list-style: none;
  margin: 0;
  padding: 0;
  display: grid;
  gap: 18px;
}

.puntos li {
  display: flex;
  gap: 14px;
}

.puntos p {
  margin-top: 2px;
  font-size: 0.95rem;
}

.icono {
  width: 42px;
  height: 42px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 14px;
  color: var(--verde-700);
  background: var(--verde-50);
}

/* Casilla grande: se puede tocar en cualquier parte del recuadro */
.acepto {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  padding: 16px;
  font-weight: 700;
  border: 2px solid var(--borde-campo);
  border-radius: 16px;
  cursor: pointer;
  transition-property: border-color, background-color;
  transition-duration: 150ms;
}

.acepto.marcado {
  border-color: var(--verde-600);
  background: var(--verde-50);
}

.acepto:has(.oculto:focus-visible) {
  outline: 3px solid rgb(29 158 117 / 0.4);
  outline-offset: 2px;
}

.oculto {
  position: absolute;
  opacity: 0;
  width: 1px;
  height: 1px;
}

.caja {
  width: 30px;
  height: 30px;
  flex: none;
  display: grid;
  place-items: center;
  color: #ffffff;
  background: var(--superficie);
  border: 2px solid var(--borde-campo);
  border-radius: 9px;
  transition-property: background-color, border-color;
  transition-duration: 150ms;
}

.marcado .caja {
  background: var(--verde-600);
  border-color: var(--verde-600);
}

.pista {
  margin-top: -6px;
  text-align: center;
}
</style>
