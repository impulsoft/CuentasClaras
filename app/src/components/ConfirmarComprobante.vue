<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { IconAlertTriangle, IconBan, IconCheck, IconRefresh, IconX } from '@tabler/icons-vue'
import CampoPesos from './CampoPesos.vue'
import { fechaLarga, hoy, pesos } from '../calculos'
import { confirmarComprobante, pagoManualParecido, referenciaCargada } from '../datos'
import { useComprobantesStore } from '../stores/comprobantes'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useDialogoStore } from '../stores/dialogo'
import { useDeudoresStore } from '../stores/deudores'
import { deudorSugerido, repartirPago } from '../deudas'
import type { Pago } from '../db'

// RF-18: el dueño revisa lo que leyó el OCR, lo corrige si hace falta y lo confirma
const comprobantes = useComprobantesStore()
const sesion = useSesionStore()
const sync = useSincronizacionStore()
const dialogo = useDialogoStore()
const deudores = useDeudoresStore()

const foto = computed(() => comprobantes.fotos.find((f) => f.id === comprobantes.revisando))

const valor = ref<number | null>(null)
const fecha = ref('')
const hora = ref('')
const nombre = ref('')
const referencia = ref('')
const error = ref('')
const imagenUrl = ref<string | null>(null)
const parecido = ref<Pago | undefined>(undefined)
const repetido = ref(false)

// RN-39: "Venta" (por defecto) o "Abono de fiado" de un deudor que debe
const tipo = ref<'venta' | 'abono'>('venta')
const deudorId = ref('')
const escogioDeudor = ref(false)
const sugerido = computed(() => deudorSugerido(nombre.value, deudores.conSaldo))
const elegido = computed(() => deudores.conSaldo.find((d) => d.deudor.id === deudorId.value))
const reparto = computed(() => repartirPago(valor.value ?? 0, elegido.value?.saldo ?? 0))
const primerNombre = computed(() => elegido.value?.nombre.split(' ')[0] ?? '')

// Mientras el dueño no escoja a mano, la persona escogida sigue al nombre leído
watch(sugerido, (s) => {
  if (!escogioDeudor.value) deudorId.value = s?.deudor.id ?? ''
}, { immediate: true })

// La ventana se abre apenas se toma la foto; mientras el servidor la lee, los campos esperan
const leyendoEsta = computed(() => comprobantes.leyendo && foto.value?.estado === 'por_leer')

watch(
  () => foto.value?.id,
  () => {
    if (imagenUrl.value) URL.revokeObjectURL(imagenUrl.value)
    imagenUrl.value = foto.value ? URL.createObjectURL(foto.value.imagen) : null
    llenarCampos()
  },
  { immediate: true },
)

// Cuando termina de leer, se llenan los campos con lo leído
watch(
  () => foto.value?.estado,
  (nuevo, anterior) => {
    if (anterior === 'por_leer' && nuevo === 'por_confirmar') llenarCampos()
  },
)

function llenarCampos() {
  const campos = foto.value?.campos
  valor.value = campos?.valor ?? null
  fecha.value = campos?.fecha && campos.fecha <= hoy() ? campos.fecha : hoy()
  hora.value = campos?.hora ?? ''
  nombre.value = campos?.nombre ?? ''
  referencia.value = campos?.referencia ?? ''
  repetido.value = foto.value?.repetido ?? false
  tipo.value = 'venta'
  escogioDeudor.value = false
  deudorId.value = sugerido.value?.deudor.id ?? ''
  error.value = ''
}

onBeforeUnmount(() => imagenUrl.value && URL.revokeObjectURL(imagenUrl.value))

// RN-40: avisa si ya hay un pago escrito a mano con el mismo valor y la misma hora
watch([fecha, valor, hora], async () => {
  parecido.value = foto.value ? await pagoManualParecido(fecha.value, valor.value, hora.value || null) : undefined
})

// RN-05: si el dueño corrige la referencia, se vuelve a revisar
watch(referencia, async (nueva, anterior) => {
  if (anterior !== undefined && nueva !== (foto.value?.campos?.referencia ?? '')) {
    repetido.value = await referenciaCargada(nueva.trim() || null)
  }
})

