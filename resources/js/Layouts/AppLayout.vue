<script setup>
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { Link, router, usePage } from '@inertiajs/vue3'
import ThemeRoot from '../shared/ThemeRoot.vue'
import { send } from '../shared/http.js'
import { newToasts } from '../shared/notifications.js'
import { term } from '../shared/terms.js'

const page = usePage()
const can = (p) => (page.props.auth?.permissions ?? []).some((x) => x === '*' || x === p)
const canSettings = computed(() => can('core.settings.manage'))
const partyPlural = computed(() => term(page.props.terms, 'Party', { plural: true }))

const unread = ref(0)
const items = ref([])
const open = ref(false)
const toasts = reactive([])
const seen = new Set()
const loadedAt = Date.now() - 1000
let timer = null

async function poll() {
  if (document.hidden) return
  const { ok, data } = await send('GET', '/core/notifications')
  if (!ok || !data) return
  unread.value = data.unread_count
  items.value = data.notifications
  for (const n of newToasts(data.notifications, seen, loadedAt)) {
    seen.add(n.id)
    toasts.push(n)
    setTimeout(() => dismiss(n.id), 8000)
  }
}
function dismiss(id) {
  const i = toasts.findIndex((t) => t.id === id)
  if (i >= 0) toasts.splice(i, 1)
}
async function markAll() {
  await send('POST', '/core/notifications/read-all')
  await poll()
}
onMounted(() => { poll(); timer = setInterval(poll, 30000) })
onBeforeUnmount(() => clearInterval(timer))
</script>

<template>
  <ThemeRoot>
    <header class="topbar">
      <span>
        <Link href="/dashboard"><strong>{{ page.props.tenant?.name }}</strong></Link>
        <Link href="/dashboard" class="navlink">Dashboard</Link>
        <Link href="/core/customers" class="navlink">{{ partyPlural }}</Link>
        <Link href="/reports" class="navlink">Reports</Link>
        <template v-if="canSettings">
          <Link href="/core/organisation" class="navlink">Organisation</Link>
          <Link href="/core/theme" class="navlink">Theme</Link>
          <Link href="/core/terminology" class="navlink">Terminology</Link>
          <Link href="/core/email-integration" class="navlink">Email</Link>
        </template>
      </span>
      <span class="right">
        <span class="bell">
          <button class="btn ghost" :aria-expanded="open" aria-label="Notifications" @click="open = !open">
            Notifications<span v-if="unread" class="badge">{{ unread }}</span>
          </button>
          <div v-if="open" class="card pop" role="region" aria-label="Notifications">
            <p v-if="!items.length" class="muted">Nothing yet.</p>
            <ul class="list">
              <li v-for="n in items" :key="n.id" :class="{ unread: !n.read_at }">
                <strong>{{ n.title }}</strong>
                <div v-if="n.body" class="hint">{{ n.body }}</div>
              </li>
            </ul>
            <button v-if="unread" class="btn ghost" @click="markAll">Mark all read</button>
          </div>
        </span>
        <span class="muted">{{ page.props.auth.user?.name }}</span>
        <button class="btn ghost" @click="router.post('/logout')">Sign out</button>
      </span>
    </header>

    <div class="toasts" aria-live="polite">
      <div v-for="t in toasts" :key="t.id" class="toast" role="status">
        <strong>{{ t.title }}</strong>
        <button class="x" aria-label="Dismiss" @click="dismiss(t.id)">×</button>
      </div>
    </div>

    <slot />
  </ThemeRoot>
</template>
