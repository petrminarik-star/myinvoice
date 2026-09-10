import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import test from 'node:test'

const root = new URL('../src/', import.meta.url)

// Boxy, které editují jeden objekt dodavatele: nadpis sekce → popisek jejího tlačítka.
// Všechna volají stejné saveSupplier(), ale každý box musí mít vlastní — jedno
// tlačítko schované na konci poslední sekce uživatel u číselné řady nenajde.
const SUPPLIER_SECTIONS = [
  { heading: 'settings.supplier', button: 'settings.save_supplier' },
  { heading: 'settings.numbering_section', button: 'settings.save_numbering' },
  { heading: 'settings.tax_section', button: 'settings.save_tax' },
  { heading: 'settings.pohoda_section', button: 'settings.save_pohoda' },
]

/** Úsek šablony od nadpisu sekce po její uzavírací značku. */
function sectionBody(source, headingKey) {
  const start = source.indexOf(`t('${headingKey}')`)
  assert.notEqual(start, -1, `sekce ${headingKey} v Settings.vue chybí`)
  const end = source.indexOf('</section>', start)
  assert.notEqual(end, -1, `sekce ${headingKey} nemá uzavírací </section>`)
  return source.slice(start, end)
}

test('every supplier settings section has its own save button', async () => {
  const settings = await readFile(new URL('pages/admin/Settings.vue', root), 'utf8')

  for (const { heading, button } of SUPPLIER_SECTIONS) {
    const body = sectionBody(settings, heading)
    assert.match(body, /@click="saveSupplier"/, `sekce ${heading} nemá tlačítko volající saveSupplier()`)
    assert.match(body, new RegExp(`t\\('${button}'\\)`), `tlačítko v sekci ${heading} nemá popisek ${button}`)
  }
})

test('save button labels name their own section', async () => {
  const settings = await readFile(new URL('pages/admin/Settings.vue', root), 'utf8')
  const labels = SUPPLIER_SECTIONS.map(s => s.button)

  assert.equal(new Set(labels).size, labels.length, 'popisky tlačítek se opakují mezi sekcemi')
  for (const label of labels) {
    const uses = settings.match(new RegExp(`t\\('${label}'\\)`, 'g')) ?? []
    assert.equal(uses.length, 1, `popisek ${label} je použitý ${uses.length}× — patří jedné sekci`)
  }
})

test('save button labels are translated in both locales', async () => {
  const [cs, en] = await Promise.all([
    readFile(new URL('i18n/cs.json', root), 'utf8').then(JSON.parse),
    readFile(new URL('i18n/en.json', root), 'utf8').then(JSON.parse),
  ])

  for (const { button } of SUPPLIER_SECTIONS) {
    const key = button.replace('settings.', '')
    assert.equal(typeof cs.settings[key], 'string', `cs.json postrádá settings.${key}`)
    assert.equal(typeof en.settings[key], 'string', `en.json postrádá settings.${key}`)
  }
})
