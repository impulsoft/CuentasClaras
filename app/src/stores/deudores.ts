import { defineStore } from 'pinia'
import { liveQuery } from 'dexie'
import { computed, shallowRef } from 'vue'
import { resumenDeTodos } from '../datosDeudores'
import { ordenarDeudores, totalPorCobrar, type ResumenDeudor } from '../deudas'

// Lista de deudores con su saldo y estado, siempre al día con la base local (RF-31, RF-32)
export const useDeudoresStore = defineStore('deudores', () => {
  const lista = shallowRef<ResumenDeudor[]>([])
  const cargando = shallowRef(true)

  liveQuery(() => resumenDeTodos()).subscribe({
    next: (resumenes) => {
      lista.value = ordenarDeudores(resumenes)
      cargando.value = false
    },
  })

  const totales = computed(() => totalPorCobrar(lista.value))
  const conSaldo = computed(() => lista.value.filter((d) => d.saldo > 0))

  return { lista, cargando, totales, conSaldo }
})
