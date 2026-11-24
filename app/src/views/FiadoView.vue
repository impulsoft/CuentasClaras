<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { IconAlertTriangle, IconDeviceFloppy, IconUser } from '@tabler/icons-vue'
import BarraVolver from '../components/BarraVolver.vue'
import CampoPesos from '../components/CampoPesos.vue'
import AvisoPrivacidad from '../components/AvisoPrivacidad.vue'
import { fechaLarga, hoy, pesos, sumarDias } from '../calculos'
import { normalizarTelefono, telefonoLegible, type ResumenDeudor } from '../deudas'
import { agregarFiado, deudorConTelefono, fiarAPersonaNueva, resumenDe } from '../datosDeudores'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'

// RF-34 (fiado a una persona nueva) y RF-35 (otro fiado a alguien que ya debe)
const route = useRoute()
const router = useRouter()
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const dialogo = useDialogoStore()

const fechaHoy = hoy()
const nombre = ref('')
const telefono = ref('')
const fecha = ref(fechaHoy)
const descripcion = ref('')
const valor = ref<number | null>(null)
const fechaPago = ref('')
const acepta = ref(false)
const verAviso = ref(false)
const error = ref('')

// La persona a la que se le fía, si ya está registrada: por el enlace "Agregar otro fiado"
// o porque el dueño escribió un teléfono que ya existe (RN-32)
const existente = ref<ResumenDeudor | undefined>(undefined)
const porTelefono = ref(false)

const deudorDelEnlace = computed(() => (typeof route.query.deudor === 'string' ? route.query.deudor : null))
watch(
  deudorDelEnlace,
  async (id) => {
    existente.value = id ? await resumenDe(id) : undefined
    porTelefono.value = false
  },
  { immediate: true },
)

watch(telefono, async (escrito) => {
  if (deudorDelEnlace.value) return
  const numeros = normalizarTelefono(escrito)
  const deudor = numeros.length === 10 ? await deudorConTelefono(numeros) : undefined
  existente.value = deudor ? await resumenDe(deudor.id) : undefined
  porTelefono.value = !!deudor
})

function noEsEstaPersona() {
  telefono.value = ''
  existente.value = undefined
  porTelefono.value = false
}

// Botones rápidos para la fecha de pago: un toque en vez de abrir el calendario
const plazos = [
  { dias: 8, texto: 'En 8 días' },
  { dias: 15, texto: 'En 15 días' },
  { dias: 30, texto: 'En un mes' },
]

async function guardar() {
  error.value = ''
  const persona = existente.value
  const numeros = normalizarTelefono(telefono.value)

  if (!persona) {
    if (!nombre.value.trim()) return (error.value = 'Escriba el nombre del cliente.')
    if (numeros.length !== 10) return (error.value = 'Escriba los 10 números del celular del cliente.')
  }
  if (!fecha.value || fecha.value > fechaHoy) return (error.value = 'La fecha del fiado no puede ser un día que no ha llegado.')
  if (!descripcion.value.trim()) return (error.value = 'Escriba qué se llevó.')
  if (!valor.value) return (error.value = 'Escriba el valor fiado.')
  if (!fechaPago.value) return (error.value = 'Escoja cuándo va a pagar.')
  if (fechaPago.value < fecha.value) return (error.value = 'La fecha de pago no puede ser antes del día en que se fía.') // RN-24
  if (!persona && !acepta.value) return (error.value = 'Marque la casilla del aviso de privacidad. Sin ella no se puede guardar.') // RN-41

  const datos = { fecha: fecha.value, descripcion: descripcion.value, valor: valor.value, fecha_pago: fechaPago.value }
  const negocioId = sesion.usuario!.negocio!.id
  let deudorId: string
  if (persona) {
    deudorId = persona.deudor.id
    await agregarFiado(negocioId, deudorId, datos)
  } else {
    deudorId = await fiarAPersonaNueva(negocioId, { nombre: nombre.value, telefono: numeros }, datos)
  }
  sync.programar()
  dialogo.avisar('Fiado guardado')
  router.replace({ name: 'deudor', params: { id: deudorId } })
}
</script>

