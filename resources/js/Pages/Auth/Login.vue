<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3'
import ThemeRoot from '../../shared/ThemeRoot.vue'

const page = usePage()
const form = useForm({ email: '', password: '', remember: false })

function submit() {
  form.post('/login', { onFinish: () => form.reset('password') })
}
</script>

<template>
  <Head title="Sign in" />
  <ThemeRoot>
    <main class="wrap narrow">
      <h1>Sign in</h1>
      <p class="muted">{{ page.props.tenant?.name }}</p>
      <form class="card" @submit.prevent="submit">
        <div class="field">
          <label for="email">Email</label>
          <input id="email" v-model="form.email" type="email" autocomplete="username" autofocus :aria-invalid="!!form.errors.email">
          <span v-if="form.errors.email" class="error" role="alert">{{ form.errors.email }}</span>
        </div>
        <div class="field">
          <label for="password">Password</label>
          <input id="password" v-model="form.password" type="password" autocomplete="current-password">
        </div>
        <div class="field">
          <label><input v-model="form.remember" type="checkbox" style="width:auto"> Keep me signed in</label>
        </div>
        <button class="btn" type="submit" :disabled="form.processing">Sign in</button>
      </form>
    </main>
  </ThemeRoot>
</template>
