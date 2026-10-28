<script setup>
import { reactive } from 'vue'
import { Head, Link, router } from '@inertiajs/vue3'
import Layout from '../../Layouts/ControlPlaneLayout.vue'

const props = defineProps({ tenants: Object, filters: Object })
const f = reactive({ q: props.filters?.q ?? '', status: props.filters?.status ?? '' })

function search() {
  router.get('/tenants', { q: f.q || undefined, status: f.status || undefined }, { preserveState: true, replace: true })
}
</script>

<template>
  <Head title="Tenants" />
  <Layout>
    <h1>Tenants</h1>
    <form class="row" @submit.prevent="search">
      <input v-model="f.q" type="search" placeholder="Name, subdomain or domain" aria-label="Search tenants">
      <select v-model="f.status" aria-label="Status" @change="search">
        <option value="">All statuses</option>
        <option value="active">Active</option>
        <option value="suspended">Suspended</option>
        <option value="cancelled">Cancelled</option>
      </select>
      <button class="btn" type="submit">Search</button>
    </form>
    <table class="table">
      <thead><tr><th>Name</th><th>Address</th><th>Plan</th><th>Status</th><th>Created</th></tr></thead>
      <tbody>
        <tr v-for="t in tenants.data" :key="t.id">
          <td><Link :href="`/tenants/${t.id}`">{{ t.name }}</Link></td>
          <td>{{ t.custom_domain || t.subdomain }}</td>
          <td>{{ t.plan ?? '-' }}</td>
          <td>{{ t.status }}</td>
          <td>{{ t.created_at?.slice(0, 10) }}</td>
        </tr>
        <tr v-if="!tenants.data.length"><td colspan="5" class="muted">No tenants match.</td></tr>
      </tbody>
    </table>
    <p class="row">
      <Link v-if="tenants.prev_page_url" :href="tenants.prev_page_url">Previous</Link>
      <span class="muted">Page {{ tenants.current_page }} of {{ tenants.last_page }}</span>
      <Link v-if="tenants.next_page_url" :href="tenants.next_page_url">Next</Link>
    </p>
  </Layout>
</template>
