import { describe, expect, it } from 'vitest'
import {
  aniosDePagos,
  mesesDePagos,
  type PagoSuscripcion,
  contarGrupos,
  enlaceAyuda,
  enlaceCobro,
  enGrupo,
  filtrarNegocios,
  haceCuanto,
  necesitaAyuda,
  nuevoVencimiento,
  porAyudar,
  porVencer,
  sumarMes,
  textoUltimoCierre,
  textoVencimiento,
  type NegocioAdmin,
} from './admin'

// Debe dar lo mismo que Negocio::registrarPago en la API (RN-12)
describe('vencimiento de la suscripción (RN-12)', () => {
  it('al día: un mes desde el vencimiento anterior', () => {
    expect(nuevoVencimiento('2026-11-20', '2026-11-18')).toBe('2026-12-20')
    expect(nuevoVencimiento('2026-11-18', '2026-11-18')).toBe('2026-12-18') // vence hoy: sigue al día
  })

  it('vencida: un mes desde el día del pago, sin cobrar los días vencidos', () => {
    expect(nuevoVencimiento('2026-11-01', '2026-11-18')).toBe('2026-12-18')
    expect(nuevoVencimiento(null, '2026-11-18')).toBe('2026-12-18')
  })

  it('el fin de mes no se pasa al mes siguiente', () => {
    expect(sumarMes('2027-01-31')).toBe('2027-02-28')
    expect(sumarMes('2028-01-31')).toBe('2028-02-29')
    expect(sumarMes('2026-12-15')).toBe('2027-01-15')
  })

  it('texto corto del vencimiento', () => {
    expect(textoVencimiento(3)).toBe('Vence en 3 días')
    expect(textoVencimiento(0)).toBe('Vence hoy')
    expect(textoVencimiento(-1)).toBe('Venció ayer')
    expect(textoVencimiento(-8)).toBe('Venció hace 8 días')
    expect(textoVencimiento(null)).toBe('Sin pagos registrados')
  })
})