<template>
  <div>
    <BarraVolver titulo="Nuevo fiado" :volver-a="{ name: 'deudores' }" />

    <main class="pantalla">
      <form class="tarjeta formulario" novalidate @submit.prevent="guardar">
        <!-- Persona ya registrada -->
        <div v-if="existente" class="persona">
          <span class="avatar" aria-hidden="true"><IconUser :size="24" /></span>
          <div class="persona-datos">
            <strong>{{ existente.nombre }}</strong>
            <span class="suave pequeno">{{ telefonoLegible(existente.deudor.telefono) }}</span>
          </div>
        </div>
        <div v-if="existente && porTelefono" class="ya-registrado">
          <p class="suave pequeno">Este número ya está registrado. El fiado se le suma a esta persona.</p>
          <button type="button" class="boton-texto" @click="noEsEstaPersona">No es esta persona</button>
        </div>

        <!-- RF-36: aviso antes de fiar -->
        <p v-if="existente && existente.saldo > 0" class="mensaje" :class="existente.estado === 'vencido' ? 'error' : 'aviso'" role="status">
          <IconAlertTriangle :size="22" aria-hidden="true" />
          <span>
            {{ existente.nombre }} ya debe {{ pesos(existente.saldo) }}<template v-if="existente.estado === 'vencido'"> y tiene deudas vencidas</template>.
          </span>
        </p>

        <template v-if="!existente">
          <label class="campo">
            Nombre del cliente
            <span class="control"><input v-model="nombre" autocomplete="off" autocapitalize="words" maxlength="120" placeholder="Ej.: Ana Torres" /></span>
          </label>
          <label class="campo">
            Número de celular
            <span class="control"><input v-model="telefono" type="tel" inputmode="numeric" autocomplete="off" placeholder="Ej.: 320 111 2233" /></span>
          </label>
        </template>

        <label class="campo">
          Fecha en que pide el fiado
          <span class="control"><input v-model="fecha" type="date" :max="fechaHoy" /></span>
        </label>
        <label class="campo">
          ¿Qué se llevó?
          <textarea v-model="descripcion" maxlength="255" placeholder="Ej.: 2 bultos de concentrado para pollos" />
        </label>
        <CampoPesos v-model="valor" etiqueta="Valor fiado" />

        <div class="campo">
          <label for="fecha-pago">¿Cuándo va a pagar?</label>
          <div class="plazos">
            <button
              v-for="p in plazos"
              :key="p.dias"
              type="button"
              class="plazo"
              :class="{ activo: fechaPago === sumarDias(fecha, p.dias) }"
              @click="fechaPago = sumarDias(fecha, p.dias)"
            >
              {{ p.texto }}
            </button>
          </div>
          <span class="control"><input id="fecha-pago" v-model="fechaPago" type="date" :min="fecha" /></span>
          <span v-if="fechaPago" class="ayuda">Paga el {{ fechaLarga(fechaPago) }}</span>
        </div>

        <!-- RN-41: solo la primera vez que se le fía -->
        <label v-if="!existente" class="casilla privacidad">
          <input v-model="acepta" type="checkbox" />
          <span>
            El cliente acepta el
            <button type="button" class="enlace" @click.prevent="verAviso = true">aviso de privacidad</button>
          </span>
        </label>

        <p v-if="error" class="mensaje error" role="alert">
          <IconAlertTriangle :size="20" aria-hidden="true" />
          {{ error }}
        </p>

        <button class="boton">
          <IconDeviceFloppy :size="22" aria-hidden="true" />
          Guardar fiado
        </button>
      </form>
    </main>

    <AvisoPrivacidad :abierto="verAviso" @cerrar="verAviso = false" />
  </div>
</template>

<style scoped>
.persona {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 12px 14px;
  border-radius: var(--radio-control);
  background: var(--superficie-suave);
}

.avatar {
  width: 44px;
  height: 44px;
  flex: none;
  display: grid;
  place-items: center;
  border-radius: 50%;
  color: var(--verde-700);
  background: var(--verde-50);
}

.persona-datos {
  flex: 1;
  min-width: 0;
  display: grid;
}

.ya-registrado {
  display: grid;
  justify-items: start;
  margin-top: -8px;
}

.ya-registrado .boton-texto {
  margin-left: -12px;
}

.plazos {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.plazo {
  min-height: 44px;
  padding: 8px 4px;
  font: inherit;
  font-size: 0.9rem;
  font-weight: 700;
  color: var(--verde-800);
  background: var(--superficie);
  border: 1.5px solid var(--borde-campo);
  border-radius: var(--radio-pildora);
  cursor: pointer;
  transition-property: background-color, border-color, color, transform;
  transition-duration: 150ms;
}

.plazo:active {
  transform: scale(0.96);
}

.plazo.activo {
  color: #ffffff;
  background: var(--verde-700);
  border-color: var(--verde-700);
}

.privacidad {
  align-items: center;
  color: var(--texto);
}

.enlace {
  display: inline;
  /* El relleno agranda el área para tocar; el margen negativo evita que separe los renglones */
  padding: 10px 0;
  margin: -10px 0;
  font: inherit;
  font-weight: 700;
  color: var(--enlace);
  text-decoration: underline;
  text-underline-offset: 3px;
  background: none;
  border: none;
  cursor: pointer;
}
</style>
