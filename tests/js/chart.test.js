import { describe, expect, it } from 'vitest'
import { buildChart, formatValue, niceMax } from '../../resources/js/shared/chart.js'

describe('niceMax', () => {
  it('rounds up to 1, 2, 5 or 10 times a power of ten', () => {
    expect(niceMax(0)).toBe(1)
    expect(niceMax(-4)).toBe(1)
    expect(niceMax(1)).toBe(1)
    expect(niceMax(3)).toBe(5)
    expect(niceMax(7)).toBe(10)
    expect(niceMax(120)).toBe(200)
    expect(niceMax(430)).toBe(500)
    expect(niceMax(0.3)).toBe(0.5)
  })
})

describe('formatValue', () => {
  it('formats counts with grouping and money from minor units', () => {
    expect(formatValue(12345)).toBe('12,345')
    expect(formatValue(12345, 'money', 'USD')).toBe('$123.45')
  })
  it('does not crash on an unknown currency code', () => {
    expect(formatValue(1050, 'money', 'ZZZZ')).toContain('10.50')
  })
})

describe('buildChart', () => {
  const s = (key, points) => ({ key, points })

  it('returns an empty chart for no data', () => {
    expect(buildChart([]).lines).toEqual([])
    expect(buildChart([s('a', [])]).lines).toEqual([])
  })

  it('places points on a time scale (a gap in dates is a gap in space)', () => {
    const c = buildChart([s('a', [{ date: '2026-01-01', value: 1 }, { date: '2026-01-02', value: 1 }, { date: '2026-01-11', value: 1 }])])
    const [p1, p2, p3] = c.lines[0].dots
    expect(p2.x - p1.x).toBeCloseTo((p3.x - p1.x) / 10, 5)
  })

  it('shares one y scale across series and keeps zero at the baseline', () => {
    const c = buildChart([s('a', [{ date: '2026-01-01', value: 0 }, { date: '2026-01-02', value: 3 }]), s('b', [{ date: '2026-01-01', value: 8 }])], { height: 220, top: 10, bottom: 24 })
    expect(c.max).toBe(10)
    expect(c.lines[0].dots[0].y).toBe(196)             // baseline = top + h (10 + 186)
    expect(c.lines[1].dots[0].y).toBeLessThan(c.lines[0].dots[1].y)
  })

  it('centres a single date and still draws it', () => {
    const c = buildChart([s('a', [{ date: '2026-01-05', value: 4 }])])
    expect(c.lines[0].dots).toHaveLength(1)
    expect(c.lines[0].d.startsWith('M')).toBe(true)
    expect(c.xTicks).toHaveLength(1)
  })

  it('sorts unsorted points and caps the x labels at four', () => {
    const pts = Array.from({ length: 30 }, (_, i) => ({ date: `2026-01-${String(30 - i).padStart(2, '0')}`, value: i }))
    const c = buildChart([s('a', pts)])
    expect(c.lines[0].dots[0].date).toBe('2026-01-01')
    expect(c.xTicks.length).toBeLessThanOrEqual(4)
  })
})
