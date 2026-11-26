<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { IconAlertTriangle, IconCalendarCheck, IconCashRegister, IconChevronLeft, IconChevronRight } from '@tabler/icons-vue'
import BarraSuperior from '../components/BarraSuperior.vue'
import CalendarioMes from '../components/CalendarioMes.vue'
import PanelDia from '../components/PanelDia.vue'
import PanelLateral from '../components/PanelLateral.vue'
import EstadoSincronizacion from '../components/EstadoSincronizacion.vue'
import ComprobantesPorRevisar from '../components/ComprobantesPorRevisar.vue'
import NavegacionInferior from '../components/NavegacionInferior.vue'
import AvisoSuscripcion from '../components/AvisoSuscripcion.vue'
import { estadoDelDia, fechaDe, fechaLarga, hoy as fechaHoy, mejorYPeor, mesVecino, nombreMes, pesos, totalDelMes, totalesDelDia, type ResumenDia } from '../calculos'
import { cierreDelDia, cierresEntre, pagosDelDia, pagosEntre, useConsulta } from '../datos'
import { useSesionStore } from '../stores/sesion'
import { useSincronizacionStore } from '../stores/sincronizacion'
import { useSalir } from '../composables/salir'

const sesion = useSesionStore()
const sync = useSincronizacionStore()
const salir = useSalir()

// RF-14: al abrir, el mes actual con el día de hoy seleccionado (RF-15)
const hoy = fechaHoy()
const anio = ref(Number(hoy.slice(0, 4)))
const mes = ref(Number(hoy.slice(5, 7)))
const seleccionado = ref(hoy)

const primerDia = computed(() => fechaDe(anio.value, mes.value, 1))
const ultimoDia = computed(() => fechaDe(anio.value, mes.value, new Date(Date.UTC(anio.value, mes.value, 0)).getUTCDate()))

const cierres = useConsulta(() => cierresEntre(primerDia.value, ultimoDia.value), [], [primerDia])
const pagos = useConsulta(() => pagosEntre(primerDia.value, ultimoDia.value), [], [primerDia])

const resumen = computed<ResumenDia[]>(() => {
  const dias: ResumenDia[] = []
  const ultimo = Number(ultimoDia.value.slice(8))
  for (let dia = 1; dia <= ultimo; dia++) {
    const fecha = fechaDe(anio.value, mes.value, dia)
    const cierre = cierres.value.find((c) => c.fecha === fecha)
    const estado = estadoDelDia(fecha, hoy, cierre)
    const total = totalesDelDia(cierre, pagos.value.filter((p) => p.fecha === fecha)).total
    dias.push({ fecha, estado, total })
  }
  return dias
})

const porFecha = computed(() => new Map(resumen.value.map((d) => [d.fecha, d])))
const mejorPeor = computed(() => mejorYPeor(resumen.value))
const totalMes = computed(() => totalDelMes(resumen.value))
const diasCerrados = computed(() => resumen.value.filter((d) => d.estado === 'cerrado').length)
const titulo = computed(() => `${nombreMes(mes.value)} ${anio.value}`)
const esMesActual = computed(() => primerDia.value === hoy.slice(0, 8) + '01')

function cambiarMes(paso: 1 | -1) {
  const vecino = mesVecino(anio.value, mes.value, paso)
  anio.value = vecino.anio
  mes.value = vecino.mes
  // En el mes actual queda hoy; en otro mes, el día 1 (los futuros no se pueden escoger)
  seleccionado.value = esMesActual.value ? hoy : primerDia.value
}

/*
 * RF-15: el día escogido se abre en un panel que entra desde la izquierda. El día va en la
 * dirección (?dia=2026-11-21), así el botón "atrás" del celular cierra el panel y vuelve al calendario.
 */
