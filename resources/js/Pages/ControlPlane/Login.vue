<script setup>
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '' })
function submit() { form.post('/login', { onFinish: () => form.reset('password') }) }
</script>

<template>
  <Head title="Operator sign in" />
  <div class="theme-root">
    <main class="wrap narrow">
      <h1>Operator sign in</h1>
      <p class="muted">Platform staff only</p>
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
        <button class="btn" type="submit" :disabled="form.processing">Sign in</button>
      </form>
    </main>
  </div>
</template>
