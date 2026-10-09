/**
 * Keyboard-wedge badge readers type the id as fast keystrokes followed by Enter. We listen on the whole document
 * (no input field needs focus, so the first characters are not lost and tablets show no on-screen keyboard) and
 * treat a burst of quick keys ending in Enter as a scan. People type much slower than readers, so normal typing in
 * a field is left alone.
 */
export interface BadgeReaderOptions {
  /** maximum pause between two keys of a scan, ms */
  maxGap?: number
  /** minimum characters of a scan */
  minLength?: number
  /** also listen while the focus is in a text field (kiosk: yes, register: no) */
  inFields?: boolean
}

export function listenForBadges(onScan: (tag: string) => void, options: BadgeReaderOptions = {}): () => void {
  const maxGap = options.maxGap ?? 60
  const minLength = options.minLength ?? 4
  let buffer = ''
  let last = 0

  const editable = (target: EventTarget | null): boolean => {
    const el = target as HTMLElement | null
    return !!el && (el.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(el.tagName))
  }

  const handler = (event: KeyboardEvent) => {
    if (!options.inFields && editable(event.target)) return
    const now = performance.now()
    if (now - last > maxGap) buffer = ''
    last = now
    if (event.key === 'Enter') {
      if (buffer.length >= minLength) {
        event.preventDefault()
        const tag = buffer
        buffer = ''
        onScan(tag)
      }
      buffer = ''
      return
    }
    if (event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey) {
      buffer += event.key
    }
  }

  document.addEventListener('keydown', handler, true)
  return () => document.removeEventListener('keydown', handler, true)
}
