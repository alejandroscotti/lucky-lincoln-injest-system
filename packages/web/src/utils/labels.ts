export function faultLabel(type: string, labels?: Record<string, string>): string {
  if (labels?.[type]) return labels[type];
  return type.replace(/_/g, ' ');
}

export function cleanGameName(name: string): string {
  return name.replace(/\*\*/g, '').replace(/_/g, ' ').trim();
}

export function formatCurrency(n: number | undefined | null, compact = false): string {
  const v = Number(n || 0);
  if (compact && Math.abs(v) >= 1_000_000) {
    return `$${(v / 1_000_000).toFixed(1)}M`;
  }
  if (compact && Math.abs(v) >= 1_000) {
    return `$${(v / 1_000).toFixed(1)}K`;
  }
  return v.toLocaleString('en-US', { style: 'currency', currency: 'USD', maximumFractionDigits: 0 });
}

export function formatCount(n: number | undefined | null): string {
  return Number(n || 0).toLocaleString('en-US');
}

export function truncate(text: string, max = 28): string {
  if (text.length <= max) return text;
  return `${text.slice(0, max - 1)}…`;
}
