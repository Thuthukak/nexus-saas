import { describe, it, expect } from 'vitest'
import { isSafeRef, sortExtensions, usableExtensions } from '../../resources/js/shared/extensionRefs.js'

describe('isSafeRef', () => {
  it.each(['Support/CustomerTicketsTab', 'Core/ModuleDataTab', 'Financial/Widgets/InvoiceSummary'])('accepts %s', (r) => expect(isSafeRef(r)).toBe(true))
  it.each(['', 'Support', '../Support/X', 'Support/../Core/X', '/Support/X', 'Support//X', 'Support/X.vue', 'Support/X Y', null, 5, 'Support/<script>'])('rejects %j', (r) => expect(isSafeRef(r)).toBe(false))
})

describe('ordering', () => {
  const a = { module_key: 'Support', component: 'Support/A', order_index: 100 }
  const b = { module_key: 'Core', component: 'Core/B', order_index: 100 }
  const c = { module_key: 'Core', component: 'Core/C', order_index: 5 }
  it('sorts by order_index, then module, then component', () => expect(sortExtensions([a, b, c]).map((x) => x.component)).toEqual(['Core/C', 'Core/B', 'Support/A']))
  it('does not mutate its input', () => { const l = [a, c]; sortExtensions(l); expect(l[0]).toBe(a) })
  it('drops registrations pointing outside their own module folder or with unsafe refs', () => {
    const bad1 = { module_key: 'Support', component: 'Core/Steal', order_index: 1 }
    const bad2 = { module_key: 'Support', component: 'Support/../X', order_index: 1 }
    expect(usableExtensions([a, bad1, bad2, c]).map((x) => x.component)).toEqual(['Core/C', 'Support/A'])
  })
  it('handles missing lists', () => expect(usableExtensions(undefined)).toEqual([]))
})
