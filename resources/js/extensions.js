import { defineAsyncComponent } from 'vue'
import { isSafeRef } from './shared/extensionRefs.js'

// Only files under resources/js/Extensions/ can ever be rendered by a slot: the glob IS the whitelist.
const loaders = import.meta.glob('./Extensions/**/*.vue')
const cache = new Map()

export function resolveExtension(ref) {
  if (!isSafeRef(ref)) return null
  if (cache.has(ref)) return cache.get(ref)
  const loader = loaders[`./Extensions/${ref}.vue`]
  const component = loader ? defineAsyncComponent(loader) : null
  cache.set(ref, component)
  return component
}
