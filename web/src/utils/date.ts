/** Kalendář aplikace odpovídá výchozímu app.timezone na backendu. */
export const APP_TIME_ZONE = 'Europe/Prague'

const appDateParts = new Intl.DateTimeFormat('en-CA', {
  timeZone: APP_TIME_ZONE,
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
})

export function appIsoDate(date: Date = new Date()): string {
  const parts = appDateParts.formatToParts(date)
  const value = (type: Intl.DateTimeFormatPartTypes) =>
    parts.find(part => part.type === type)?.value ?? ''
  return `${value('year')}-${value('month')}-${value('day')}`
}

/** Celé kalendářní dny po splatnosti, nezávisle na změnách letního času. */
export function overdueDays(dueDate: string, now: Date = new Date()): number {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(dueDate)) return 0
  const days = (Date.parse(appIsoDate(now)) - Date.parse(dueDate)) / 86_400_000
  return Number.isFinite(days) ? Math.max(0, days) : 0
}
