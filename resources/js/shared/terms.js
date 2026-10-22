// Display vocabulary. Core stores entities under generic names (Party); the tenant sees its own words.

/**
 * @param {Object|null} terms  shared Inertia prop: { Party: { singular, plural, source }, ... }
 * @param {string} generic     Core's generic term, e.g. 'Party'
 */
export function term(terms, generic, { plural = false, lower = false } = {}) {
  const entry = terms?.[generic]
  const text = entry ? (plural ? entry.plural : entry.singular) : plural ? pluralise(generic) : generic
  return lower ? text.toLowerCase() : text
}

export function pluralise(s) {
  const t = String(s ?? '').trim()
  if (!t) return t
  if (/[^aeiou]y$/i.test(t)) return t.slice(0, -1) + 'ies'
  if (/(s|x|z|ch|sh)$/i.test(t)) return t + 'es'
  return t + 's'
}
