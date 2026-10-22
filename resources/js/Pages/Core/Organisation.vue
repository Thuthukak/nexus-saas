<script setup>
import { reactive, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { send } from '../../shared/http.js'

defineOptions({ layout: AppLayout })
const props = defineProps({ profile: Object })

const form = reactive({
  organisation_name: props.profile?.organisation_name ?? '',
  organisation_email: props.profile?.organisation_email ?? '',
  organisation_phone: props.profile?.organisation_phone ?? '',
  organisation_address: props.profile?.organisation_address ?? '',
  tax_registration_number: props.profile?.tax_registration_number ?? '',
})
const logo = ref(null)
const errors = ref({})
const message = ref('')
const busy = ref(false)

async function save() {
  busy.value = true; message.value = ''; errors.value = {}
  const fd = new FormData()
  for (const [k, v] of Object.entries(form)) fd.append(k, v ?? '')
  if (logo.value) fd.append('logo', logo.value)

  const { ok, status, data } = await send('POST', '/core/organisation', fd)
  busy.value = false
  if (ok) {
    message.value = 'Organisation profile saved.'
    logo.value = null
    router.reload({ only: ['profile'] })
  } else if (status === 422) {
    errors.value = data?.errors ?? {}
  } else {
    message.value = data?.message ?? 'Could not save. Please try again.'
  }
}
</script>

<template>
  <Head title="Organisation" />
  <main class="wrap narrow">
    <h1>Organisation profile</h1>
    <p class="muted">Shown on your quotations, invoices and other documents.</p>

    <form class="card" novalidate @submit.prevent="save">
      <img v-if="profile?.logo_url" :src="profile.logo_url" alt="Current logo" style="max-height:4rem;margin-bottom:1rem">
      <div class="field">
        <label for="logo">Logo (PNG, JPG or WebP, up to 1 MB)</label>
        <input id="logo" type="file" accept="image/png,image/jpeg,image/webp" @change="logo = $event.target.files[0] ?? null">
        <span v-if="errors.logo" class="error">{{ errors.logo[0] }}</span>
      </div>
      <div class="field">
        <label for="organisation_name">Name</label>
        <input id="organisation_name" v-model="form.organisation_name" :aria-invalid="!!errors.organisation_name">
        <span v-if="errors.organisation_name" class="error">{{ errors.organisation_name[0] }}</span>
      </div>
      <div class="field">
        <label for="organisation_email">Email</label>
        <input id="organisation_email" v-model="form.organisation_email" type="email" :aria-invalid="!!errors.organisation_email">
        <span v-if="errors.organisation_email" class="error">{{ errors.organisation_email[0] }}</span>
      </div>
      <div class="field">
        <label for="organisation_phone">Phone</label>
        <input id="organisation_phone" v-model="form.organisation_phone">
        <span v-if="errors.organisation_phone" class="error">{{ errors.organisation_phone[0] }}</span>
      </div>
      <div class="field">
        <label for="organisation_address">Address</label>
        <input id="organisation_address" v-model="form.organisation_address">
        <span v-if="errors.organisation_address" class="error">{{ errors.organisation_address[0] }}</span>
      </div>
      <div class="field">
        <label for="tax_registration_number">Tax registration number</label>
        <input id="tax_registration_number" v-model="form.tax_registration_number">
        <span v-if="errors.tax_registration_number" class="error">{{ errors.tax_registration_number[0] }}</span>
      </div>
      <p v-if="message" role="status">{{ message }}</p>
      <button class="btn" type="submit" :disabled="busy">Save</button>
    </form>
  </main>
</template>
