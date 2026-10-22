<script setup>
import { reactive, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { send } from '../../shared/http.js'

defineOptions({ layout: AppLayout })
const props = defineProps({ rows: Array })

const form = reactive(Object.fromEntries(props.rows.map((r) => [r.generic, { singular: r.singular, plural: r.plural }])))
const errors = ref({})
const message = ref('')
const sourceText = { tenant: 'your wording', business_type: 'default for your business type', generic: 'generic name' }

async function save(r) {
  errors.value = {}; message.value = ''
  const { ok, status, data } = await send('PUT', '/core/terminology', { generic_term: r.generic, display_term: form[r.generic].singular, display_plural: form[r.generic].plural })
  if (ok) { message.value = 'Saved.'; router.reload({ only: ['rows', 'terms'] }) }
  else if (status === 422) errors.value = { [r.generic]: Object.values(data?.errors ?? {}).flat()[0] }
  else message.value = data?.message ?? 'Could not save.'
}
async function reset(r) {
  const { ok } = await send('DELETE', `/core/terminology/${r.generic}`)
  if (ok) router.reload({ only: ['rows', 'terms'], onSuccess: () => { const n = props.rows.find((x) => x.generic === r.generic); form[r.generic] = { singular: n.singular, plural: n.plural } } })
}
</script>

<template>
  <Head title="Terminology" />
  <main class="wrap narrow">
    <h1>Terminology</h1>
    <p class="muted">Choose the words your workspace uses. This only changes labels, never your data.</p>
    <p v-if="message" role="status">{{ message }}</p>
    <div v-for="r in rows" :key="r.generic" class="card" style="margin-bottom:1rem">
      <h2>{{ r.generic }}</h2>
      <p class="hint">Currently: {{ r.singular }} / {{ r.plural }} ({{ sourceText[r.source] }})</p>
      <div class="grid two">
        <div class="field"><label :for="r.generic + 's'">Singular</label><input :id="r.generic + 's'" v-model="form[r.generic].singular"></div>
        <div class="field"><label :for="r.generic + 'p'">Plural</label><input :id="r.generic + 'p'" v-model="form[r.generic].plural"></div>
      </div>
      <p v-if="errors[r.generic]" class="error" role="alert">{{ errors[r.generic] }}</p>
      <div class="row"><button class="btn" @click="save(r)">Save</button><button v-if="r.source === 'tenant'" class="btn ghost" @click="reset(r)">Use default</button></div>
    </div>
  </main>
</template>