const sinLeer = computed(() => foto.value && !leyendoEsta.value && (!foto.value.leido || foto.value.estado === 'por_leer'))

// Se leyó la imagen pero no tiene nada de un pago (por ejemplo, un pantallazo de otra cosa)
const sinDatos = computed(() => {
  const f = foto.value
  if (!f || !f.leido || f.estado !== 'por_confirmar' || !f.campos) return false
  return Object.values(f.campos).every((v) => v === null || v === '')
})

function cerrar() {
  comprobantes.revisando = null
}

async function descartar() {
  if (!foto.value) return
  await comprobantes.descartar(foto.value.id)
  dialogo.avisar('Comprobante descartado')
}

async function confirmar() {
  error.value = ''
  if (!foto.value || leyendoEsta.value) return
  if (!valor.value) {
    error.value = 'Escriba el valor del pago.'
    return
  }
  if (!fecha.value || fecha.value > hoy()) {
    error.value = 'Revise la fecha: no puede ser un día que no ha llegado.'
    return
  }
  const esAbono = tipo.value === 'abono'
  if (esAbono && !elegido.value) {
    error.value = 'Escoja quién está abonando.'
    return
  }
  const abono = esAbono ? { deudorId: deudorId.value, valor: reparto.value.abono } : undefined
  const quien = primerNombre.value
  const fechaPago = fecha.value
  const cambioCierre = await confirmarComprobante(sesion.usuario!.negocio!.id, foto.value, {
    valor: valor.value,
    fecha: fechaPago,
    hora: hora.value || null,
    nombre: nombre.value.trim() || null,
    referencia: referencia.value.trim() || null,
    abono,
  })
  comprobantes.revisando = null
  sync.programar()
  dialogo.avisar(
    abono
      ? `Pago guardado y abonado a la deuda de ${quien}`
      : cambioCierre
      ? `Pago guardado; cambió el total del ${fechaLarga(fechaPago)}`
      : fechaPago === hoy()
        ? 'Pago guardado'
        : `Pago guardado en el ${fechaLarga(fechaPago)}`,
  )
}
</script>

