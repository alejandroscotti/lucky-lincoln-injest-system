export function appendColumnFilters(params: URLSearchParams, col: Record<string, string>) {
  for (const [key, value] of Object.entries(col)) {
    const trimmed = value.trim();
    if (trimmed) params.set(`filter_${key}`, trimmed);
  }
}
