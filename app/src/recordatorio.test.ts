import { describe, expect, it } from 'vitest'
import { diasARecordar, momentoDelAviso } from './recordatorio'

describe('recordatorio diario del cierre', () => {
  const HOY = '2026-11-23'

  it('programa hoy y los días siguientes que no tienen cierre', () => {
    const dias = diasARecordar(HOY, '10:15', '19:00', new Set(['2026-11-24', '2026-11-26']), 5)
    expect(dias).toEqual(['2026-11-23', '2026-11-25', '2026-11-27'])
  })

  it('si ya pasó la hora de hoy, empieza mañana', () => {
    expect(diasARecordar(HOY, '19:00', '19:00', new Set(), 3)).toEqual(['2026-11-24', '2026-11-25'])
    expect(diasARecordar(HOY, '21:30', '19:00', new Set(), 3)).toEqual(['2026-11-24', '2026-11-25'])
  })

  it('si hoy ya tiene cierre (o "No abrió"), hoy no se recuerda', () => {
    expect(diasARecordar(HOY, '10:00', '19:00', new Set([HOY]), 2)).toEqual(['2026-11-24'])
  })

  it('cambia bien de mes y de año', () => {
    expect(diasARecordar('2026-12-30', '08:00', '19:00', new Set(), 3)).toEqual(['2026-12-30', '2026-12-31', '2027-01-01'])
  })

  it('el aviso sale a esa hora en Colombia, sin importar la zona del celular', () => {
    expect(momentoDelAviso('2026-11-23', '19:00').toISOString()).toBe('2026-11-24T00:00:00.000Z')
    expect(momentoDelAviso('2026-11-23', '16:30').toISOString()).toBe('2026-11-23T21:30:00.000Z')
  })
})
