<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Line, Bar, Doughnut } from 'vue-chartjs';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  BarElement,
  ArcElement,
  Title,
  Tooltip,
  Legend,
  Filler,
} from 'chart.js';
import LocationPicker from '../components/LocationPicker.vue';
import { onDashboard, refreshNow } from '../composables/useLiveStream';
import { usePeriodFilter } from '../composables/usePeriodFilter';
import PeriodFilter from '../components/PeriodFilter.vue';
import {
  baseChartOptions,
  currencyTooltip,
  faultColor,
  faultDoughnutOptions,
  gameBarOptions,
  shortfallBarOptions,
  tierBarOptions,
} from '../utils/chartTheme';
import { useAppStore } from '../stores/app';
import { cleanGameName, faultLabel, formatCount, formatCurrency } from '../utils/labels';

ChartJS.register(
  CategoryScale, LinearScale, PointElement, LineElement, BarElement,
  ArcElement, Title, Tooltip, Legend, Filler,
);

const store = useAppStore();

const { isActive, label: periodLabel, applyToParams, locationId } = usePeriodFilter();

interface LocationOption {
  location_id: string;
  location_name: string;
}

const kpis = ref<Record<string, number>>({});
const byDate = ref<{ report_date: string; net_revenue: number }[]>([]);
const topShortfalls = ref<{ location_id: string; location_name: string; shortfall: number }[]>([]);
const faultStats = ref<{ fault_type: string; count: number }[]>([]);
const faultByTier = ref<{ severity: string; count: number }[]>([]);
const byGame = ref<{ game_name: string; net_revenue: number }[]>([]);
const locations = ref<LocationOption[]>([]);
const loading = ref(true);

async function applyDashboard(data: Record<string, unknown>) {
  kpis.value = (data.kpis || {}) as Record<string, number>;
  byDate.value = (data.by_date || []) as { report_date: string; net_revenue: number }[];
  topShortfalls.value = ((data.top_shortfalls || []) as Record<string, unknown>[]).map((s) => ({
    location_id: String(s.location_id),
    location_name: String(s.location_name || ''),
    shortfall: Number(s.shortfall),
  }));
  faultStats.value = (data.fault_stats || []) as { fault_type: string; count: number }[];
  faultByTier.value = (data.fault_by_tier || []) as { severity: string; count: number }[];
  byGame.value = ((data.by_game || []) as Record<string, unknown>[]).map((g) => ({
    game_name: cleanGameName(String(g.game_name)),
    net_revenue: Number(g.net_revenue),
  }));
}

async function load() {
  refreshingManual.value = true;
  try {
    await refreshNow();
  } finally {
    refreshingManual.value = false;
    loading.value = false;
  }
}

const refreshingManual = ref(false);

const lineData = computed(() => ({
  labels: byDate.value.map((d) => d.report_date),
  datasets: [{
    label: 'Net revenue',
    data: byDate.value.map((d) => d.net_revenue),
    borderColor: '#4a9eff',
    backgroundColor: 'rgba(74, 158, 255, 0.12)',
    fill: true,
    tension: 0.25,
    pointRadius: 2,
    pointHoverRadius: 5,
  }],
}));

const lineOptions = computed(() => ({
  ...baseChartOptions,
  plugins: { ...baseChartOptions.plugins, tooltip: currencyTooltip() },
}));

const healthData = computed(() => ({
  labels: ['Clean transactions', 'Faulty transactions'],
  datasets: [{
    data: [kpis.value.clean_records || 0, kpis.value.faulty_count || 0],
    backgroundColor: ['#3dd68c', '#e74c5c'],
    borderWidth: 0,
    hoverOffset: 6,
  }],
}));

const shortfallData = computed(() => ({
  labels: topShortfalls.value.map((s) => s.location_id),
  datasets: [{
    label: 'Shortfall',
    data: topShortfalls.value.map((s) => s.shortfall),
    backgroundColor: '#e74c5c',
    borderRadius: 4,
  }],
}));

const shortfallOptions = computed(() => shortfallBarOptions(topShortfalls.value));

const faultData = computed(() => ({
  labels: faultStats.value.map((f) => faultLabel(f.fault_type, store.faultTypeLabels)),
  datasets: [{
    data: faultStats.value.map((f) => f.count),
    backgroundColor: faultStats.value.map((_, i) => faultColor(i)),
    borderWidth: 0,
    hoverOffset: 6,
  }],
}));

const tierOrder = ['severe', 'moderate', 'minor'];
const tierColors: Record<string, string> = {
  severe: '#e74c5c',
  moderate: '#f0a500',
  minor: '#4a9eff',
};

const sortedTiers = computed(() =>
  [...faultByTier.value].sort(
    (a, b) => tierOrder.indexOf(a.severity) - tierOrder.indexOf(b.severity),
  ),
);