describe('negocios que necesitan ayuda', () => {
  const HOY = '2026-11-23'
  const negocio = (id: string, ultimo: string | null, cambios: Partial<NegocioAdmin> = {}): NegocioAdmin => ({
    id,
    nombre: `Tienda ${id}`,
    telefono: '3101234567',
    estado: 'activo',
    suscripcion: { inicio_el: '2026-11-01', vence_el: '2026-12-01', estado: 'activa', dias: 8 },
    datos_se_borran_el: null,
    datos_borrados_en: null,
    ultimo_valor: 30000,
    deudas: { por_cobrar: 0, deudores: 0, vencidos: 0 },
    dueno: { nombre: 'José Martínez', usuario: id, telefono: '3109876543', activo: true, debe_cambiar_contrasena: false },
    actividad: { ultimo_cierre: ultimo, ultima_conexion: null },
    ...cambios,
  })

  it('dice hace cuánto fue el último cierre', () => {
    expect(haceCuanto('2026-11-23', HOY)).toBe('hoy')
    expect(haceCuanto('2026-11-22', HOY)).toBe('ayer')
    expect(haceCuanto('2026-11-17', HOY)).toBe('hace 6 días')
    expect(haceCuanto('2026-11-23T15:20:00-05:00', HOY)).toBe('hoy')
    expect(textoUltimoCierre(negocio('a', null), HOY)).toBe('Todavía no ha hecho su primer cierre')
  })

  it('pide ayuda con 3 días o más sin cierre, o sin el primero; no si está bloqueado o desactivado', () => {
    expect(necesitaAyuda(negocio('a', '2026-11-21'), HOY)).toBe(false) // hace 2 días
    expect(necesitaAyuda(negocio('b', '2026-11-20'), HOY)).toBe(true) // hace 3 días
    expect(necesitaAyuda(negocio('c', null), HOY)).toBe(true)
    expect(necesitaAyuda(negocio('d', null, { estado: 'bloqueado' }), HOY)).toBe(false)
    expect(necesitaAyuda(negocio('e', null, { dueno: { nombre: 'X', usuario: 'e', telefono: null, activo: false, debe_cambiar_contrasena: false } }), HOY)).toBe(false)
  })

  it('primero los que nunca han cerrado y luego los de más días', () => {
    const lista = porAyudar([negocio('a', '2026-11-20'), negocio('b', '2026-11-01'), negocio('c', null), negocio('d', '2026-11-22')], HOY)
    expect(lista.map((n) => n.id)).toEqual(['c', 'b', 'a'])
  })

  it('el WhatsApp va al dueño con un mensaje amable', () => {
    const enlace = decodeURIComponent(enlaceAyuda(negocio('a', '2026-11-17'), HOY)!)
    expect(enlace).toContain('wa.me/573109876543')
    expect(enlace).toContain('Hola José, le escribimos de Cierre de Caja. Vimos que no ha registrado el cierre desde hace 6 días.')
  })
  const conVencimiento = (id: string, dias: number, vence: string, cambios: Partial<NegocioAdmin> = {}) =>
    negocio(id, HOY, { suscripcion: { inicio_el: '2026-10-01', vence_el: vence, estado: dias < 0 ? 'vencida' : dias <= 5 ? 'por_vencer' : 'activa', dias }, ...cambios })

  it('por vencer: primero los que vencen antes; no los que ya no usan el servicio', () => {
    const lista = porVencer([
      conVencimiento('a', 4, '2026-11-27'),
      conVencimiento('b', 1, '2026-11-24'),
      conVencimiento('c', 9, '2026-12-02'), // al día
      conVencimiento('d', -2, '2026-11-21'), // ya vencida: va en la otra lista
      conVencimiento('e', 2, '2026-11-25', { datos_borrados_en: '2026-11-20T10:00:00-05:00' }),
    ])
    expect(lista.map((n) => n.id)).toEqual(['b', 'a'])
  })

  it('el recordatorio de pago dice la fecha y el valor', () => {
    const texto = (n: NegocioAdmin) => decodeURIComponent(enlaceCobro(n)!)
    expect(texto(conVencimiento('a', 4, '2026-11-27'))).toContain('Hola José, le escribimos de Cierre de Caja. Le recordamos que su mensualidad vence el 27 de noviembre. El valor es $30.000. Cuando haga el pago nos avisa por aquí. ¡Gracias!')
    expect(texto(conVencimiento('b', 1, '2026-11-24'))).toContain('su mensualidad vence mañana.')
    expect(texto(conVencimiento('c', 0, '2026-11-23'))).toContain('su mensualidad vence hoy.')
    expect(texto(conVencimiento('d', -2, '2026-11-21'))).toContain('su mensualidad venció el 21 de noviembre. El valor es $30.000. Cuando haga el pago nos avisa por aquí para dejar el servicio al día.')
    expect(texto(conVencimiento('e', 3, '2026-11-26', { ultimo_valor: 0 }))).not.toContain('El valor')
  })

  describe('filtro de la lista', () => {
    const desactivado = { nombre: 'Ana Ruiz', usuario: 'x', telefono: null, activo: false, debe_cambiar_contrasena: false }
    const lista = [
      conVencimiento('a', 9, '2026-12-02'), // al día
      conVencimiento('b', 2, '2026-11-25'), // por vencer
      conVencimiento('c', -4, '2026-11-19'), // vencido
      conVencimiento('d', -9, '2026-11-14', { estado: 'bloqueado' }), // bloqueado (aunque esté vencido)
      conVencimiento('e', 9, '2026-12-02', { dueno: desactivado }), // desactivado (aunque esté al día)
      conVencimiento('f', -40, '2026-10-14', { estado: 'bloqueado', datos_borrados_en: '2026-11-20T10:00:00-05:00' }), // retirado
    ]

    it('los desactivados solo están en su grupo; un bloqueado vencido está en los dos', () => {
      expect(contarGrupos(lista)).toEqual({ todos: 6, al_dia: 1, por_vencer: 1, vencidos: 2, bloqueados: 1, desactivados: 2 })
      expect(enGrupo(lista[3]!, 'vencidos')).toBe(true)
      expect(enGrupo(lista[3]!, 'bloqueados')).toBe(true)
      expect(enGrupo(lista[5]!, 'bloqueados')).toBe(false) // retirado
      expect(enGrupo(lista[4]!, 'al_dia')).toBe(false) // desactivado
    })

    it('en "Todos" los desactivados van al final', () => {
      const orden = [lista[4]!, ...lista.slice(0, 4)]
      expect(filtrarNegocios(orden, 'todos', '').map((n) => n.id)).toEqual(['a', 'b', 'c', 'd', 'e'])
      expect(filtrarNegocios(lista, 'desactivados', '').map((n) => n.id)).toEqual(['e', 'f'])
    })

    it('el filtro y la búsqueda se combinan', () => {
      expect(filtrarNegocios(lista, 'vencidos', '').map((n) => n.id)).toEqual(['c', 'd'])
      expect(filtrarNegocios(lista, 'vencidos', 'tienda c').map((n) => n.id)).toEqual(['c'])
      expect(filtrarNegocios(lista, 'vencidos', 'tienda a')).toEqual([])
      expect(filtrarNegocios(lista, 'todos', 'ANA').map((n) => n.id)).toEqual(['e'])
    })
  })
})

