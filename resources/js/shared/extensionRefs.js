// Pure helpers for extension points (no Vite glob, so they are unit-testable). Mirrors the server rules.

const REF = /^[A-Za-z0-9]+(\/[A-Za-z0-9_-]+)+$/

/** A component reference is a safe relative path like "Support/CustomerTicketsTab". */
export function isSafeRef(ref) {
  return typeof ref === 'string' && REF.test(ref) && !ref.includes('..')
}

/** Same order as the server: order_index, then module, then component. */
export function sortExtensions(list) {
  return [...(list ?? [])].sort((a, b) =>
    a.order_index - b.order_index
    || a.module_key.localeCompare(b.module_key)
    || a.component.localeCompare(b.component))
}

/** Keep only extensions with a safe reference; the server already filtered by enabled modules. */
export function usableExtensions(list) {
  return sortExtensions(list).filter((e) => isSafeRef(e.component) && e.component.startsWith(e.module_key + '/'))
}
