import { describe, it, expect, vi } from 'vitest'
import { billingCurrency, slugify, effectiveSubdomain, formatMoney, priceFor, validate, submitSignup } from '../../resources/js/lib/signup.js'

const good = {
  company_name: 'Acme Trading', admin_name: 'Ada', admin_email: 'ada@example.com', admin_password: 'Str0ng-password!',
  requested_subdomain: 'acme', business_type: 'Retail', subscription_plan: 'starter', billing_cycle: 'monthly', country_code: 'ZA',
}

describe('currency', () => {
  it('ZAR only for ZA', () => {
    expect(billingCurrency('ZA')).toBe('ZAR')
    expect(billingCurrency('za')).toBe('ZAR')
    expect(billingCurrency('US')).toBe('USD')
    expect(billingCurrency('')).toBe('USD')
  })
})

describe('subdomain', () => {
  it('slugifies like the server', () => {
    expect(slugify('ACME Corp')).toBe('acme-corp')
    expect(slugify('--a---b--')).toBe('a-b')
    expect(slugify('Café Zoë')).toBe('cafe-zoe')
    expect(slugify('')).toBe('')
  })
  it('falls back to company name', () => {
    expect(effectiveSubdomain('', 'My Company (Pty) Ltd')).toBe('my-company-pty-ltd')
    expect(effectiveSubdomain('mine', 'Other')).toBe('mine')
  })
})

describe('money', () => {
  it('formats minor units without floats leaking', () => {
    expect(formatMoney(24900, 'ZAR')).toMatch(/249/)
    expect(formatMoney(1999, 'USD', 'en-US')).toBe('$19.99')
    expect(formatMoney(1900, 'USD', 'en-US')).toBe('$19')
  })
  it('looks up prices', () => {
    const plan = { prices: { ZAR: { monthly: { charge: 24900 } } } }
    expect(priceFor(plan, 'ZAR', 'monthly').charge).toBe(24900)
    expect(priceFor(plan, 'USD', 'monthly')).toBeNull()
  })
})

describe('validate', () => {
  it('accepts a good form', () => expect(validate(good)).toEqual({}))
  it.each([
    [{ company_name: '' }, 'company_name'],
    [{ admin_email: 'nope' }, 'admin_email'],
    [{ admin_password: 'short1' }, 'admin_password'],
    [{ admin_password: 'onlyletterspassword' }, 'admin_password'],
    [{ admin_password: 'a1'.repeat(40) }, 'admin_password'],
    [{ requested_subdomain: 'ab', company_name: 'Ab' }, 'requested_subdomain'],
    [{ business_type: ' ' }, 'business_type'],
    [{ subscription_plan: '' }, 'subscription_plan'],
  ])('rejects %j', (override, field) => expect(validate({ ...good, ...override })).toHaveProperty(field))
})

describe('submitSignup', () => {
  const resp = (status, body) => vi.fn().mockResolvedValue({ status, json: async () => body })

  it('returns the handoff URL on 201', async () => {
    const r = await submitSignup(good, resp(201, { handoff_url: 'https://acme.x/auth/handoff?t=1' }))
    expect(r).toEqual({ ok: true, handoffUrl: 'https://acme.x/auth/handoff?t=1' })
  })
  it('maps 422 field errors', async () => {
    const r = await submitSignup(good, resp(422, { message: 'Validation failed', errors: { admin_email: ['Bad.'] } }))
    expect(r).toMatchObject({ ok: false, status: 422, errors: { admin_email: 'Bad.' } })
  })
  it('surfaces the 409 message', async () => {
    const r = await submitSignup(good, resp(409, { message: 'A tenant with this subdomain or email address already exists' }))
    expect(r.message).toMatch(/already exists/)
  })
  it('handles 429, 500 with HTML body, and network failure', async () => {
    expect((await submitSignup(good, resp(429, {}))).message).toMatch(/Too many/)
    const html = vi.fn().mockResolvedValue({ status: 500, json: async () => { throw new Error('x') } })
    expect((await submitSignup(good, html)).message).toMatch(/Something went wrong/)
    expect((await submitSignup(good, vi.fn().mockRejectedValue(new Error('net')))).status).toBe(0)
  })
  it('does not treat 201 without a handoff URL as success', async () => {
    expect((await submitSignup(good, resp(201, {}))).ok).toBe(false)
  })
})
