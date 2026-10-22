<script setup>
import { computed, ref } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { resolveExtension } from '../extensions.js'
import { usableExtensions } from './extensionRefs.js'

// Renders every component registered for a published hook, in order, as tabs.
const props = defineProps({ hook: { type: String, required: true }, context: { type: Object, default: () => ({}) } })

const page = usePage()
const items = computed(() => usableExtensions(page.props.extensions?.[props.hook])
  .map((e) => ({ ...e, component_: resolveExtension(e.component) }))
  .filter((e) => e.component_))
const active = ref(0)
</script>

<template>
  <section v-if="items.length" class="card" style="margin-top:1rem">
    <div class="seg" role="tablist" :aria-label="hook">
      <button v-for="(e, i) in items" :key="e.module_key + e.component" type="button" role="tab"
              :aria-selected="active === i" :aria-pressed="active === i" @click="active = i">{{ e.label }}</button>
    </div>
    <component :is="items[Math.min(active, items.length - 1)].component_" v-bind="context" />
  </section>
</template>
