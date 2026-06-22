import type { ChartOptions } from 'chart.js';
import { faultLabel, cleanGameName, formatCurrency, truncate, formatCount } from './labels';

export const chartFont = { family: "'Segoe UI', system-ui, sans-serif", size: 12 };
export const chartColor = { text: '#ffffff', grid: '#2d3a4f' };

export const baseChartOptions: ChartOptions<'line'> = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      backgroundColor: '#1a2332',
      borderColor: '#2d3a4f',
      borderWidth: 1,
      titleFont: chartFont,
      bodyFont: chartFont,
      padding: 10,
    },
  },
  scales: {
    x: {
      ticks: { color: chartColor.text, font: chartFont, maxRotation: 45, minRotation: 0 },
      grid: { color: chartColor.grid },
    },
    y: {
      ticks: {
        color: chartColor.text,
        font: chartFont,
        callback: (v) => formatCurrency(Number(v), true),
      },
      grid: { color: chartColor.grid },
    },
  },
};

export function currencyTooltip() {
  return {
    callbacks: {
      label: (ctx: { parsed: { y?: number | null; x?: number | null }; dataset: { label?: string } }) => {
        const val = ctx.parsed.y ?? ctx.parsed.x ?? 0;
        return `${ctx.dataset.label || ''}: ${formatCurrency(val)}`;
      },
    },
  };
}

export function shortfallBarOptions(
  rows: { location_id: string; location_name?: string; shortfall?: number }[]
): ChartOptions<'bar'> {
  return {
    ...baseChartOptions,
    indexAxis: 'x',
    plugins: {
      ...baseChartOptions.plugins,
      tooltip: {
        ...baseChartOptions.plugins?.tooltip,
        callbacks: {
          title: (items) => {
            const i = items[0]?.dataIndex ?? 0;
            const row = rows[i];
            return row ? `${row.location_id} — ${row.location_name || ''}` : '';
          },
          label: (ctx) => `Shortfall: ${formatCurrency(ctx.parsed.y)}`,
        },
      },
    },
  } as ChartOptions<'bar'>;
}

export function faultDoughnutOptions(): ChartOptions<'doughnut'> {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: {
        display: true,
        position: 'right',
        labels: {
          color: chartColor.text,
          font: chartFont,
          padding: 14,
          boxWidth: 14,
          boxHeight: 14,
        },
      },
      tooltip: {
        backgroundColor: '#1a2332',
        borderColor: '#2d3a4f',
        borderWidth: 1,
        callbacks: {
          label: (ctx) => {
            const label = ctx.label || '';
            const val = Number(ctx.parsed);
            const total = (ctx.dataset.data as number[]).reduce((a, b) => a + b, 0);
            const pct = total ? ((val / total) * 100).toFixed(1) : '0';
            return `${label}: ${val.toLocaleString()} (${pct}%)`;
          },
        },
      },
    },
  };
}

export function gameBarOptions(
  games: { game_name: string; net_revenue: number }[]
): ChartOptions<'bar'> {
  return {
    ...baseChartOptions,
    indexAxis: 'y',
    plugins: {
      ...baseChartOptions.plugins,
      tooltip: {
        ...baseChartOptions.plugins?.tooltip,
        callbacks: {
          title: (items) => {
            const i = items[0]?.dataIndex ?? 0;
            return cleanGameName(games[i]?.game_name || '');
          },
          label: (ctx) => `Revenue: ${formatCurrency(ctx.parsed.x)}`,
        },
      },
    },
    scales: {
      x: baseChartOptions.scales?.x,
      y: {
        ticks: {
          color: chartColor.text,
          font: chartFont,
          autoSkip: false,
          callback: (_v, i) => truncate(cleanGameName(games[i]?.game_name || ''), 36),
        },
        grid: { display: false },
      },
    },
  } as ChartOptions<'bar'>;
}

export function tierBarOptions(): ChartOptions<'bar'> {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        backgroundColor: '#1a2332',
        borderColor: '#2d3a4f',
        borderWidth: 1,
        callbacks: {
          label: (ctx) => `Faulty transactions: ${formatCount(ctx.parsed.y)}`,
        },
      },
    },
    scales: {
      x: {
        ticks: { color: chartColor.text, font: chartFont },
        grid: { color: chartColor.grid },
      },
      y: {
        ticks: { color: chartColor.text, font: chartFont, precision: 0 },
        grid: { color: chartColor.grid },
      },
    },
  };
}

const FAULT_COLORS = [
  '#e74c5c', '#4a9eff', '#f0a500', '#3dd68c', '#9b59b6',
  '#e67e22', '#1abc9c', '#ff6b81', '#95a5a6',
];

export function faultColor(i: number) {
  return FAULT_COLORS[i % FAULT_COLORS.length];
}