<template>
  <Transition name="hoja">
    <div v-if="foto" class="fondo" @click.self="cerrar">
      <form class="hoja" role="dialog" aria-modal="true" aria-labelledby="titulo-comprobante" @submit.prevent="confirmar">
        <header class="cabecera">
          <h2 id="titulo-comprobante">Pago Nequi {{ sinLeer || leyendoEsta ? '' : 'leído' }}</h2>
          <button type="button" class="cerrar" aria-label="Cerrar" @click="cerrar"><IconX :size="24" /></button>
        </header>

        <div class="cuerpo">
          <img v-if="imagenUrl" :src="imagenUrl" class="miniatura" alt="Foto del comprobante" />

          <p v-if="leyendoEsta" class="mensaje leyendo" role="status">
            <IconRefresh class="girando" :size="20" aria-hidden="true" />
            Leyendo los datos de la foto…
          </p>

          <!-- RN-05 -->
          <p v-if="repetido" class="mensaje error" role="alert">
            <IconBan :size="20" aria-hidden="true" />
            Este comprobante ya está cargado. No se puede cargar dos veces.
          </p>
          <p v-else-if="sinLeer" class="mensaje aviso">
            <IconAlertTriangle :size="20" aria-hidden="true" />
            No se pudo leer la foto. Escriba los datos mirando el comprobante.
          </p>
          <p v-else-if="sinDatos" class="mensaje aviso">
            <IconAlertTriangle :size="20" aria-hidden="true" />
            En esta imagen no se ven los datos de un pago. Revise que sea el comprobante de Nequi; si lo es, escriba los datos.
          </p>

          <!-- Aviso contra comprobantes falsos (solo para fotos del celular del cliente) -->
          <p v-if="foto.origen === 'foto' && !repetido" class="mensaje aviso">
            <IconAlertTriangle :size="20" aria-hidden="true" />
            Revise en su Nequi que la plata le llegó.
          </p>

          <!-- Mientras lee, los campos esperan (se llenan solos al terminar) -->
          <fieldset class="campos" :disabled="leyendoEsta">
          <div class="lista">
          <CampoPesos v-model="valor" etiqueta="Valor" />
          <div class="dos">
            <label class="campo">
              Fecha
              <span class="control"><input v-model="fecha" type="date" :max="hoy()" /></span>
            </label>
            <label class="campo">
              Hora
              <span class="control"><input v-model="hora" type="time" /></span>
            </label>
          </div>
          <label class="campo">
            De (quien paga)
            <span class="control"><input v-model="nombre" autocomplete="off" placeholder="Si aparece en el comprobante" /></span>
            <!-- Algunos bancos y Bre-B ocultan parte del nombre: "Bra*** Sti*** Fig*****" -->
            <span v-if="nombre.includes('*')" class="suave pequeno">El banco ocultó parte del nombre. Si sabe quién es, escríbalo completo.</span>
          </label>
          <label class="campo">
            Referencia
            <span class="control"><input v-model="referencia" autocomplete="off" autocapitalize="characters" placeholder="Ej.: M1234567" /></span>
          </label>

          <!-- RN-40 -->
          <p v-if="parecido && !repetido" class="mensaje aviso">
            <IconAlertTriangle :size="20" aria-hidden="true" />
            Ya hay un pago escrito a mano de {{ pesos(parecido.valor ?? 0) }} a las {{ parecido.hora }}. Puede ser el mismo; si es así, descarte este o anule el otro.
          </p>

          <!-- RN-39: venta o abono de fiado -->
          <section v-if="deudores.conSaldo.length && !repetido" class="tipo">
            <div>
              <h3 v-if="sugerido">¿Es abono de {{ sugerido.nombre }}?</h3>
              <h3 v-else>¿Es una venta o el abono de un fiado?</h3>
              <p v-if="sugerido" class="suave">Debe {{ pesos(sugerido.saldo) }}</p>
            </div>
            <div class="opciones" role="group" aria-label="Tipo de pago">
              <button type="button" class="opcion" :class="{ activa: tipo === 'venta' }" :aria-pressed="tipo === 'venta'" @click="tipo = 'venta'">Venta</button>
              <button type="button" class="opcion" :class="{ activa: tipo === 'abono' }" :aria-pressed="tipo === 'abono'" @click="tipo = 'abono'">Abono de fiado</button>
            </div>
            <template v-if="tipo === 'abono'">
              <label class="campo">
                ¿Quién abona?
                <span class="control">
                  <select v-model="deudorId" @change="escogioDeudor = true">
                    <option value="" disabled>Escoja la persona</option>
                    <option v-for="d in deudores.conSaldo" :key="d.deudor.id" :value="d.deudor.id">{{ d.nombre }} · debe {{ pesos(d.saldo) }}</option>
                  </select>
                </span>
              </label>
              <div v-if="elegido && valor" class="recuadro reparto">
                <div class="fila"><span>Abono a la deuda</span><strong class="monto">{{ pesos(reparto.abono) }}</strong></div>
                <div v-if="reparto.venta" class="fila"><span>Venta del día (lo que sobra)</span><strong class="monto">{{ pesos(reparto.venta) }}</strong></div>
                <p class="suave pequeno">
                  {{ reparto.abono >= elegido.saldo ? `${primerNombre} queda a paz y salvo.` : `${primerNombre} queda debiendo ${pesos(elegido.saldo - reparto.abono)}.` }}
                </p>
              </div>
            </template>
          </section>
          </div>
          </fieldset>

          <p v-if="error" class="mensaje error" role="alert">
            <IconAlertTriangle :size="20" aria-hidden="true" />
            {{ error }}
          </p>
        </div>

        <footer class="acciones">
          <button type="button" class="boton secundario" :disabled="leyendoEsta" @click="descartar">Descartar</button>
          <button class="boton" :disabled="repetido || leyendoEsta">
            <IconCheck :size="22" aria-hidden="true" />
            Confirmar
          </button>
        </footer>
      </form>
    </div>
  </Transition>
