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

/*
 * Tarjeta "Hoy", lo primero que se ve: si falta el cierre, el botón para hacerlo; si ya se hizo,
 * lo vendido hoy. Es la forma más directa de llegar al cierre del día.
 */
const cierreHoy = useConsulta(() => cierreDelDia(hoy), null)
const pagosHoy = useConsulta(() => pagosDelDia(hoy), [])
const fechaHoyTexto = (() => {
  const texto = `${SEMANA[new Date(`${hoy}T12:00:00Z`).getUTCDay()]} ${fechaLarga(hoy)}`
  return texto.charAt(0).toUpperCase() + texto.slice(1)
})()
const tarjetaHoy = computed(() => {
  const c = cierreHoy.value
  if (c && !c.no_abrio) return { titulo: 'Vendido hoy', valor: pesos(totalesDelDia(c, pagosHoy.value).total), boton: 'Ver el cierre', primario: false }
  if (c?.no_abrio) return { titulo: 'Hoy no abrió', valor: null, boton: 'Ver hoy', primario: false }
  if (sync.soloConsulta) return { titulo: 'Hoy', valor: null, boton: 'Ver hoy', primario: false }
  return { titulo: 'Falta el cierre de hoy', valor: null, boton: 'Hacer el cierre', primario: true }
})

// Cifras del mes que se está viendo en el calendario
const promedioDia = computed(() => (diasCerrados.value ? Math.round(totalMes.value / diasCerrados.value) : null))
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

      <!-- Lo primero que se ve: el día de hoy y la forma más directa de hacer su cierre -->
      <section class="tarjeta hoy" :class="{ pendiente: tarjetaHoy.primario }" aria-label="Hoy">
        <p class="hoy-fecha">Hoy · {{ fechaHoyTexto }}</p>
        <p class="hoy-titulo">{{ tarjetaHoy.titulo }}</p>
        <p v-if="tarjetaHoy.valor" class="hoy-valor monto">{{ tarjetaHoy.valor }}</p>
        <button type="button" class="boton" :class="{ secundario: !tarjetaHoy.primario }" @click="abrirDia(hoy)">
          <component :is="tarjetaHoy.primario ? IconCashRegister : IconCalendarCheck" :size="22" aria-hidden="true" />
          {{ tarjetaHoy.boton }}
        </button>
      </section>

      <!-- Cifras del mes que se ve en el calendario -->
      <section class="cifras" aria-label="Resumen del mes">
        <div class="cifra">
          <span class="cifra-nombre">Vendido en {{ nombreMes(mes) }}</span>
          <strong class="cifra-valor monto">{{ pesos(totalMes) }}</strong>
          <span class="cifra-nota">{{ diasCerrados }} {{ diasCerrados === 1 ? 'día cerrado' : 'días cerrados' }}</span>
        </div>
        <div class="cifra">
          <span class="cifra-nombre">Promedio por día</span>
          <strong class="cifra-valor monto">{{ promedioDia === null ? '–' : pesos(promedioDia) }}</strong>
          <span class="cifra-nota">de los días cerrados</span>
        </div>
      </section>

      <section class="tarjeta calendario-tarjeta">
        <nav class="mes" aria-label="Cambiar de mes">
          <button class="flecha" aria-label="Mes anterior" @click="cambiarMes(-1)"><IconChevronLeft :size="22" /></button>
          <h2>{{ titulo }}</h2>
          <button class="flecha" aria-label="Mes siguiente" @click="cambiarMes(1)"><IconChevronRight :size="22" /></button>
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
/* ---------- Tarjeta "Hoy" ---------- */
.hoy {
  gap: 4px;
}

.hoy-fecha {
  font-size: 0.85rem;
  font-weight: 500;
  color: var(--texto-suave);
}

.hoy-titulo {
  font-size: 1.2rem;
  font-weight: 650;
  letter-spacing: -0.015em;
}

.hoy-valor {
  font-size: 2rem;
  font-weight: 700;
  letter-spacing: -0.025em;
  line-height: 1.15;
}

.hoy .boton {
  width: 100%;
  margin-top: 12px;
}

/* Falta el cierre: una franja verde a la izquierda llama la atención sin gritar */
.hoy.pendiente {
  box-shadow: inset 4px 0 0 var(--verde-600), var(--sombra-1);
}

/* ---------- Cifras del mes ---------- */
.cifras {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.cifra {
  display: grid;
  gap: 2px;
  padding: 14px 14px 12px;
  background: var(--superficie);
  border: 1px solid var(--linea);
  border-radius: var(--radio-tarjeta);
  box-shadow: var(--sombra-1);
}

.cifra-nombre {
  font-size: 0.8rem;
  font-weight: 500;
  color: var(--texto-suave);
}

.cifra-nombre::first-letter {
  text-transform: uppercase;
}

.cifra-valor {
  font-size: 1.3rem;
  font-weight: 700;
  letter-spacing: -0.02em;
  overflow-wrap: anywhere;
}

.cifra-nota {
  font-size: 0.75rem;
  color: var(--texto-tenue);
}

/* ---------- Calendario ---------- */
.mes {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.mes h2 {
  font-size: 1.05rem;
  text-transform: capitalize;
}

.flecha {
  width: 44px;
  height: 44px;
  flex: none;
  display: grid;
  place-items: center;
  color: var(--texto-suave);
  background: none;
  border: 1px solid var(--linea);
  border-radius: 12px;
  cursor: pointer;
  transition-property: background-color, transform;
  transition-duration: 150ms;
}

.flecha:hover {
  background: var(--superficie-suave);
}

.flecha:active {
  transform: scale(0.94);
}

.pista {
  text-align: center;
}
</style>
