<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'
import { IconAlertTriangle, IconCashRegister, IconCircleCheck, IconEye, IconEyeOff, IconLock, IconSettings, IconUser } from '@tabler/icons-vue'
import HojaInferior from '../components/HojaInferior.vue'
import { useSesionStore } from '../stores/sesion'
import { ErrorApi, pedir } from '../api'

const sesion = useSesionStore()
const router = useRouter()

const usuario = ref('')
const contrasena = ref('')
const verContrasena = ref(false)
const error = ref('')
const listo = ref('')

/*
 * El admin que olvidó la contraseña pone una nueva con su código de recuperación (lo crea en
 * Cuenta). Los dueños no tienen código: a ellos el admin les da una contraseña temporal.
 */
const recuperando = ref(false)
const recuperar = reactive({ usuario: '', codigo: '', nueva: '' })
const erroresRecuperar = ref<ErrorApi | null>(null)
const enviandoRecuperar = ref(false)

function abrirRecuperar() {
  Object.assign(recuperar, { usuario: usuario.value, codigo: '', nueva: '' })
  erroresRecuperar.value = null
  recuperando.value = true
}

async function enviarRecuperar() {
  erroresRecuperar.value = null
  enviandoRecuperar.value = true
  try {
    const respuesta = await pedir<{ mensaje: string }>('/recuperar', { metodo: 'POST', cuerpo: { ...recuperar } })
    usuario.value = recuperar.usuario
    contrasena.value = ''
    error.value = ''
    listo.value = respuesta.mensaje
    recuperando.value = false
  } catch (e) {
    erroresRecuperar.value =
      e instanceof ErrorApi
        ? e.sinConexion
          ? new ErrorApi('Para esto necesita internet.', 0)
          : e
        : new ErrorApi('Ocurrió un error. Intente de nuevo.', 500)
  } finally {
    enviandoRecuperar.value = false
  }
}
const enviando = ref(false)

// RF-01: el primer ingreso en cada dispositivo necesita internet
async function entrar() {
  error.value = ''
  enviando.value = true
  try {
    await sesion.iniciar(usuario.value, contrasena.value)
    router.replace('/')
  } catch (e) {
    if (e instanceof ErrorApi) {
      error.value = e.sinConexion
        ? 'Para entrar por primera vez en este celular necesita internet.'
        : (e.de('usuario') ?? e.message)
    } else {
      error.value = 'Ocurrió un error. Intente de nuevo.'
    }
  } finally {
    enviando.value = false
  }
}
</script>

