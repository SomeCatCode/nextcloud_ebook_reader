import { createPinia } from 'pinia'
import { createApp } from 'vue'
import App from './App.vue'
import { router } from './router.ts'

/**
 * SPDX-FileCopyrightText: 2026 Felix Kurth
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
import '@nextcloud/dialogs/style.css'

const app = createApp(App)
app.use(createPinia())
app.use(router)
app.mount('#ebookreader-app')