</template>

<style scoped>
.fondo {
  position: fixed;
  inset: 0;
  z-index: 40;
  display: flex;
  align-items: flex-end;
  justify-content: center;
  background: rgb(4 30 24 / 0.45);
}

@media (min-width: 600px) {
  .fondo {
    align-items: center;
    padding: 16px;
  }
}

.hoja {
  width: 100%;
  max-width: 480px;
  max-height: 94vh;
  display: flex;
  flex-direction: column;
  background: var(--superficie);
  border-radius: 24px 24px 0 0;
  box-shadow: var(--sombra-3);
}

@media (min-width: 600px) {
  .hoja {
    border-radius: 24px;
  }
}

.cabecera {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 18px 16px 10px 20px;
}

.cerrar {
  width: 44px;
  height: 44px;
  display: grid;
  place-items: center;
  color: var(--texto-suave);
  background: var(--superficie-suave);
  border: none;
  border-radius: 50%;
  cursor: pointer;
}

.cuerpo {
  display: grid;
  gap: 14px;
  padding: 4px 20px 16px;
  overflow-y: auto;
}

.miniatura {
  width: 100%;
  max-height: 180px;
  object-fit: contain;
  background: var(--superficie-suave);
  border-radius: 14px;
  outline: 1px solid rgb(0 0 0 / 0.06);
  outline-offset: -1px;
}

/* minmax(0, …): los campos de fecha y hora tienen un ancho mínimo propio que desbordaba la pantalla */
.cuerpo > * {
  min-width: 0;
}

/* El fieldset solo sirve para pausar los campos mientras lee. Va como bloque, con la lista adentro:
   los WebViews viejos de Android 7 no dejan que un fieldset sea grid (RNF-03) */
.campos {
  min-width: 0;
  margin: 0;
  padding: 0;
  border: 0;
}

.campos:disabled {
  opacity: 0.55;
}

.lista {
  display: grid;
  gap: 14px;
}

.lista > * {
  min-width: 0;
}

.leyendo {
  align-items: center;
  color: var(--verde-800);
  background: var(--verde-50);
}

.girando {
  flex: none;
  animation: girar 1s linear infinite;
}

@keyframes girar {
  to {
    transform: rotate(360deg);
  }
}

@media (prefers-reduced-motion: reduce) {
  .girando {
    animation: none;
  }
}

.dos {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: 10px;
}

.dos > *,
.dos .control,
.dos input {
  min-width: 0;
}

/* En un celular angosto, la fecha y la hora completas caben con letra un poco más pequeña */
.dos input {
  font-size: 1rem;
  padding-inline: 10px;
}

.tipo {
  display: grid;
  gap: 12px;
  padding-top: 4px;
}

.opciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}

.opcion {
  min-height: 56px;
  padding: 10px;
  font: inherit;
  font-weight: 800;
  color: var(--verde-800);
  background: var(--superficie);
  border: 1.5px solid var(--borde-campo);
  border-radius: var(--radio-control);
  cursor: pointer;
  transition-property: background-color, border-color, color, transform;
  transition-duration: 150ms;
}

.opcion:active {
  transform: scale(0.97);
}

.opcion.activa {
  color: #ffffff;
  background: var(--verde-700);
  border-color: var(--verde-700);
}

.reparto {
  display: grid;
  gap: 4px;
}

.reparto .fila {
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.acciones {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
  padding: 12px 20px 20px;
  padding-bottom: max(20px, env(safe-area-inset-bottom));
  border-top: 1px solid var(--linea);
}

.hoja-enter-active,
.hoja-leave-active {
  transition-property: opacity;
  transition-duration: 200ms;
}

.hoja-enter-active .hoja,
.hoja-leave-active .hoja {
  transition-property: transform;
  transition-duration: 240ms;
  transition-timing-function: cubic-bezier(0.2, 0.9, 0.3, 1);
}

.hoja-enter-from,
.hoja-leave-to {
  opacity: 0;
}

.hoja-enter-from .hoja {
  transform: translateY(40px);
}

.hoja-leave-to .hoja {
  transform: translateY(12px);
}
</style>
