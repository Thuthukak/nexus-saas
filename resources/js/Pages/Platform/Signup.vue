<script setup>
import { computed, reactive, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import {
  BUSINESS_TYPES, COUNTRIES, billingCurrency, effectiveSubdomain, formatMoney, priceFor, submitSignup, validate,
} from '../../lib/signup.js'

const props = defineProps({ plans: Array, rootDomain: String })

const form = reactive({
  company_name: '', admin_name: '', admin_email: '', admin_password: '',
  requested_subdomain: '', business_type: '', subscription_plan: props.plans?.[0]?.key ?? '',
  billing_cycle: 'monthly', country_code: 'ZA',
})
const errors = ref({})
const banner = ref('')
const busy = ref(false)

const currency = computed(() => billingCurrency(form.country_code))
const subdomain = computed(() => effectiveSubdomain(form.requested_subdomain, form.company_name))

function planPrice(plan) {
  const p = priceFor(plan, currency.value, form.billing_cycle)
  if (!p) return null
  return { text: formatMoney(p.monthly_equivalent, currency.value), from: p.starting_from }
}

async function submit() {
  banner.value = ''
  errors.value = validate(form)
  if (Object.keys(errors.value).length) return

  busy.value = true
  const result = await submitSignup({ ...form, requested_subdomain: subdomain.value })
  if (result.ok) {
    // Full-page navigation to the tenant host: a different origin, so no fetch/Inertia visit.
    window.location.href = result.handoffUrl
    return
  }
  busy.value = false
  errors.value = result.errors
  banner.value = result.message
}
</script>

<template>
  <Head title="Start your workspace" />
  <main class="wrap">
    <h1>Start your workspace</h1>
    <p class="muted">Pick a plan, tell us about your business and you will be signed in straight away.</p>

    <form novalidate @submit.prevent="submit">
      <div v-if="banner" class="alert" role="alert">{{ banner }}</div>

      <h2>Plan</h2>
      <div class="seg" role="group" aria-label="Billing cycle">
        <button type="button" :aria-pressed="form.billing_cycle === 'monthly'" @click="form.billing_cycle = 'monthly'">Monthly</button>
        <button type="button" :aria-pressed="form.billing_cycle === 'annual'" @click="form.billing_cycle = 'annual'">Annual (save 20%)</button>
      </div>
      <div class="plans">
        <button v-for="plan in plans" :key="plan.key" type="button" class="plan"
                :aria-pressed="form.subscription_plan === plan.key" @click="form.subscription_plan = plan.key">
          <strong>{{ plan.name }}</strong>
          <div v-if="planPrice(plan)" class="price">
            <span v-if="planPrice(plan).from" class="hint">from </span>{{ planPrice(plan).text }}<span class="hint"> / month</span>
          </div>
          <div class="hint">{{ plan.modules.join(' · ') }}</div>
        </button>
      </div>
      <p v-if="errors.subscription_plan" class="error">{{ errors.subscription_plan }}</p>

      <h2>Your business</h2>
      <div class="grid two">
        <div class="field">
          <label for="company_name">Company name</label>
          <input id="company_name" v-model="form.company_name" autocomplete="organization" :aria-invalid="!!errors.company_name">
          <span v-if="errors.company_name" class="error">{{ errors.company_name }}</span>
        </div>
        <div class="field">
          <label for="business_type">Business type</label>
          <input id="business_type" v-model="form.business_type" list="business-types" :aria-invalid="!!errors.business_type">
          <datalist id="business-types"><option v-for="b in BUSINESS_TYPES" :key="b" :value="b" /></datalist>
          <span v-if="errors.business_type" class="error">{{ errors.business_type }}</span>
        </div>
        <div class="field">
          <label for="country_code">Country</label>
          <select id="country_code" v-model="form.country_code" :aria-invalid="!!errors.country_code">
            <option v-for="[code, name] in COUNTRIES" :key="code" :value="code">{{ name }}</option>
          </select>
          <span class="hint">Billed in {{ currency }}.</span>
          <span v-if="errors.country_code" class="error">{{ errors.country_code }}</span>
        </div>
        <div class="field">
          <label for="requested_subdomain">Web address</label>
          <input id="requested_subdomain" v-model="form.requested_subdomain" autocomplete="off" :placeholder="subdomain || 'your-company'" :aria-invalid="!!errors.requested_subdomain">
          <span class="hint addr">{{ subdomain || 'your-company' }}.{{ rootDomain }}</span>
          <span v-if="errors.requested_subdomain" class="error">{{ errors.requested_subdomain }}</span>
        </div>
      </div>

      <h2>Your account</h2>
      <div class="grid two">
        <div class="field">
          <label for="admin_name">Your name</label>
          <input id="admin_name" v-model="form.admin_name" autocomplete="name" :aria-invalid="!!errors.admin_name">
          <span v-if="errors.admin_name" class="error">{{ errors.admin_name }}</span>
        </div>
        <div class="field">
          <label for="admin_email">Email</label>
          <input id="admin_email" v-model="form.admin_email" type="email" autocomplete="email" :aria-invalid="!!errors.admin_email">
          <span v-if="errors.admin_email" class="error">{{ errors.admin_email }}</span>
        </div>
        <div class="field">
          <label for="admin_password">Password</label>
          <input id="admin_password" v-model="form.admin_password" type="password" autocomplete="new-password" :aria-invalid="!!errors.admin_password">
          <span class="hint">At least 10 characters, with letters and numbers.</span>
          <span v-if="errors.admin_password" class="error">{{ errors.admin_password }}</span>
        </div>
      </div>

      <button class="btn" type="submit" :disabled="busy">{{ busy ? 'Creating your workspace…' : 'Create workspace' }}</button>
    </form>
  </main>
</template>
