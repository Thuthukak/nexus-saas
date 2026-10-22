// Pure signup logic (no Vue, no DOM) so it can be unit-tested with Vitest.
// Mirrors the server rules in CreateTenantRequest; the server remains the authority.

export const COUNTRIES = [
  ['ZA', 'South Africa'], ['NA', 'Namibia'], ['BW', 'Botswana'], ['ZW', 'Zimbabwe'], ['MZ', 'Mozambique'],
  ['KE', 'Kenya'], ['NG', 'Nigeria'], ['GB', 'United Kingdom'], ['US', 'United States'], ['AU', 'Australia'],
  ['DE', 'Germany'], ['NL', 'Netherlands'], ['AE', 'United Arab Emirates'], ['IN', 'India'],
]

export const BUSINESS_TYPES = ['Retail', 'Restaurant', 'Professional services', 'Healthcare', 'Legal', 'Education', 'Construction', 'Other']

/** ZAR only for South Africa, USD everywhere else. */
export function billingCurrency(countryCode) {
  return String(countryCode || '').trim().toUpperCase() === 'ZA' ? 'ZAR' : 'USD'
}

export function slugify(value) {
  return String(value || '')
    .normalize('NFD').replace(/[̀-ͯ]/g, '')
    .toLowerCase().trim()
    .replace(/[^a-z0-9-]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '')
}

/** The subdomain the server will use: the requested one, else derived from the company name. */
export function effectiveSubdomain(requested, company) {
  return slugify(requested) || slugify(company)
}

export function formatMoney(minor, currency, locale = 'en-ZA') {
  const major = minor / 100
  const whole = Number.isInteger(major)
  return new Intl.NumberFormat(locale, {
    style: 'currency', currency, minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: 2,
  }).format(major)
}

/** @returns {{monthly_equivalent:number, charge:number, starting_from:boolean}|null} */
export function priceFor(plan, currency, cycle) {
  return plan?.prices?.[currency]?.[cycle] ?? null
}

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/

export function validate(form) {
  const e = {}
  if (!form.company_name?.trim()) e.company_name = 'Enter your company name.'
  if (!form.admin_name?.trim()) e.admin_name = 'Enter your name.'
  if (!EMAIL.test(form.admin_email?.trim() || '')) e.admin_email = 'Enter a valid email address.'
  const pw = form.admin_password || ''
  if (pw.length < 10 || !/[A-Za-z]/.test(pw) || !/\d/.test(pw)) e.admin_password = 'Use at least 10 characters with letters and numbers.'
  else if (pw.length > 72) e.admin_password = 'Use at most 72 characters.'
  const sub = effectiveSubdomain(form.requested_subdomain, form.company_name)
  if (sub.length < 3 || sub.length > 63) e.requested_subdomain = 'Your web address must be 3 to 63 characters.'
  if (!form.business_type?.trim()) e.business_type = 'Tell us your business type.'
  if (!form.subscription_plan) e.subscription_plan = 'Choose a plan.'
  if (!form.country_code) e.country_code = 'Choose your country.'
  return e
}

/**
 * POSTs the signup. Never throws for HTTP outcomes.
 * @returns {Promise<{ok:true, handoffUrl:string}|{ok:false, status:number, message:string, errors:Object}>}
 */
export async function submitSignup(form, fetchImpl = fetch, url = '/api/tenants') {
  let res
  try {
    res = await fetchImpl(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(form),
    })
  } catch {
    return { ok: false, status: 0, message: 'Could not reach the server. Check your connection and try again.', errors: {} }
  }

  let body = {}
  try { body = await res.json() } catch { /* non-JSON error page */ }

  if (res.status === 201 && body.handoff_url) return { ok: true, handoffUrl: body.handoff_url }

  const errors = {}
  for (const [field, msgs] of Object.entries(body.errors || {})) errors[field] = Array.isArray(msgs) ? msgs[0] : String(msgs)

  const message = {
    409: body.message || 'That web address or email is already in use.',
    422: 'Please correct the highlighted fields.',
    429: 'Too many attempts. Please wait a minute and try again.',
  }[res.status] || 'Something went wrong. Please try again.'

  return { ok: false, status: res.status, message, errors }
}
