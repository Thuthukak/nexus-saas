<script setup>
import { computed } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { themeStyle } from './colour.js'

// The theme is bound to the root node through Vue reactivity (usePage() is reactive), not written
// to the document as a side effect: updating the palette re-renders the style instantly, and
// there is no flash of stale colours when switching tabs.
const page = usePage()
const style = computed(() => themeStyle(page.props.theme))
</script>

<template>
  <div class="theme-root" :style="style"><slot /></div>
</template>
