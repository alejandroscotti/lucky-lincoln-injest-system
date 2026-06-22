const TIME_RE = /(\d{2}):(\d{2}):(\d{2})/;

/** Extract the wall-clock `HH:MM:SS` from a MySQL/ISO timestamp without timezone math. */
function timePart(timestamp: string | null | undefined): string {
  if (!timestamp) {
    return '';
  }
  const match = TIME_RE.exec(String(timestamp));
  return match ? `${match[1]}:${match[2]}:${match[3]}` : '';
}

/** Date-only `YYYY-MM-DD` portion of a date or timestamp string. */
function datePart(value: string | null | undefined): string {
  return value ? String(value).slice(0, 10) : '';
}

/**
 * Render the business report date together with the submission time, e.g.
 * `2026-06-21 15:43:17`. The date stays anchored on `report_date` (the gaming
 * day) while the clock time comes from when the row was actually submitted, so
 * every submission carries a fully formatted timestamp. Falls back to the date
 * alone when no submission time is available.
 */
export function formatReportTimestamp(
  reportDate: string | null | undefined,
  submittedAt?: string | null,
): string {
  const date = datePart(reportDate);
  if (!date) {
    return '—';
  }
  const time = timePart(submittedAt);
  return time ? `${date} ${time}` : date;
}