const tierData = computed(() => ({
  labels: sortedTiers.value.map((t) => t.severity.charAt(0).toUpperCase() + t.severity.slice(1)),
  datasets: [{
    label: 'Faulty transactions',
    data: sortedTiers.value.map((t) => t.count),
    backgroundColor: sortedTiers.value.map((t) => tierColors[t.severity] || '#95a5a6'),
    borderRadius: 4,
  }],
}));

const tierOptions = computed(() => tierBarOptions());

const gameData = computed(() => ({
  labels: byGame.value.map((g) => g.game_name),
  datasets: [{
    label: 'Revenue',
    data: byGame.value.map((g) => g.net_revenue),
    backgroundColor: '#3dd68c',
    borderRadius: 4,
  }],
}));

const gameOptions = computed(() => gameBarOptions(byGame.value));

const hasExceptions = computed(() =>
  (kpis.value.faulty_count || 0) > 0 || (kpis.value.shortfall_locations || 0) > 0,
);

function onPeriodChange() {
  load();
}

function onLocationChange() {
  load();
}

async function loadLocations() {
  locations.value = await fetch('/api/locations/options').then((r) => r.json()) as LocationOption[];
}

const unsubDashboard = onDashboard((data) => {
  applyDashboard(data);
  loading.value = false;
});

onMounted(async () => {
  await loadLocations();
  await load();
});

onUnmounted(unsubDashboard);
</script>

<template>
  <div class="dashboard">
    <header class="dash-header">
      <div>
        <h1>Dashboard</h1>
        <p class="subtitle">Revenue performance and validation health — exceptions shown separately below</p>
      </div>
      <div class="dash-actions">
        <PeriodFilter @change="onPeriodChange" />
        <LocationPicker
          v-model="locationId"
          :locations="locations"
          @update:model-value="onLocationChange"
        />
        <button class="btn secondary" :disabled="refreshingManual" @click="load">{{ refreshingManual ? 'Loading…' : 'Refresh' }}</button>
      </div>
    </header>

    <section class="kpi-strip kpi-strip--positive">
      <article class="kpi positive">
        <span class="kpi-label">Total net revenue</span>
        <span class="kpi-value positive">{{ formatCurrency(kpis.total_net_revenue) }}</span>
        <span class="kpi-hint">{{ isActive ? periodLabel : 'All imported transactions' }}</span>
      </article>
      <article v-if="!isActive" class="kpi positive">
        <span class="kpi-label">Net revenue today</span>
        <span class="kpi-value positive">{{ formatCurrency(kpis.net_revenue_today) }}</span>
        <span class="kpi-hint">Current report date</span>
      </article>
      <article class="kpi positive">
        <span class="kpi-label">Clean transactions</span>
        <span class="kpi-value positive">{{ formatCount(kpis.clean_records) }}</span>
        <span class="kpi-hint">of {{ formatCount(kpis.total_records) }} total</span>
      </article>
      <article class="kpi positive">
        <span class="kpi-label">Validation pass rate</span>
        <span class="kpi-value positive">{{ (kpis.clean_pct ?? 100).toFixed(1) }}%</span>
        <span class="kpi-hint">Transactions without faults</span>
      </article>
      <article class="kpi positive">
        <span class="kpi-label">Locations meeting expected</span>
        <span class="kpi-value positive">{{ formatCount(kpis.matched_locations) }}</span>
        <span class="kpi-hint">of {{ formatCount(kpis.reconciliation_total) }} reconciled</span>
      </article>
    </section>

    <section class="chart-grid">
      <article class="chart-panel chart-panel--hero">
        <div class="panel-head">
          <h2>Revenue by date</h2>
          <span class="panel-meta">{{ byDate.length }} days</span>
        </div>
        <div class="chart-box chart-box--tall">
          <Line :data="lineData" :options="lineOptions" />
        </div>
      </article>

      <article class="chart-panel">
        <div class="panel-head">
          <h2>Transaction health</h2>
          <span class="panel-meta">Clean vs faulty</span>
        </div>
        <div class="chart-box chart-box--medium">
          <Doughnut :data="healthData" :options="faultDoughnutOptions()" />
        </div>
      </article>

      <article class="chart-panel chart-panel--wide">
        <div class="panel-head">
          <h2>Revenue by game</h2>
          <span class="panel-meta">{{ byGame.length }} games</span>
        </div>
        <div class="chart-box chart-box--games">
          <Bar :data="gameData" :options="gameOptions" />
        </div>
      </article>
    </section>

    <section v-if="hasExceptions" class="exceptions-section">
      <h2 class="section-title">Exceptions to review</h2>
      <div class="kpi-strip kpi-strip--exceptions">
        <article class="kpi exception">
          <span class="kpi-label">Faulty transactions</span>
          <span class="kpi-value">{{ formatCount(kpis.faulty_count) }}</span>
          <router-link to="/faults" class="kpi-link">View faults →</router-link>
        </article>
        <article class="kpi exception">
          <span class="kpi-label">Locations below expected</span>
          <span class="kpi-value">{{ formatCount(kpis.shortfall_locations) }}</span>
          <router-link to="/reconcile?view=shortfalls" class="kpi-link">View reconciliation →</router-link>
        </article>
        <article class="kpi exception">
          <span class="kpi-label">Total fault delta</span>
          <span class="kpi-value">{{ formatCurrency(kpis.total_delta) }}</span>
          <span class="kpi-hint">Reported vs expected gaps</span>
        </article>
      </div>

      <div class="chart-grid chart-grid--exceptions">
        <article class="chart-panel">
          <div class="panel-head">
            <h2>Top location shortfalls</h2>
            <router-link to="/reconcile?view=shortfalls" class="panel-link">View all shortfalls</router-link>
          </div>
          <div class="chart-box chart-box--medium">
            <Bar :data="shortfallData" :options="shortfallOptions" />
          </div>
        </article>

        <article class="chart-panel">
          <div class="panel-head">
            <h2>Faulty transactions by type</h2>
            <router-link to="/faults" class="panel-link">View faults</router-link>
          </div>
          <div class="chart-box chart-box--medium">
            <Doughnut :data="faultData" :options="faultDoughnutOptions()" />
          </div>
        </article>

        <article class="chart-panel">
          <div class="panel-head">
            <h2>Fault severity</h2>
            <span class="panel-meta">By transaction</span>
          </div>
          <div class="chart-box chart-box--medium">
            <Bar :data="tierData" :options="tierOptions" />
          </div>
        </article>
      </div>
    </section>
  </div>
