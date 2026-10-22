<script setup>
import { computed } from 'vue'
import { Head, Link, usePage } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import ExtensionSlot from '../../shared/ExtensionSlot.vue'
import { term } from '../../shared/terms.js'

defineOptions({ layout: AppLayout })
const props = defineProps({ customer: Object, metadata: Object, canManage: Boolean })

const page = usePage()
const one = computed(() => term(page.props.terms, 'Party'))
const many = computed(() => term(page.props.terms, 'Party', { plural: true }))
const context = computed(() => ({ customer: props.customer, metadata: props.metadata }))
</script>

<template>
  <Head :title="customer.customer_name" />
  <main class="wrap">
    <p><Link href="/core/customers">&larr; {{ many }}</Link></p>
    <h1>{{ customer.customer_name }}</h1>
    <p class="muted">{{ one }}</p>

    <div class="card">
      <dl class="dl">
        <dt>Email</dt><dd>{{ customer.email }}</dd>
        <dt>Phone</dt><dd>{{ customer.phone || '—' }}</dd>
        <dt>Billing address</dt><dd>{{ customer.billing_address || '—' }}</dd>
        <dt>Tax number</dt><dd>{{ customer.tax_number || '—' }}</dd>
      </dl>
    </div>

    <ExtensionSlot hook="CustomerProfileTabs" :context="context" />
  </main>
</template>
