<script setup>
import { onMounted, ref } from 'vue'
import { send } from './http.js'
import SeriesChart from './SeriesChart.vue'

// Widget data contract (any module):
//  { type:'stat',   label, value, note? }
//  { type:'list',   label, items:[{ text, detail? }] }
//  { type:'series', label, unit?, points:[{ date, value }] }
const props = defineProps({ widget: { type: Object, required: true } })

const state = ref('loading') // loading | ready | error
const data = ref(null)

async function load() {
  state.value = 'loading'
  const { ok, data: body } = await send('GET', props.widget.data_endpoint)
  if (!ok || !body) { state.value = 'error'; return }
  data.value = body
  state.value = 'ready'
}
onMounted(load)
</script>

<template>
  <section class="card widget" :aria-label="widget.title">
    <h2 class="wtitle">{{ widget.title }}</h2>
    <p v-if="state === 'loading'" class="muted" role="status">Loading…</p>
    <div v-else-if="state === 'error'" role="alert">
      <p class="muted">This widget could not be loaded.</p>
      <button class="btn ghost" @click="load">Try again</button>
    </div>
    <template v-else-if="data.type === 'stat'">
      <div class="stat">{{ data.value }}</div>
      <div class="muted">{{ data.label }}</div>
      <div v-if="data.note" class="hint">{{ data.note }}</div>
    </template>
    <template v-else-if="data.type === 'list'">
      <p v-if="!data.items?.length" class="muted">Nothing to show.</p>
      <ul class="list">
        <li v-for="(it, i) in data.items" :key="i">{{ it.text }}<span v-if="it.detail" class="hint"> · {{ it.detail }}</span></li>
      </ul>
    </template>
    <SeriesChart v-else-if="data.type === 'series'" :title="data.label" :series="[{ key: data.label, unit: data.unit, points: data.points ?? [] }]" :height="170" />
    <p v-else class="muted">Unsupported widget</p>
  </section>
</template>
