<script setup>
import { computed } from 'vue'
import { Head, Link, useForm } from '@inertiajs/vue3'
import Layout from '../../Layouts/ControlPlaneLayout.vue'

const props = defineProps({ tenant: Object, domains: Array, subscription: Object, history: Array, can: Object })
const form = useForm({ reason: '' })
const suspended = computed(() => props.tenant.status === 'suspended')
const action = computed(() => (suspended.value ? 'reinstate' : 'suspend'))
const allowed = computed(() => props.tenant.status !== 'cancelled' && props.can[action.value])

function submit() {
  form.post(`/tenants/${props.tenant.id}/${action.value}`, { preserveScroll: true, onSuccess: () => form.reset() })
}
</script>

<template>
  <Head :title="tenant.name" />
  <Layout>
    <p><Link href="/tenants">All tenants</Link></p>
    <h1>{{ tenant.name }}</h1>
    <dl class="dl">
      <dt>Status</dt><dd>{{ tenant.status }}</dd>
      <dt>Subdomain</dt><dd>{{ tenant.subdomain }}</dd>
      <dt>Country / currency</dt><dd>{{ tenant.country_code }} / {{ tenant.billing_currency }}</dd>
      <dt>Plan</dt><dd>{{ subscription ? `${subscription.plan_name} (${subscription.billing_cycle}, ${subscription.status})` : '-' }}</dd>
    </dl>
    <h2>Domains</h2>
    <ul class="list"><li v-for="d in domains" :key="d.domain">{{ d.domain }} <span class="muted">{{ d.verified_at ? 'verified' : 'unverified' }}{{ d.is_active ? '' : ', inactive' }}</span></li>
      <li v-if="!domains.length" class="muted">No custom domains.</li></ul>

    <form v-if="allowed" class="card" @submit.prevent="submit">
      <h2>{{ suspended ? 'Reinstate tenant' : 'Suspend tenant' }}</h2>
      <p class="hint">{{ suspended ? 'Everyone in the workspace can sign in again.' : 'Access stops on the next request. No data is deleted. Tenant admins are emailed the reason.' }}</p>
      <div class="field">
        <label for="reason">Reason</label>
        <textarea id="reason" v-model="form.reason" maxlength="500" rows="3" required />
        <span v-if="form.errors.reason" class="error" role="alert">{{ form.errors.reason }}</span>
      </div>
      <button class="btn" type="submit" :disabled="form.processing || !form.reason.trim()">{{ suspended ? 'Reinstate' : 'Suspend' }}</button>
    </form>

    <h2>Status history</h2>
    <table class="table">
      <thead><tr><th>When</th><th>Change</th><th>By</th><th>Reason</th></tr></thead>
      <tbody>
        <tr v-for="(h, i) in history" :key="i">
          <td>{{ String(h.created_at).slice(0, 16) }}</td><td>{{ h.from_status }} to {{ h.to_status }}</td>
          <td>{{ h.actor === 'system' ? 'Platform' : h.operator_name ?? 'Operator' }}</td><td>{{ h.reason }}</td>
        </tr>
        <tr v-if="!history.length"><td colspan="4" class="muted">No changes yet.</td></tr>
      </tbody>
    </table>
  </Layout>
</template>