<template>
  <div class="entrada">
    <header class="bienvenida">
      <!-- Discreto a propósito: solo lo usa el administrador para recuperar su contraseña con su código -->
      <button type="button" class="ajustes" aria-label="Opciones" @click="abrirRecuperar">
        <IconSettings :size="22" aria-hidden="true" />
      </button>
      <span class="logo" aria-hidden="true"><IconCashRegister :size="38" :stroke-width="1.8" /></span>
      <h1>Cierre de Caja</h1>
      <p>Su cuaderno de caja, ahora en el celular.</p>
    </header>

    <main class="pantalla">
      <form class="tarjeta formulario" @submit.prevent="entrar">
        <h2>Entrar</h2>

        <label class="campo">
          Usuario
          <span class="control">
            <IconUser class="icono" :size="22" aria-hidden="true" />
            <input v-model="usuario" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Ej.: tienda.jose" required />
          </span>
        </label>

        <label class="campo">
          Contraseña
          <span class="control">
            <IconLock class="icono" :size="22" aria-hidden="true" />
            <input
              v-model="contrasena"
              class="con-boton"
              :type="verContrasena ? 'text' : 'password'"
              autocomplete="current-password"
              placeholder="Su contraseña"
              required
            />
            <button
              type="button"
              class="ver"
              :aria-label="verContrasena ? 'Ocultar contraseña' : 'Mostrar contraseña'"
              @click="verContrasena = !verContrasena"
            >
              <IconEyeOff v-if="verContrasena" :size="22" />
              <IconEye v-else :size="22" />
            </button>
          </span>
        </label>

        <p v-if="listo && !error" class="mensaje exito" role="status"><IconCircleCheck :size="20" aria-hidden="true" />{{ listo }}</p>
        <p v-if="error" class="mensaje error" role="alert">
          <IconAlertTriangle :size="20" aria-hidden="true" />
          {{ error }}
        </p>

        <button class="boton" :disabled="enviando">{{ enviando ? 'Entrando…' : 'Entrar' }}</button>
      </form>

      <p class="ayuda suave">¿Olvidó su contraseña? Pídale al administrador que se la restablezca.</p>
    </main>

    <HojaInferior :abierta="recuperando" titulo="Recuperar la contraseña" @cerrar="recuperando = false" @enviar="enviarRecuperar">
      <p class="suave pequeno">Solo para administradores. Escriba el código que guardó (lo creó en Cuenta) y una contraseña nueva.</p>
      <label class="campo">
        Usuario
        <span class="control">
          <input v-model="recuperar.usuario" autocomplete="username" autocapitalize="none" spellcheck="false" />
        </span>
        <span v-if="erroresRecuperar?.de('usuario')" class="error">{{ erroresRecuperar.de('usuario') }}</span>
      </label>
      <label class="campo">
        Código de recuperación
        <span class="control">
          <input v-model="recuperar.codigo" autocomplete="off" autocapitalize="characters" spellcheck="false" placeholder="Ej.: K7M2-9QXP-4HTR" />
        </span>
        <span v-if="erroresRecuperar?.de('codigo')" class="error">{{ erroresRecuperar.de('codigo') }}</span>
      </label>
      <label class="campo">
        Contraseña nueva
        <span class="control">
          <input v-model="recuperar.nueva" type="password" autocomplete="new-password" placeholder="Mínimo 8 letras o números" />
        </span>
        <span v-if="erroresRecuperar?.de('nueva')" class="error">{{ erroresRecuperar.de('nueva') }}</span>
      </label>
      <p v-if="erroresRecuperar && Object.keys(erroresRecuperar.errores).length === 0" class="mensaje error" role="alert">
        <IconAlertTriangle :size="20" aria-hidden="true" />{{ erroresRecuperar.message }}
      </p>
      <template #acciones>
        <button type="button" class="boton secundario" @click="recuperando = false">Cancelar</button>
        <button class="boton" :disabled="enviandoRecuperar">{{ enviandoRecuperar ? 'Guardando…' : 'Guardar' }}</button>
      </template>
    </HojaInferior>
  </div>
</template>

<style scoped>
.entrada {
  min-height: 100vh;
}

/* Rueda de ajustes arriba a la derecha, poco llamativa */
.ajustes {
  position: absolute;
  top: 12px;
  top: max(12px, env(safe-area-inset-top));
  right: 12px;
  width: 44px;
  height: 44px;
  padding: 0;
  display: grid;
  place-items: center;
  color: rgb(255 255 255 / 0.45);
  background: none;
  border: none;
  border-radius: 50%;
  cursor: pointer;
}

.ajustes:active {
  background: rgb(255 255 255 / 0.12);
}

.bienvenida {
  position: relative;
  display: grid;
  justify-items: center;
  gap: 6px;
  padding: 48px 24px 72px;
  padding-top: max(48px, env(safe-area-inset-top));
  text-align: center;
  color: #ffffff;
  background: var(--verde-700);
}

.logo {
  width: 76px;
  height: 76px;
  display: grid;
  place-items: center;
  margin-bottom: 8px;
  border-radius: 24px;
  background: rgb(255 255 255 / 0.14);
  box-shadow: inset 0 0 0 1.5px rgb(255 255 255 / 0.2);
}

.bienvenida h1 {
  font-size: 1.8rem;
}

.bienvenida p {
  color: var(--verde-50);
}

/* La tarjeta se monta sobre el encabezado verde: "relative" la deja encima (el encabezado también lo es, por la rueda) */
.pantalla {
  position: relative;
  margin-top: -48px;
}

.ver {
  position: absolute;
  right: 4px;
  width: 46px;
  height: 46px;
  display: grid;
  place-items: center;
  color: var(--texto-suave);
  background: none;
  border: none;
  border-radius: 12px;
  cursor: pointer;
}

.ver:hover {
  background: var(--superficie-suave);
}

/* Espacio para el botón del ojo (con una clase y no con :has, que los WebViews viejos no tienen, RNF-03) */
.control input.con-boton {
  padding-right: 52px;
}

.ayuda {
  text-align: center;
  font-size: 0.9rem;
  padding: 0 16px;
}
</style>
