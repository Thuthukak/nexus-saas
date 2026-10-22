import { describe, it, expect } from 'vitest'
import { term, pluralise } from '../../resources/js/shared/terms.js'

const terms = { Party: { singular: 'Patient', plural: 'Patients', source: 'business_type' } }

describe('term', () => {
  it('uses the tenant vocabulary', () => {
    expect(term(terms, 'Party')).toBe('Patient')
    expect(term(terms, 'Party', { plural: true })).toBe('Patients')
    expect(term(terms, 'Party', { lower: true })).toBe('patient')
  })
  it('falls back to the generic name when there is no mapping or no terms at all', () => {
    expect(term({}, 'Party')).toBe('Party')
    expect(term(null, 'Party', { plural: true })).toBe('Parties')
    expect(term(terms, 'Account', { plural: true })).toBe('Accounts')
  })
})

describe('pluralise (mirrors PHP TerminologyKey::pluralise)', () => {
  it.each([['Party', 'Parties'], ['Patient', 'Patients'], ['Class', 'Classes'], ['Box', 'Boxes'], ['Day', 'Days'], ['Church', 'Churches'], ['', '']])(
    '%s -> %s', (a, b) => expect(pluralise(a)).toBe(b))
})
