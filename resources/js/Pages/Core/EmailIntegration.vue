<script setup>
import { computed, reactive, ref } from 'vue'
import { Head, router } from '@inertiajs/vue3'
import AppLayout from '../../Layouts/AppLayout.vue'
import { send } from '../../shared/http.js'

defineOptions({ layout: AppLayout })
const props = defineProps({
  settings: Object,
  canUseTenantProviders: Boolean,
  microsoftAvailable: Boolean,
  consent: String,
  replyDomain: String,
})

const form = reactive({
  email_provider: props.settings.email_provider,
  from_address: props.settings.email_provider === 'platform_shared' ? '' : props.settings.from_address ?? '',
  smtp_host: '', smtp_port: 587, smtp_username: '', smtp_password: '',
})
const busy = ref(false)
const message = ref('')
const errors = ref({})
const consentUrl = ref('')

const providers = computed(() => [
  { value: 'platform_shared', label: 'Platform shared delivery', help: 'Works immediately. Sent from the platform\'s verified domain; replies are routed back automatically.', enabled: true },
  { value: 'microsoft365', label: 'Microsoft 365', help: 'Send as your own mailboxes. Your IT administrator approves access once.', enabled: props.canUseTenantProviders && props.microsoftAvailable, note: !props.canUseTenantProviders ? 'Enterprise plan only' : !props.microsoftAvailable ? 'Not set up on this platform yet' : '' },
  { value: 'custom_smtp', label: 'Custom SMTP relay', help: 'Send through your own mail server. The connection is tested before it is saved.', enabled: props.canUseTenantProviders, note: !props.canUseTenantProviders ? 'Enterprise plan only' : '' },
])
const tenantProvider = computed(() => form.email_provider !== 'platform_shared')

const banners = {
  ok: 'Microsoft 365 is connected.',
  denied: 'Microsoft 365 access was not approved. Nothing was changed; mail still uses the platform domain.',
  failed: 'Microsoft 365 could not be connected. See the error below.',
}

async function save() {
  busy.value = true; message.value = ''; errors.value = {}; consentUrl.value = ''
  const { ok, status, data } = await send('POST', '/core/email-integration', form)
  busy.value = false
  form.smtp_password = ''
  if (ok) {
    if (data.consent_url) { consentUrl.value = data.consent_url; message.value = 'Ask your Microsoft 365 administrator to approve access using the button below.'; return }
    message.value = 'Saved.'
    router.reload({ only: ['settings'] })
  } else if (status === 422) {
    errors.value = data?.errors ?? {}
  } else if (status === 403) {
    message.value = data?.message ?? 'You do not have permission.'
  } else {
    message.value = 'Could not save. Please try again.'
  }
}
</script>

<template>
  <Head title="Email" />
  <main class="wrap narrow">
    <h1>Email delivery</h1>
    <p class="muted">How this workspace sends email.</p>

    <p v-if="consent" :role="consent === 'ok' ? 'status' : 'alert'" class="card">{{ banners[consent] }}</p>

    <section class="card" aria-label="Current status">
      <dl class="dl">
        <dt>Provider</dt><dd>{{ settings.email_provider }}</dd>
        <dt>Sender</dt><dd>{{ settings.from_address }}</dd>
        <dt>Status</dt><dd>{{ settings.status }}<span v-if="settings.effective_provider !== settings.email_provider"> (mail is using the platform domain meanwhile)</span></dd>
      </dl>
      <p v-if="settings.last_error" role="alert" class="error">{{ settings.last_error }}</p>
    </section>

    <form class="card" novalidate style="margin-top:1rem" @submit.prevent="save">
      <fieldset class="field" style="border:0;padding:0">
        <legend>Provider</legend>
        <label v-for="p in providers" :key="p.value" class="row" style="align-items:start;margin-bottom:.6rem">
          <input v-model="form.email_provider" type="radio" name="provider" :value="p.value" :disabled="!p.enabled" style="width:auto;margin-top:.3rem">
          <span><strong>{{ p.label }}</strong> <span v-if="p.note" class="muted">· {{ p.note }}</span><br><span class="hint">{{ p.help }}</span></span>
        </label>
        <span v-if="errors.email_provider" class="error">{{ errors.email_provider[0] }}</span>
      </fieldset>

      <template v-if="tenantProvider">
        <div class="field">
          <label for="from_address">Sender address</label>
          <input id="from_address" v-model="form.from_address" type="email" autocomplete="off" :aria-invalid="!!errors.from_address">
          <span v-if="errors.from_address" class="error">{{ errors.from_address[0] }}</span>
        </div>
      </template>

      <template v-if="form.email_provider === 'custom_smtp'">
        <div class="field"><label for="smtp_host">SMTP host</label><input id="smtp_host" v-model="form.smtp_host" autocomplete="off" :aria-invalid="!!errors.smtp_host"><span v-if="errors.smtp_host" class="error">{{ errors.smtp_host[0] }}</span></div>
        <div class="field"><label for="smtp_port">Port</label>
          <select id="smtp_port" v-model.number="form.smtp_port"><option :value="587">587 (STARTTLS)</option><option :value="465">465 (TLS)</option><option :value="2525">2525</option><option :value="25">25</option></select>
          <span v-if="errors.smtp_port" class="error">{{ errors.smtp_port[0] }}</span></div>
        <div class="field"><label for="smtp_username">Username</label><input id="smtp_username" v-model="form.smtp_username" autocomplete="off" :aria-invalid="!!errors.smtp_username"><span v-if="errors.smtp_username" class="error">{{ errors.smtp_username[0] }}</span></div>
        <div class="field"><label for="smtp_password">Password</label><input id="smtp_password" v-model="form.smtp_password" type="password" autocomplete="new-password" :aria-invalid="!!errors.smtp_password"><span v-if="errors.smtp_password" class="error">{{ errors.smtp_password[0] }}</span></div>
        <p class="hint">The password is stored encrypted and is never shown again.</p>
      </template>

      <p v-if="message" role="status">{{ message }}</p>
      <p v-if="consentUrl"><a class="btn" :href="consentUrl" rel="noopener">Approve in Microsoft 365</a></p>
      <button class="btn" :disabled="busy">{{ busy ? 'Saving…' : form.email_provider === 'custom_smtp' ? 'Test and save' : form.email_provider === 'microsoft365' ? 'Continue to Microsoft' : 'Save' }}</button>
    </form>

    <p class="hint" style="margin-top:1rem">Replies to platform-delivered mail return through <span class="addr">{{ replyDomain }}</span>.</p>
  </main>
</template>
