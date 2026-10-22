<script setup>
import { computed, ref } from 'vue'
import { Head } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import SeriesChart from '../../shared/SeriesChart.vue'
import { send } from '../../shared/http.js'

defineOptions({ layout: AppLayout })
const props = defineProps({ metrics: { type: Array, default: () => [] } })

const iso = (d) => d.toISOString().slice(0, 10)
const today = new Date()
const metric = ref(props.metrics[0]?.metric_key ?? '')
const from = ref(iso(new Date(today.getTime() - 29 * 864e5)))
const to = ref(iso(today))
const report = ref(null)
const error = ref('')
const busy = ref(false)

const chartSeries = computed(() => (report.value?.series ?? []).map((s) => ({ key: s.key, unit: s.unit, currency: s.currency, points: s.points })))
const stale = computed(() => (report.value?.series ?? []).map((s) => s.refreshed_at).filter(Boolean).sort()[0] ?? null)

async function run() {
  busy.value = true
  error.value = ''
  const qs = new URLSearchParams({ metric_key: metric.value, date_from: from.value, date_to: to.value })
  const { ok, data } = await send('GET', `/core/reports?${qs}`)
  busy.value = false
  if (ok) { report.value = data; return }
  report.value = null
  error.value = data?.errors ? Object.values(data.errors).flat().join(' ') : (data?.message ?? 'Could not load the report.')
}
</script>

<template>
  <Head title="Reports" />
  <main class="wrap">
    <h1>Reports</h1>
    <p v-if="!metrics.length" class="muted">No reports are available for your enabled modules.</p>
    <form v-else class="row wrapx" @submit.prevent="run">
      <label>Metric
        <select v-model="metric"><option v-for="m in metrics" :key="m.metric_key" :value="m.metric_key">{{ m.label }} ({{ m.modules.join(', ') }})</option></select>
      </label>
      <label>From <input v-model="from" type="date" required></label>
      <label>To <input v-model="to" type="date" required></label>
      <button class="btn" :disabled="busy">{{ busy ? 'Loading…' : 'Show report' }}</button>
    </form>
    <p v-if="error" role="alert" class="error">{{ error }}</p>
    <div v-if="report" class="card" style="margin-top:1rem">
      <SeriesChart :title="`${report.metric_key} · ${report.granularity === 'month' ? 'monthly' : 'daily'}`" :series="chartSeries" />
      <p v-if="stale" class="hint">Summaries last refreshed {{ new Date(stale).toLocaleString() }}.</p>
    </div>
  </main>
</template>
