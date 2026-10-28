<script setup>
import { Link, router, usePage } from '@inertiajs/vue3'

const page = usePage()
function logout() { router.post('/logout') }
</script>

<template>
  <div class="theme-root">
    <header class="topbar">
      <span><Link href="/tenants"><strong>Butter control plane</strong></Link></span>
      <span v-if="page.props.operator" class="right">
        <span class="muted">{{ page.props.operator.name }}</span>
        <button class="btn" type="button" @click="logout">Sign out</button>
      </span>
    </header>
    <main class="wrap">
      <p v-if="page.props.flash?.status" role="status" class="hint">{{ page.props.flash.status }}</p>
      <slot />
    </main>
  </div>
</template>
