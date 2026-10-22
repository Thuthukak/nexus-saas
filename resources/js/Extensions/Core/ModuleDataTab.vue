<script setup>
// Core's own tab on the customer profile, plugged in through the same extension point other modules use.
defineProps({ metadata: { type: Object, default: () => ({}) } })

function rows(ns) {
  return Object.entries(ns ?? {}).map(([k, v]) => [k, typeof v === 'object' && v !== null ? JSON.stringify(v) : String(v)])
}
</script>

<template>
  <div>
    <p v-if="!Object.keys(metadata).length" class="muted">No other module has stored data on this record.</p>
    <div v-for="(ns, mod) in metadata" :key="mod" style="margin-bottom:1rem">
      <h2>{{ mod }}</h2>
      <dl class="dl"><template v-for="[k, v] in rows(ns)" :key="k"><dt>{{ k }}</dt><dd>{{ v }}</dd></template></dl>
    </div>
  </div>
</template>
