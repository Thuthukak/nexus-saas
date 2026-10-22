<script setup>
import { computed } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'
import AppLayout from '../Layouts/AppLayout.vue'
import DashboardWidget from '../shared/DashboardWidget.vue'

defineOptions({ layout: AppLayout })
const props = defineProps({ modules: Array, widgets: { type: Array, default: () => [] } })
const page = usePage()

// Each widget declares its own grid position on a 12-column grid; the server has already resolved overlaps.
const placed = computed(() => props.widgets.map((w) => ({
  w,
  style: { gridColumn: `${w.grid.x + 1} / span ${w.grid.w}`, gridRow: `${w.grid.y + 1} / span ${w.grid.h}` },
})))
</script>

<template>
  <Head title="Dashboard" />
  <main class="wrap">
    <h1>Welcome, {{ page.props.auth.user?.name }}</h1>
    <p v-if="!widgets.length" class="muted">Nothing to show yet. Widgets appear as modules are enabled.</p>
    <div class="dash">
      <div v-for="p in placed" :key="p.w.id" class="dash-cell" :style="p.style">
        <DashboardWidget :widget="p.w" />
      </div>
    </div>
  </main>
</template>