const route = useRoute()
const router = useRouter()
const diaAbierto = computed(() => {
  const dia = route.query.dia
  return typeof dia === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(dia) && dia <= hoy ? dia : null
})
// El último día abierto se sigue mostrando mientras el panel sale (para que no quede vacío)
const diaDelPanel = ref(diaAbierto.value ?? hoy)
watch(diaAbierto, (dia) => {
  if (dia) diaDelPanel.value = seleccionado.value = dia
})
const tituloPanel = computed(() => {
  if (diaDelPanel.value === hoy) return 'Cierre de hoy'
  const texto = `${SEMANA[new Date(`${diaDelPanel.value}T12:00:00Z`).getUTCDay()]} ${fechaLarga(diaDelPanel.value)}`
  return texto.charAt(0).toUpperCase() + texto.slice(1)
})
const SEMANA = ['domingo', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábado']

function abrirDia(fecha: string) {
  seleccionado.value = fecha
  if (fecha > hoy) return
  router.push({ query: { dia: fecha } })
}

// Si se llegó desde el calendario, "atrás"; si la app se abrió con el panel abierto, solo se cierra
function cerrarDia() {
  if (window.history.state?.back) router.back()
  else router.replace({ query: {} })
}

// Botón de hoy debajo del calendario: la forma más directa de hacer el cierre del día
const cierreHoy = useConsulta(() => cierreDelDia(hoy), null)
const pagosHoy = useConsulta(() => pagosDelDia(hoy), [])
const botonHoy = computed(() => {
  const c = cierreHoy.value
  if (c && !c.no_abrio) return { texto: 'Ver el cierre de hoy', detalle: pesos(totalesDelDia(c, pagosHoy.value).total), primario: false }
  if (c?.no_abrio) return { texto: 'Ver hoy', detalle: 'No abrió', primario: false }
  if (sync.soloConsulta) return { texto: 'Ver hoy', detalle: '', primario: false }
  return { texto: 'Hacer el cierre de hoy', detalle: '', primario: true }
})
</script>

<template>
  <div class="con-navegacion">
    <BarraSuperior :titulo="sesion.usuario?.negocio?.nombre ?? 'Mi negocio'" @salir="salir">
      <EstadoSincronizacion />
    </BarraSuperior>

    <main class="pantalla">
      <!-- RF-12 / RN-27: solo consulta -->
      <p v-if="sync.motivoSoloConsulta === 'bloqueado'" class="mensaje aviso" role="alert">
        <IconAlertTriangle :size="22" aria-hidden="true" />
        Su negocio está bloqueado por falta de pago. Puede consultar su información, pero no registrar. Comuníquese con el administrador.
      </p>
      <p v-else-if="sync.motivoSoloConsulta === 'sin_sincronizar'" class="mensaje aviso" role="alert">
        <IconAlertTriangle :size="22" aria-hidden="true" />
        Lleva más de 7 días sin conectarse a internet. Conéctese para seguir registrando; lo que ya guardó no se pierde.
      </p>

      <AvisoSuscripcion />

      <!-- RF-18: fotos de comprobantes que faltan por leer o confirmar -->
      <ComprobantesPorRevisar />

      <!-- Lo primero que se ve: la forma más directa de hacer el cierre del día -->
      <button type="button" class="boton hoy-boton" :class="{ secundario: !botonHoy.primario }" @click="abrirDia(hoy)">
        <component :is="botonHoy.primario ? IconCashRegister : IconCalendarCheck" :size="24" aria-hidden="true" />
        <span class="hoy-textos">
          <span>{{ botonHoy.texto }}</span>
          <span v-if="botonHoy.detalle" class="hoy-detalle">{{ botonHoy.detalle }}</span>
        </span>
      </button>

      <section class="tarjeta">
        <nav class="mes" aria-label="Cambiar de mes">
          <button class="flecha" aria-label="Mes anterior" @click="cambiarMes(-1)"><IconChevronLeft :size="26" /></button>
          <div class="mes-textos">
            <h2>{{ titulo }}</h2>
            <p class="total-mes">
              <span class="suave">Total del mes</span>
              <strong class="monto">{{ pesos(totalMes) }}</strong>
            </p>
            <p class="suave pequeno">{{ diasCerrados }} {{ diasCerrados === 1 ? 'día cerrado' : 'días cerrados' }}</p>
          </div>
          <button class="flecha" aria-label="Mes siguiente" @click="cambiarMes(1)"><IconChevronRight :size="26" /></button>
        </nav>

        <CalendarioMes
          :anio="anio"
          :mes="mes"
          :hoy="hoy"
          :seleccionado="seleccionado"
          :dias="porFecha"
          :mejor="mejorPeor.mejor"
          :peor="mejorPeor.peor"
          @seleccionar="abrirDia"
        />
        <p class="suave pequeno pista">Toque un día para ver o registrar su cierre.</p>
      </section>
    </main>

    <PanelLateral :abierto="diaAbierto !== null" :titulo="tituloPanel" @cerrar="cerrarDia">
      <PanelDia
        :fecha="diaDelPanel"
        :hoy="hoy"
        :negocio-id="sesion.usuario!.negocio!.id"
        :solo-consulta="sync.soloConsulta"
        en-panel
      />
    </PanelLateral>

    <NavegacionInferior />
  </div>
</template>

<style scoped>
.mes {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.mes-textos {
  text-align: center;
  display: grid;
  gap: 2px;
}

.mes h2 {
  text-transform: capitalize;
}

.total-mes {
  display: flex;
  align-items: baseline;
  justify-content: center;
  flex-wrap: wrap;
  gap: 0 8px;
}

.total-mes strong {
  font-size: 1.3rem;
  color: var(--verde-700);
}

.flecha {
  width: 48px;
  height: 48px;
  flex: none;
  display: grid;
  place-items: center;
  color: var(--verde-800);
  background: var(--verde-50);
  border: none;
  border-radius: 14px;
  cursor: pointer;
  transition-property: background-color, transform;
  transition-duration: 150ms;
}

.flecha:hover {
  background: var(--verde-200);
}

.flecha:active {
  transform: scale(0.94);
}

.pista {
  text-align: center;
}

/* Botón de hoy: texto y valor dentro de un span (un <button> no puede ser flex en Android 7, RNF-03) */
.hoy-boton {
  width: 100%;
}

.hoy-textos {
  display: inline-flex;
  flex-direction: column;
  align-items: flex-start;
  line-height: 1.2;
}

.hoy-detalle {
  font-size: 0.85rem;
  font-weight: 700;
  color: var(--texto-suave);
}
</style>
