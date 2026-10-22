<script setup>
import { computed, reactive, ref } from 'vue'
import { Head, Link, router, usePage } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { send } from '../../shared/http.js'
import { term } from '../../shared/terms.js'

defineOptions({ layout: AppLayout })
const props = defineProps({ customers: Object, q: String, canManage: Boolean })

const page = usePage()
const one = computed(() => term(page.props.terms, 'Party'))
const many = computed(() => term(page.props.terms, 'Party', { plural: true }))

const search = ref(props.q ?? '')
function doSearch() {
  router.get('/core/customers', search.value ? { q: search.value } : {}, { preserveState: true, replace: true })
}

const blank = () => ({ customer_name: '', email: '', phone: '', billing_address: '', tax_number: '' })
const editing = ref(null)          // null = closed, 0 = new, id = editing
const form = reactive(blank())
const errors = ref({})
const message = ref('')
const busy = ref(false)

function openNew() { Object.assign(form, blank()); editing.value = 0; errors.value = {}; message.value = '' }
function openEdit(c) {
  Object.assign(form, { customer_name: c.customer_name, email: c.email, phone: c.phone ?? '', billing_address: c.billing_address ?? '', tax_number: c.tax_number ?? '' })
  editing.value = c.customer_id; errors.value = {}; message.value = ''
}

async function save() {
  busy.value = true; errors.value = {}; message.value = ''
  const isNew = editing.value === 0
  const { ok, status, data } = isNew
    ? await send('POST', '/core/customers', { ...form })
    : await send('PUT', `/core/customers/${editing.value}`, { ...form })
  busy.value = false
  if (ok) {
    editing.value = null
    router.reload({ only: ['customers'] })
  } else if (status === 422) errors.value = data?.errors ?? {}
  else message.value = data?.message ?? 'Could not save. Please try again.'
}
</script>

<template>
  <Head :title="many" />
  <main class="wrap">
    <div class="row" style="justify-content:space-between">
      <h1>{{ many }}</h1>
      <button v-if="canManage" class="btn" @click="openNew">Add {{ one.toLowerCase() }}</button>
    </div>

    <form class="row" role="search" style="margin-bottom:1rem" @submit.prevent="doSearch">
      <input v-model="search" type="search" :aria-label="'Search ' + many.toLowerCase()" :placeholder="'Search ' + many.toLowerCase()" style="max-width:20rem">
      <button class="btn ghost" type="submit">Search</button>
    </form>

    <form v-if="editing !== null" class="card" style="margin-bottom:1rem" novalidate @submit.prevent="save">
      <h2>{{ editing === 0 ? 'New ' + one.toLowerCase() : 'Edit ' + one.toLowerCase() }}</h2>
      <div class="grid two">
        <div class="field"><label for="cn">Name</label><input id="cn" v-model="form.customer_name" :aria-invalid="!!errors.customer_name"><span v-if="errors.customer_name" class="error">{{ errors.customer_name[0] }}</span></div>
        <div class="field"><label for="ce">Email</label><input id="ce" v-model="form.email" type="email" :aria-invalid="!!errors.email"><span v-if="errors.email" class="error">{{ errors.email[0] }}</span></div>
        <div class="field"><label for="cp">Phone</label><input id="cp" v-model="form.phone"><span v-if="errors.phone" class="error">{{ errors.phone[0] }}</span></div>
        <div class="field"><label for="ct">Tax number</label><input id="ct" v-model="form.tax_number"><span v-if="errors.tax_number" class="error">{{ errors.tax_number[0] }}</span></div>
      </div>
      <div class="field"><label for="ca">Billing address</label><input id="ca" v-model="form.billing_address"><span v-if="errors.billing_address" class="error">{{ errors.billing_address[0] }}</span></div>
      <p v-if="message" class="error" role="alert">{{ message }}</p>
      <div class="row"><button class="btn" type="submit" :disabled="busy">Save</button><button class="btn ghost" type="button" @click="editing = null">Cancel</button></div>
    </form>

    <div class="card">
      <p v-if="!customers.data.length" class="muted">No {{ many.toLowerCase() }} yet.</p>
      <table v-else class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Phone</th><th /></tr></thead>
        <tbody>
          <tr v-for="c in customers.data" :key="c.customer_id">
            <td><Link :href="`/core/customers/${c.customer_id}`">{{ c.customer_name }}</Link></td><td>{{ c.email }}</td><td>{{ c.phone }}</td>
            <td><button v-if="canManage" class="btn ghost" @click="openEdit(c)">Edit</button></td>
          </tr>
        </tbody>
      </table>
      <nav v-if="customers.links?.length > 3" class="row" aria-label="Pagination" style="margin-top:.75rem">
        <template v-for="l in customers.links" :key="l.label">
          <Link v-if="l.url" :href="l.url" preserve-scroll v-html="l.label" :class="{ active: l.active }" />
          <span v-else class="muted" v-html="l.label" />
        </template>
      </nav>
    </div>
  </main>
</template>
