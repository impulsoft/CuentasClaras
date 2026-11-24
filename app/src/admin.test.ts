import { describe, expect, it } from 'vitest'
import { enlaceAyuda, haceCuanto, necesitaAyuda, nuevoVencimiento, porAyudar, sumarMes, textoUltimoCierre, textoVencimiento, type NegocioAdmin } from './admin'

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
})