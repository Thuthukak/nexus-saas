<script setup>
import { computed } from 'vue'
import { buildChart, formatValue } from './chart.js'

// Palette uses the tenant accent first, then fixed, distinguishable hues. Series are also identified by a direct legend, never by colour alone.
const COLOURS = ['var(--accent)', '#b45309', '#0f766e', '#7c3aed', '#be123c', '#4d7c0f']

const props = defineProps({
  title: { type: String, required: true },
  series: { type: Array, required: true }, // [{ key, unit?, currency?, points:[{date,value}] }]
  height: { type: Number, default: 220 },
})

const chart = computed(() => buildChart(props.series, { height: props.height }))
const first = computed(() => props.series[0] ?? {})
const fmt = (v, s = first.value) => formatValue(v, s.unit ?? 'count', s.currency ?? null)
const dates = computed(() => [...new Set(props.series.flatMap((s) => s.points.map((p) => p.date)))].sort())
const lookup = (s, date) => s.points.find((p) => p.date === date)?.value
</script>

<template>
  <figure class="chart">
    <figcaption class="muted">{{ title }}</figcaption>
    <p v-if="!chart.lines.length" class="muted">No data in this range yet.</p>
    <template v-else>
      <svg :viewBox="`0 0 600 ${height}`" role="img" :aria-label="`${title}. Data table below.`" preserveAspectRatio="xMidYMid meet">
        <g v-for="t in chart.yTicks" :key="t.y">
          <line x1="44" x2="588" :y1="t.y" :y2="t.y" stroke="var(--line)" />
          <text x="40" :y="t.y + 4" text-anchor="end" class="tick">{{ fmt(t.value) }}</text>
        </g>
        <text v-for="t in chart.xTicks" :key="t.date" :x="t.x" :y="height - 6" text-anchor="middle" class="tick">{{ t.date.slice(5) }}</text>
        <g v-for="(l, i) in chart.lines" :key="l.key">
          <path :d="l.d" fill="none" :stroke="COLOURS[i % COLOURS.length]" stroke-width="2" :stroke-dasharray="i % 2 ? '5 3' : null" />
          <circle v-for="p in l.dots" :key="p.date" :cx="p.x" :cy="p.y" r="3" :fill="COLOURS[i % COLOURS.length]"><title>{{ p.date }}: {{ fmt(p.value, series[i]) }}</title></circle>
        </g>
      </svg>
      <ul class="legend">
        <li v-for="(s, i) in series" :key="s.key"><span class="key" :style="{ background: COLOURS[i % COLOURS.length] }" aria-hidden="true" /> {{ s.key }}</li>
      </ul>
      <details>
        <summary>View as table</summary>
        <table class="table">
          <thead><tr><th>Date</th><th v-for="s in series" :key="s.key">{{ s.key }}</th></tr></thead>
          <tbody>
            <tr v-for="d in dates" :key="d"><td>{{ d }}</td><td v-for="s in series" :key="s.key">{{ lookup(s, d) === undefined ? '—' : fmt(lookup(s, d), s) }}</td></tr>
          </tbody>
        </table>
      </details>
    </template>
  </figure>
</template>
