// WCAG 2.x contrast maths. Mirrors Modules\Core\Theming\Colour (PHP); the server is the authority.

export const AA = 4.5
export const SURFACE = '#ffffff'

export function normaliseHex(value) {
  const v = String(value ?? '').trim().toLowerCase()
  if (/^#[0-9a-f]{3}$/.test(v)) return '#' + [...v.slice(1)].map((c) => c + c).join('')
  return /^#[0-9a-f]{6}$/.test(v) ? v : null
}

export function luminance(hex) {
  const h = normaliseHex(hex)
  if (!h) throw new Error(`Bad colour ${hex}`)
  const [r, g, b] = [1, 3, 5].map((i) => {
    const s = parseInt(h.slice(i, i + 2), 16) / 255
    return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * r + 0.7152 * g + 0.0722 * b
}

export function contrast(a, b) {
  const [la, lb] = [luminance(a), luminance(b)]
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05)
}

export function readableOn(background) {
  return contrast(background, '#ffffff') >= contrast(background, '#111111') ? '#ffffff' : '#111111'
}

/** Warnings for the three palette colours used as text on the white page surface. */
export function contrastWarnings(palette) {
  const out = []
  for (const token of ['primary_colour', 'secondary_colour', 'accent_colour']) {
    const hex = normaliseHex(palette[token])
    if (!hex) continue
    const ratio = Math.round(contrast(hex, SURFACE) * 100) / 100
    if (ratio < AA) out.push({ token, colour: hex, ratio })
  }
  return out
}

/** CSS custom properties for the root layout binding. */
export function themeStyle(theme) {
  if (!theme) return {}
  return {
    '--accent': theme.primary_colour,
    '--accent-ink': theme.on_primary,
    '--secondary': theme.secondary_colour,
    '--highlight': theme.accent_colour,
  }
}
