/*
 * Bootstrap views of the Quick POS blocks (js/dist/quick-pos.js): mounts every
 * <div data-qpos="kiosk|register"><script type="application/json">{config}</script></div> on the page.
 */
import { createApp, type Component } from 'vue'
import KioskApp from './KioskApp.vue'
import RegisterApp from './RegisterApp.vue'

const apps: Record<string, Component> = { kiosk: KioskApp, register: RegisterApp }

function start(): void {
  document.querySelectorAll<HTMLElement>('[data-qpos]:not([data-qpos-mounted])').forEach((el) => {
    const app = apps[el.dataset.qpos ?? '']
    const json = el.querySelector(':scope > script[type="application/json"]')
    if (!app || !json?.textContent) return
    el.setAttribute('data-qpos-mounted', '')
    createApp(app, { config: JSON.parse(json.textContent) }).mount(el)
  })
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', start)
} else {
  start()
}