describe('meses pagados de un negocio', () => {
  const HOY = '2026-11-25'
  let id = 0
  const pago = (fecha: string, valor: number, vence: string, cambios: Partial<PagoSuscripcion> = {}): PagoSuscripcion => ({
    id: ++id,
    fecha,
    valor,
    vencia_el: null,
    vence_el: vence,
    nota: null,
    anulado_en: null,
    motivo_anulacion: null,
    ...cambios,
  })

  it('cada pago cuenta en el mes en que se pagó; un mes cubierto sin pago queda al día', () => {
    const pagos = [
      pago('2026-05-10', 30000, '2026-06-10'),
      pago('2026-06-08', 30000, '2026-07-10', { vencia_el: '2026-06-10' }),
      // julio: el pago de junio lo cubre hasta el 10 → al día
      // agosto: ninguno lo cubre → sin pagar
      pago('2026-09-03', 30000, '2026-10-03'),
      pago('2026-09-20', 5000, '2026-11-03', { vencia_el: '2026-10-03', nota: 'Diferencia' }),
      pago('2026-10-31', 30000, '2026-12-03', { vencia_el: '2026-11-03' }),
      pago('2026-10-31', 30000, '2027-01-03', { anulado_en: '2026-11-01T10:00:00-05:00', motivo_anulacion: 'Repetido' }),
    ]
    const meses = mesesDePagos(pagos, 2026, HOY, '2026-05-10')

    expect(meses.map((m) => m.estado)).toEqual([
      'antes', 'antes', 'antes', 'antes', 'pagado', 'pagado', 'al_dia', 'sin_pagar', 'pagado', 'pagado', 'al_dia', 'futuro',
    ])
    expect(meses[8]!.total).toBe(35000) // dos pagos en septiembre
    expect(meses[9]!.total).toBe(30000) // el anulado no suma...
    expect(meses[9]!.pagos).toHaveLength(2) // ...pero se ve en el detalle
  })

  it('un mes con solo un pago anulado y sin cobertura queda sin pagar', () => {
    const pagos = [pago('2026-09-01', 30000, '2026-10-01'), pago('2026-11-02', 30000, '2026-12-02', { anulado_en: '2026-11-03T09:00:00-05:00' })]
    const meses = mesesDePagos(pagos, 2026, HOY, '2026-09-01')
    expect(meses[9]!.estado).toBe('sin_pagar') // octubre: el de septiembre vence el 1 (no cubre el mes)
    expect(meses[10]!.estado).toBe('sin_pagar')
    expect(meses[10]!.pagos).toHaveLength(1)
  })

  it('en el mes actual cuenta si hoy sigue al día', () => {
    const vencida = [pago('2026-10-23', 35000, '2026-11-23')] // venció hace 2 días
    expect(mesesDePagos(vencida, 2026, HOY, '2026-10-23')[10]!.estado).toBe('sin_pagar')
    const venceHoy = [pago('2026-10-25', 35000, '2026-11-25')]
    expect(mesesDePagos(venceHoy, 2026, HOY, '2026-10-25')[10]!.estado).toBe('al_dia')
  })

  it('los años van desde el primer pago hasta el actual', () => {
    expect(aniosDePagos([pago('2025-03-01', 1, '2025-04-01')], HOY, '2025-03-01')).toEqual([2025, 2026])
    expect(aniosDePagos([], HOY, null)).toEqual([2026])
  })
})
