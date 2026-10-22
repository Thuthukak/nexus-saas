import { describe, it, expect } from 'vitest'
import { normaliseHex, contrast, readableOn, contrastWarnings, themeStyle } from '../../resources/js/shared/colour.js'
import { newToasts } from '../../resources/js/shared/notifications.js'

describe('colour', () => {
  it('normalises hex', () => {
    expect(normaliseHex('#FFF')).toBe('#ffffff')
    expect(normaliseHex(' #1D4ED8 ')).toBe('#1d4ed8')
    for (const bad of ['red', '#12', '#12345', '#gggggg', '', null, 123]) expect(normaliseHex(bad)).toBeNull()
  })
  it('computes known WCAG ratios', () => {
    expect(contrast('#000000', '#ffffff')).toBeCloseTo(21, 5)
    expect(contrast('#ffffff', '#ffffff')).toBeCloseTo(1, 5)
    expect(contrast('#767676', '#ffffff')).toBeGreaterThanOrEqual(4.5)   // 4.54:1, the classic AA grey
    expect(contrast('#777777', '#ffffff')).toBeLessThan(4.5)             // 4.48:1
  })
  it('picks readable text', () => {
    expect(readableOn('#ffffff')).toBe('#111111')
    expect(readableOn('#000000')).toBe('#ffffff')
  })
  it('warns only for colours below 4.5:1 on white', () => {
    const w = contrastWarnings({ primary_colour: '#1d4ed8', secondary_colour: '#777777', accent_colour: '#ffff00' })
    expect(w.map((x) => x.token)).toEqual(['secondary_colour', 'accent_colour'])
  })
  it('maps a theme to CSS custom properties', () => {
    expect(themeStyle({ primary_colour: '#111', on_primary: '#fff', secondary_colour: '#222', accent_colour: '#333' }))
      .toEqual({ '--accent': '#111', '--accent-ink': '#fff', '--secondary': '#222', '--highlight': '#333' })
    expect(themeStyle(null)).toEqual({})
  })
})

describe('toasts', () => {
  const now = Date.now()
  const n = (id, ageMs, read = null) => ({ id, read_at: read, created_at: new Date(now - ageMs).toISOString() })
  it('toasts only new, unread, unseen notifications', () => {
    const list = [n('a', 1000), n('b', 60000), n('c', 500, '2026-01-01'), n('d', 200)]
    expect(newToasts(list, new Set(['d']), now - 5000).map((x) => x.id)).toEqual(['a'])
  })
  it('handles empty input', () => expect(newToasts(null, new Set(), 0)).toEqual([]))
})
