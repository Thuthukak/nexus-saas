<script setup>
import { computed, reactive, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { contrastWarnings, normaliseHex, readableOn } from '../../shared/colour.js'
import { send } from '../../shared/http.js'

defineOptions({ layout: AppLayout })
const props = defineProps({ palette: Object })

const form = reactive({ ...props.palette })
const acknowledge = ref(false)
const busy = ref(false)
const message = ref('')
const errors = ref({})

const labels = { primary_colour: 'Primary', secondary_colour: 'Secondary', accent_colour: 'Accent' }
const valid = computed(() => Object.keys(labels).every((k) => normaliseHex(form[k])))
const warnings = computed(() => (valid.value ? contrastWarnings(form) : []))

async function save() {
  busy.value = true; message.value = ''; errors.value = {}
  const { ok, status, data } = await send('POST', '/core/theme', { ...form, acknowledge_contrast: acknowledge.value })
  busy.value = false
  if (ok) {
    message.value = 'Theme saved.'
    // Re-fetch the shared `theme` prop: the root layout binding updates reactively.
    router.reload({ only: ['theme', 'palette'] })
  } else if (status === 422) {
    errors.value = data?.errors ?? {}
  } else if (status === 403) {
    message.value = data?.message ?? 'You do not have permission.'
  } else {
    message.value = 'Could not save. Please try again.'
  }
}
</script>

<template>
  <Head title="Theme" />
  <main class="wrap narrow">
    <h1>Theme</h1>
    <p class="muted">Colours apply across your whole workspace.</p>

    <form class="card" novalidate @submit.prevent="save">
      <div v-for="(label, key) in labels" :key="key" class="field">
        <label :for="key">{{ label }}</label>
        <span class="row">
          <input :id="key" v-model="form[key]" :aria-invalid="!!errors[key]" style="max-width:9rem">
          <input type="color" :value="normaliseHex(form[key]) ?? '#000000'" :aria-label="label + ' picker'" @input="form[key] = $event.target.value">
          <span v-if="normaliseHex(form[key])" class="swatch" :style="{ background: form[key], color: readableOn(form[key]) }">Aa</span>
        </span>
        <span v-if="errors[key]" class="error">{{ errors[key][0] }}</span>
      </div>

      <div v-if="warnings.length" class="alert" role="alert">
        <p v-for="w in warnings" :key="w.token">
          {{ labels[w.token] }} {{ w.colour }}: contrast {{ w.ratio }}:1 on white. WCAG AA needs 4.5:1.
        </p>
        <label><input v-model="acknowledge" type="checkbox" style="width:auto"> Save anyway</label>
      </div>
      <p v-if="errors.acknowledge_contrast" class="error">{{ errors.acknowledge_contrast[0] }}</p>
      <p v-if="message" role="status">{{ message }}</p>

      <button class="btn" type="submit" :disabled="busy || !valid || (warnings.length > 0 && !acknowledge)">Save theme</button>
    </form>
  </main>
</template>