</template>

<style scoped>
.dashboard {
  width: 100%;
  max-width: none;
  padding: 0 0.5rem 2rem;
}

.dash-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.dash-actions {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex-wrap: wrap;
}

h1 {
  margin: 0 0 0.25rem;
  font-size: 1.75rem;
  font-weight: 700;
}

.subtitle {
  margin: 0;
  color: var(--muted);
  font-size: 0.95rem;
}

.kpi-strip {
  display: grid;
  gap: 1rem;
  margin-bottom: 1.25rem;
}

.kpi-strip--positive {
  grid-template-columns: repeat(5, 1fr);
}

.kpi-strip--exceptions {
  grid-template-columns: repeat(3, 1fr);
  margin-bottom: 1rem;
}

.kpi {
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1.1rem 1.25rem;
  display: flex;
  flex-direction: column;
  gap: 0.35rem;
}

.kpi.positive { border-color: rgba(61, 214, 140, 0.4); }
.kpi.exception { border-color: rgba(231, 76, 92, 0.55); }

.kpi.exception .kpi-value { color: var(--red); }

.kpi-label {
  font-size: 0.8rem;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.04em;
}

.kpi-value {
  font-size: 1.85rem;
  font-weight: 700;
  line-height: 1.1;
}

.kpi-value.positive { color: var(--green); }

.kpi-hint, .kpi-link {
  font-size: 0.78rem;
  color: var(--muted);
}

.kpi-link { color: var(--blue); }

.exceptions-section {
  margin-top: 0.5rem;
  padding-top: 1.25rem;
  border-top: 1px solid var(--border);
}

.section-title {
  margin: 0 0 1rem;
  font-size: 1.1rem;
  font-weight: 600;
  color: var(--muted);
}

.chart-grid {
  display: grid;
  grid-template-columns: repeat(12, 1fr);
  gap: 1rem;
}

.chart-grid--exceptions {
  margin-top: 0;
}

.chart-grid--exceptions .chart-panel {
  grid-column: span 4;
}

.chart-panel {
  grid-column: span 6;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 1rem 1.15rem 1.15rem;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.chart-panel--hero { grid-column: span 8; }
.chart-panel--wide { grid-column: span 12; }

.panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
  flex-shrink: 0;
}

.panel-head h2 {
  margin: 0;
  font-size: 1rem;
  font-weight: 600;
  color: var(--text);
}

.panel-meta, .panel-link {
  font-size: 0.8rem;
  color: var(--muted);
}

.panel-link { color: var(--blue); }

.chart-box {
  position: relative;
  width: 100%;
  flex: 1;
}

.chart-box--tall { height: 380px; }
.chart-box--medium { height: 320px; }
.chart-box--games { min-height: 420px; height: 420px; }

@media (max-width: 1200px) {
  .kpi-strip--positive { grid-template-columns: repeat(2, 1fr); }
  .kpi-strip--exceptions { grid-template-columns: 1fr; }
  .chart-panel, .chart-panel--hero, .chart-panel--wide { grid-column: span 12; }
  .chart-grid--exceptions { grid-template-columns: 1fr; }
  .chart-grid--exceptions .chart-panel { grid-column: span 12; }
}

@media (max-width: 640px) {
  .kpi-strip--positive { grid-template-columns: 1fr; }
  .chart-box--tall { height: 280px; }
  .chart-box--medium { height: 260px; }
}
</style>
