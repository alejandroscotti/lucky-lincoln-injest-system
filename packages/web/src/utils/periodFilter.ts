export type PeriodType = 'day' | 'month' | 'quarter' | 'year' | 'all';

export interface PeriodState {
  type: PeriodType;
  day: string;
  month: string;
  quarter: 1 | 2 | 3 | 4;
  year: number;
}

export interface DateRange {
  from?: string;
  to?: string;
}

function pad(n: number) {
  return String(n).padStart(2, '0');
}

function daysInMonth(year: number, month: number) {
  return new Date(year, month, 0).getDate();
}

function currentQuarter(): 1 | 2 | 3 | 4 {
  return (Math.floor(new Date().getMonth() / 3) + 1) as 1 | 2 | 3 | 4;
}

export function defaultPeriodState(): PeriodState {
  const now = new Date();
  const year = now.getFullYear();
  const month = now.getMonth() + 1;
  return {
    type: 'all',
    day: `${year}-${pad(month)}-${pad(now.getDate())}`,
    month: `${year}-${pad(month)}`,
    quarter: currentQuarter(),
    year,
  };
}

export function periodRange(state: PeriodState): DateRange {
  if (state.type === 'all') return {};

  if (state.type === 'day') {
    return { from: state.day, to: state.day };
  }

  if (state.type === 'month') {
    const [y, m] = state.month.split('-').map(Number);
    const last = daysInMonth(y, m);
    return { from: `${y}-${pad(m)}-01`, to: `${y}-${pad(m)}-${pad(last)}` };
  }

  if (state.type === 'quarter') {
    const startMonth = (state.quarter - 1) * 3 + 1;
    const endMonth = startMonth + 2;
    const last = daysInMonth(state.year, endMonth);
    return {
      from: `${state.year}-${pad(startMonth)}-01`,
      to: `${state.year}-${pad(endMonth)}-${pad(last)}`,
    };
  }

  return { from: `${state.year}-01-01`, to: `${state.year}-12-31` };
}

export function periodLabel(state: PeriodState): string {
  if (state.type === 'all') return 'All time';

  const range = periodRange(state);
  if (state.type === 'day') return state.day;
  if (state.type === 'month') {
    const [y, m] = state.month.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString(undefined, { month: 'long', year: 'numeric' });
  }
  if (state.type === 'quarter') return `Q${state.quarter} ${state.year}`;
  if (state.type === 'year') return String(state.year);
  return `${range.from} – ${range.to}`;
}

export function appendPeriodParams(params: URLSearchParams, range: DateRange) {
  if (range.from) params.set('from', range.from);
  if (range.to) params.set('to', range.to);
}
