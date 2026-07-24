<script setup lang="ts">
import { ref, watch } from 'vue'

// Náhrada za <input type="date">: nativní date input zobrazuje datum podle
// jazyka PROHLÍŽEČE (anglický prohlížeč → MM/DD/YYYY), tenhle vždy DD.MM.RRRR.
// v-model zůstává ISO (YYYY-MM-DD), stejně jako u nativního inputu, který
// nahrazuje — pro volající kód je to drop-in náhrada.
defineOptions({ inheritAttrs: false })

const props = defineProps<{ modelValue?: string | null }>()
const emit = defineEmits<{
  (e: 'update:modelValue', value: string): void
  (e: 'change', value: string): void
}>()

const pickerEl = ref<HTMLInputElement | null>(null)
const text = ref(isoToCz(props.modelValue ?? ''))

watch(() => props.modelValue, (v) => { text.value = isoToCz(v ?? '') })

function isoToCz(iso: string): string {
  const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(iso)
  return m ? `${m[3]}.${m[2]}.${m[1]}` : ''
}

function czToIso(input: string): string | null {
  const s = input.trim()
  if (!s) return ''
  let d: number, mo: number, y: number
  let m = /^(\d{1,2})\.\s*(\d{1,2})\.\s*(\d{4}|\d{2})$/.exec(s)
  if (m) {
    d = +m[1]; mo = +m[2]; y = m[3].length === 2 ? 2000 + +m[3] : +m[3]
  } else if ((m = /^(\d{1,2})\.\s*(\d{1,2})\.?$/.exec(s))) {
    // „24.7." bez roku → letošní rok
    d = +m[1]; mo = +m[2]; y = new Date().getFullYear()
  } else if ((m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(s))) {
    y = +m[1]; mo = +m[2]; d = +m[3]
  } else {
    return null
  }
  const dt = new Date(Date.UTC(y, mo - 1, d))
  if (dt.getUTCFullYear() !== y || dt.getUTCMonth() !== mo - 1 || dt.getUTCDate() !== d) return null
  return `${y}-${String(mo).padStart(2, '0')}-${String(d).padStart(2, '0')}`
}

function commit(iso: string) {
  text.value = isoToCz(iso)
  if (iso !== (props.modelValue ?? '')) {
    emit('update:modelValue', iso)
    emit('change', iso)
  }
}

function commitText() {
  const iso = czToIso(text.value)
  if (iso === null) {
    // neplatný zápis → vrátit poslední platnou hodnotu
    text.value = isoToCz(props.modelValue ?? '')
    return
  }
  commit(iso)
}

function openPicker() {
  const el = pickerEl.value
  if (!el) return
  try { el.showPicker() } catch { el.focus() }
}
</script>

<template>
  <div class="relative">
    <input
      v-model="text"
      type="text"
      inputmode="numeric"
      autocomplete="off"
      placeholder="DD.MM.RRRR"
      v-bind="$attrs"
      @blur="commitText"
      @keydown.enter="commitText"
    />
    <button type="button" tabindex="-1" aria-label="Otevřít kalendář" @click="openPicker"
            class="absolute right-2 top-1/2 -translate-y-1/2 cursor-pointer text-neutral-400 hover:text-neutral-600">
      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <rect x="3" y="4" width="18" height="17" rx="2" />
        <path stroke-linecap="round" d="M8 2.5v3.5M16 2.5v3.5M3 9.5h18" />
      </svg>
    </button>
    <input
      ref="pickerEl"
      :value="modelValue ?? ''"
      type="date"
      tabindex="-1"
      aria-hidden="true"
      class="absolute right-0 bottom-0 w-px h-px opacity-0 pointer-events-none"
      @change="commit(($event.target as HTMLInputElement).value)"
    />
  </div>
</template>
