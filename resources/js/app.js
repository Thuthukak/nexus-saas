import '../css/app.css'
import { createApp, h } from 'vue'
import { createInertiaApp } from '@inertiajs/vue3'

const pages = import.meta.glob('./Pages/**/*.vue', { eager: false })

createInertiaApp({
  title: (title) => (title ? `${title} · Butter` : 'Butter'),
  resolve: (name) => {
    const page = pages[`./Pages/${name}.vue`]
    if (!page) throw new Error(`Unknown page: ${name}`)
    return page()
  },
  setup({ el, App, props, plugin }) {
    createApp({ render: () => h(App, props) }).use(plugin).mount(el)
  },
  progress: { color: '#b45309' },
})
