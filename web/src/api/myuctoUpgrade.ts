import { api } from './client'

/**
 * Přechod na MyÚčto.cz — nástupce MyInvoice od stejného autora.
 *
 * Vlastní endpointy, ne varianta `/admin/update/*`: aktualizace MyInvoice
 * a výměna produktu za nástupce mají jiný dopad i jiný stav, a míchat je
 * dohromady by znamenalo, že běžící jedno vypadá jako běžící druhé.
 */

export interface MyuctoBackupInfo {
  exists: boolean
  newest_at: string | null
  newest_file: string | null
  /** Záloha ne starší než 24 h. */
  fresh: boolean
}

export interface MyuctoUpgradeProgress {
  step: string
  step_index: number
  step_count: number
  step_message: string
}

export interface MyuctoUpgradeResult {
  status: 'applied' | 'failed' | 'unknown' | string
  target_version?: string
  applied_at?: string
  message?: string
  log_path?: string
  backup_path?: string
}

export interface MyuctoUpgradeStatus {
  /** Verze MyInvoice, ze které se přechází. */
  current_version: string
  /** Poslední verze MyÚčta z cache (null = ještě neproběhla kontrola). */
  myucto_version: string | null
  release_notes_md: string | null
  release_url: string | null
  published_at: string | null
  last_check_at: string | null
  last_check_error: string | null
  cache_stale: boolean
  environment: 'docker' | 'native'
  in_progress: boolean
  progress: MyuctoUpgradeProgress | null
  last_result: MyuctoUpgradeResult | null
  backup: MyuctoBackupInfo
  /** Kroky pipeline v pořadí — UI je zobrazuje jako progress. */
  steps: string[]
}

/** Jedna kontrola prostředí — vrací se i ta, která dopadla dobře. */
export interface MyuctoUpgradeCheck {
  id: string
  /** Co se ověřovalo; text chodí česky ze serveru, stejně jako blockery. */
  label: string
  status: 'ok' | 'fail'
  /** Naměřená hodnota. */
  actual: string
  /** Co se od ní čeká. */
  expected: string
}

export interface MyuctoUpgradePreflight {
  ok: boolean
  supported: boolean
  blockers: string[]
  warnings: string[]
  checks: MyuctoUpgradeCheck[]
}

export interface MyuctoUpgradeTriggerResponse {
  status: 'queued' | 'manual_required' | 'error' | string
  environment?: 'docker' | 'native'
  target_version?: string
  message?: string
  instructions?: string[]
  blockers?: string[]
  warnings?: string[]
}

export const myuctoUpgradeApi = {
  status: (signal?: AbortSignal) =>
    api.get<MyuctoUpgradeStatus>('/admin/myucto-upgrade/status', { signal }).then((r) => r.data),

  /** Vynucený fresh fetch verze MyÚčta z GitHub Releases. */
  refresh: () =>
    api.post<MyuctoUpgradeStatus>('/admin/myucto-upgrade/refresh').then((r) => r.data),

  /** Zvládne tahle instalace přechod sama? (zapisuje probe soubory kvůli právům) */
  preflight: (target_version?: string, signal?: AbortSignal) =>
    api
      .get<MyuctoUpgradePreflight>('/admin/myucto-upgrade/preflight', {
        params: target_version ? { target_version } : undefined,
        signal,
      })
      .then((r) => r.data),

  /**
   * Spustit přechod. `backup_confirmed` není formalita — bez něj server
   * odmítne, protože zpátky vede jedině obnova zálohy.
   */
  trigger: (backup_confirmed: boolean, target_version?: string) =>
    api
      .post<MyuctoUpgradeTriggerResponse>('/admin/myucto-upgrade/trigger', {
        target_version: target_version ?? null,
        backup_confirmed,
      })
      .then((r) => r.data),

  /** Zrušit zaseknutý „přechod probíhá" flag. */
  cancel: () =>
    api.post<{ status: string; cleared: boolean }>('/admin/myucto-upgrade/cancel').then((r) => r.data),
}
