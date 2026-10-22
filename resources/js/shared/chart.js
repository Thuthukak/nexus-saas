/** Pure chart maths for SeriesChart.vue. No DOM, so it is unit-tested. */

export function niceMax(max) {
  if (!(max > 0)) return 1
  const exp = Math.floor(Math.log10(max))
  const base = 10 ** exp
  const f = max / base
  const step = f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10
  return step * base
}

const dayMs = (d) => Date.parse(`${d}T00:00:00Z`)

/** Money arrives as integer minor units; counts as integers. */
export function formatValue(value, unit = 'count', currency = null) {
  if (unit === 'money' && currency) {
    try {
      return new Intl.NumberFormat('en', { style: 'currency', currency }).format(value / 100)
    } catch { /* unknown currency code: fall through */ }
    return `${(value / 100).toFixed(2)} ${currency}`
  }
  return new Intl.NumberFormat('en').format(value)
}

/**
 * @param {{key:string, points:{date:string, value:number}[]}[]} series
 * @returns {{lines:{key:string, d:string, dots:{x:number,y:number,date:string,value:number}[]}[], yTicks:{y:number,value:number}[], xTicks:{x:number,date:string}[], max:number}}
 */
export function buildChart(series, { width = 600, height = 220, left = 44, right = 12, top = 10, bottom = 24 } = {}) {
  const all = series.flatMap((s) => s.points)
  if (!all.length) return { lines: [], yTicks: [], xTicks: [], max: 0 }

  const times = all.map((p) => dayMs(p.date))
  const t0 = Math.min(...times)
  const t1 = Math.max(...times)
  const max = niceMax(Math.max(...all.map((p) => p.value), 0))
  const w = width - left - right
  const h = height - top - bottom

  const x = (date) => (t1 === t0 ? left + w / 2 : left + ((dayMs(date) - t0) / (t1 - t0)) * w)
  const y = (v) => top + h - (Math.max(v, 0) / max) * h

  const lines = series.map((s) => {
    const dots = [...s.points].sort((a, b) => dayMs(a.date) - dayMs(b.date)).map((p) => ({ x: x(p.date), y: y(p.value), date: p.date, value: p.value }))
    return { key: s.key, dots, d: dots.map((p, i) => `${i ? 'L' : 'M'}${p.x.toFixed(1)} ${p.y.toFixed(1)}`).join(' ') }
  })

  const yTicks = [0, 1, 2, 3, 4].map((i) => ({ y: y((max / 4) * i), value: (max / 4) * i }))
  const dates = [...new Set(all.map((p) => p.date))].sort()
  const pick = dates.length <= 4 ? dates : [dates[0], dates[Math.floor(dates.length / 3)], dates[Math.floor((2 * dates.length) / 3)], dates[dates.length - 1]]
  const xTicks = [...new Set(pick)].map((date) => ({ x: x(date), date }))

  return { lines, yTicks, xTicks, max }
}
