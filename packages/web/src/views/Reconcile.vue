<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import LocationPicker from '../components/LocationPicker.vue';
import PeriodFilter from '../components/PeriodFilter.vue';
import TablePager from '../components/TablePager.vue';
import { usePeriodFilter } from '../composables/usePeriodFilter';
import { appendColumnFilters } from '../utils/columnFilters';
import { formatReportTimestamp } from '../utils/datetime';
import { formatCount } from '../utils/labels';

interface Row {
  location_id: string;
  location_name: string;
  report_date: string;
  submitted_at?: string | null;
  expected_net_revenue: number;
  actual_net_revenue: number;
  variance: number;
  shortfall: number;
  overage: number;
  status: string;
  variance_tier: string;
  notes?: string;
}

interface LocationOption {
  location_id: string;
  location_name: string;
}

type ViewMode = 'all' | 'shortfalls' | 'matches';

const PAGE_SIZE = 100;

const route = useRoute();
const { applyToParams } = usePeriodFilter();

const rows = ref<Row[]>([]);
const total = ref(0);
const page = ref(0);
const loading = ref(false);
const viewMode = ref<ViewMode>('all');
const locationId = ref('');
const sort = ref('location_id');
const downloadScope = ref<'filtered' | 'all'>('filtered');
const pickerLocations = ref<LocationOption[]>([]);
const summary = ref({ total: 0, matches: 0, shortfalls: 0 });

const col = ref({
  location_id: '',
  location_name: '',
  report_date: '',
  expected: '',
  actual: '',
  shortfall: '',
  overage: '',
  status: '',
  tier: '',
  notes: '',
});

type ColKey = keyof typeof col.value;

const filterFields: { key: ColKey; placeholder: string }[] = [
  { key: 'location_id', placeholder: 'Filter…' },
  { key: 'location_name', placeholder: 'Filter…' },
  { key: 'report_date', placeholder: 'YYYY-MM-DD' },
  { key: 'expected', placeholder: 'Filter…' },
  { key: 'actual', placeholder: 'Filter…' },
  { key: 'shortfall', placeholder: 'Filter…' },
  { key: 'overage', placeholder: 'Filter…' },
  { key: 'status', placeholder: 'match / mismatch' },
  { key: 'tier', placeholder: 'Filter…' },
  { key: 'notes', placeholder: 'Filter…' },
];

function clearFilter(key: ColKey) {
  col.value[key] = '';
}

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)));
const pageLabel = computed(() => {
  if (!total.value) return 'No rows';
  const start = page.value * PAGE_SIZE + 1;
  const end = Math.min((page.value + 1) * PAGE_SIZE, total.value);
  return `${start}–${end} of ${total.value.toLocaleString()}`;
});

const pageStats = computed(() => {
  const matched = rows.value.filter((r) => r.status === 'match').length;
  const shortfalls = rows.value.filter((r) => r.shortfall > 0.01).length;
  return { matched, shortfalls };
});

function buildServerParams() {
  const params = new URLSearchParams({
    sort: sort.value,
    limit: String(PAGE_SIZE),
    offset: String(page.value * PAGE_SIZE),
  });
  if (viewMode.value === 'shortfalls') params.set('shortfall_only', 'true');
  if (viewMode.value === 'matches') params.set('status', 'match');
  if (locationId.value) params.set('location_id', locationId.value);
  applyToParams(params);
  appendColumnFilters(params, col.value);
  return params;
}

function buildDownloadParams(scope: 'filtered' | 'all') {
  const params = new URLSearchParams({ sort: sort.value, scope });
  if (scope === 'filtered') {
    if (viewMode.value === 'shortfalls') params.set('shortfall_only', 'true');
    if (viewMode.value === 'matches') params.set('status', 'match');
    if (locationId.value) params.set('location_id', locationId.value);
    applyToParams(params);
    appendColumnFilters(params, col.value);
  }
  return params;
}

const downloadUrl = computed(() =>
  `/api/reports/reconciliation.xlsx?${buildDownloadParams(downloadScope.value).toString()}`
);

async function loadPickerLocations() {
  pickerLocations.value = await fetch('/api/locations/options').then((r) => r.json()) as LocationOption[];
}

async function load() {
  loading.value = true;
  try {
    const data = await fetch(`/api/revenue/reconcile?${buildServerParams()}`).then((r) => r.json());
    rows.value = data.records || [];
    total.value = data.total ?? rows.value.length;
    if (data.summary) {
      summary.value = {
        total: Number(data.summary.total || 0),
        matches: Number(data.summary.matches || 0),
        shortfalls: Number(data.summary.shortfalls || 0),
      };
    }
  } finally {
    loading.value = false;
  }
}

function refresh() {
  page.value = 0;
  load();
}

function onPeriodChange() {
  refresh();
}

function onLocationChange() {
  page.value = 0;
  load();
}

function goPage(p: number) {
  const next = Math.min(Math.max(0, p), totalPages.value - 1);
  if (next === page.value) return;
  page.value = next;
  load();
}

watch([viewMode, sort], () => {
  page.value = 0;
  load();
});

let filterTimer: ReturnType<typeof setTimeout> | undefined;
watch(col, () => {
  clearTimeout(filterTimer);
  filterTimer = setTimeout(() => {
    page.value = 0;
    load();
  }, 400);
}, { deep: true });

function tierClass(tier: string) {
  if (tier === 'severe') return 'badge red';
  if (tier === 'moderate') return 'badge amber';
  if (tier === 'minor') return 'badge blue';
  return '';
}

function rowClass(r: Row) {
  if (r.status === 'match') return 'row-clean';
  if (r.shortfall > 0.01) return 'fault-under';
  return '';
}

function fmt(n: number) {
  return `$${Number(n).toFixed(2)}`;
}

onMounted(async () => {
  const view = route.query.view;
  if (view === 'shortfalls') viewMode.value = 'shortfalls';
  else if (view === 'matches') viewMode.value = 'matches';
  await loadPickerLocations();
  await load();
});
</script>

<template>
  <div class="reconcile-page">
    <header class="page-header">
      <div>
        <h1>Reconciliation</h1>
        <p class="subtitle">Compare expected vs actual revenue — {{ PAGE_SIZE }} rows per page</p>
      </div>
      <button class="btn" :disabled="loading" @click="refresh">
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </header>

    <div class="summary-strip">
      <span class="pill pill-green">{{ formatCount(summary.matches) }} matches</span>
      <span v-if="summary.shortfalls" class="pill pill-red">{{ formatCount(summary.shortfalls) }} shortfalls</span>
      <span class="pill pill-blue">{{ formatCount(summary.total) }} rows in view</span>
      <span class="page-pills">
        <span class="pill pill-green">{{ pageStats.matched }} matches on page</span>
        <span v-if="pageStats.shortfalls" class="pill pill-red">{{ pageStats.shortfalls }} shortfalls on page</span>
      </span>
    </div>

    <div class="toolbar">
      <PeriodFilter @change="onPeriodChange" />
      <div class="view-toggle" role="group" aria-label="Show rows">
        <button
          type="button"
          class="toggle-btn"
          :class="{ active: viewMode === 'all' }"
          @click="viewMode = 'all'"
        >All</button>
        <button
          type="button"
          class="toggle-btn"
          :class="{ active: viewMode === 'shortfalls' }"
          @click="viewMode = 'shortfalls'"
        >Shortfalls</button>
        <button
          type="button"
          class="toggle-btn"
          :class="{ active: viewMode === 'matches' }"
          @click="viewMode = 'matches'"
        >Matches</button>
      </div>

      <LocationPicker
        v-model="locationId"
        :locations="pickerLocations"
        @change="onLocationChange"
      />

      <select v-model="sort" class="sort-select">
        <option value="location_id">Sort by location ID</option>
        <option value="shortfall_desc">Sort by shortfall</option>
        <option value="date">Sort by date</option>
        <option value="status">Sort by status</option>
      </select>

      <span class="page-info">{{ pageLabel }}</span>
    </div>

    <div class="download-bar">
      <label class="download-label">
        Download
        <select v-model="downloadScope" class="scope-select">
          <option value="filtered">Current filtered view</option>
          <option value="all">All reconciliation data</option>
        </select>
      </label>
      <a class="btn secondary" :href="downloadUrl" download>Download XLSX</a>
    </div>

    <TablePager
      :page="page"
      :total-pages="totalPages"
      :loading="loading"
      @prev="goPage(page - 1)"
      @next="goPage(page + 1)"
    />

    <div class="card table-wrap">
      <table>
        <thead>
          <tr>
            <th>Location ID</th>
            <th>Name</th>
            <th>Date</th>
            <th>Expected</th>
            <th>Actual</th>
            <th>Shortfall</th>
            <th>Overage</th>
            <th>Status</th>
            <th>Tier</th>
            <th>Notes</th>
          </tr>
          <tr class="filter-row">
            <th v-for="f in filterFields" :key="f.key">
              <div class="filter-field">
                <input v-model="col[f.key]" :placeholder="f.placeholder" />
                <button
                  v-if="col[f.key]"
                  type="button"
                  class="filter-clear"
                  :aria-label="`Clear ${f.key} filter`"
                  title="Clear filter"
                  @click="clearFilter(f.key)"
                >×</button>
              </div>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td colspan="10" class="empty">Loading…</td>
          </tr>
          <tr v-else-if="!rows.length">
            <td colspan="10" class="empty">No rows match the current filters.</td>
          </tr>
          <tr
            v-for="r in rows"
            v-else
            :key="`${r.location_id}-${r.report_date}`"
            :class="rowClass(r)"
          >
            <td>{{ r.location_id }}</td>
            <td>{{ r.location_name }}</td>
            <td>{{ formatReportTimestamp(r.report_date, r.submitted_at) }}</td>
            <td>{{ fmt(r.expected_net_revenue) }}</td>
            <td>{{ fmt(r.actual_net_revenue) }}</td>
            <td :class="{ 'text-warn': r.shortfall > 0.01 }">{{ fmt(r.shortfall) }}</td>
            <td>{{ fmt(r.overage) }}</td>
            <td>
              <span :class="r.status === 'match' ? 'badge green' : 'badge red'">
                {{ r.status }}
              </span>
            </td>
            <td><span v-if="r.variance_tier !== 'none'" :class="tierClass(r.variance_tier)">{{ r.variance_tier }}</span></td>
            <td>{{ r.notes || '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <TablePager
      :page="page"
      :total-pages="totalPages"
      :loading="loading"
      @prev="goPage(page - 1)"
      @next="goPage(page + 1)"
    />
  </div>
</template>

<style scoped>
.reconcile-page { width: 100%; }

.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1rem;
}

h1 { margin: 0 0 0.25rem; font-size: 1.75rem; }
.subtitle { margin: 0; color: var(--muted); font-size: 0.9rem; }

.summary-strip {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
  margin-bottom: 1rem;
}

.page-pills { margin-left: auto; display: flex; gap: 0.5rem; flex-wrap: wrap; }

.toolbar {
  display: flex;
  flex-wrap: wrap;
  gap: 1rem;
  align-items: center;
  margin-bottom: 1rem;
}

.view-toggle {
  display: inline-flex;
  border: 1px solid var(--border);
  border-radius: 6px;
  overflow: hidden;
}

.toggle-btn {
  background: var(--surface);
  color: var(--muted);
  border: none;
  padding: 0.45rem 0.85rem;
  font-size: 0.85rem;
  cursor: pointer;
}

.toggle-btn + .toggle-btn { border-left: 1px solid var(--border); }

.toggle-btn.active {
  background: #1a2f4a;
  color: var(--blue);
  font-weight: 600;
}

.sort-select, .scope-select {
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  padding: 0.4rem 0.6rem;
  border-radius: 4px;
  font-size: 0.85rem;
}

.page-info { margin-left: auto; color: var(--muted); font-size: 0.85rem; }

.download-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
}

.download-label {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  color: var(--muted);
  font-size: 0.85rem;
}

.table-wrap { overflow-x: auto; }

table { min-width: 1200px; width: 100%; }

.filter-row th { padding: 0.35rem 0.4rem; }

.filter-field {
  position: relative;
  display: flex;
  align-items: center;
}

.filter-row input {
  width: 100%;
  min-width: 4rem;
  background: #121a26;
  color: var(--text);
  border: 1px solid var(--border);
  border-radius: 4px;
  padding: 0.3rem 1.4rem 0.3rem 0.4rem;
  font-size: 0.75rem;
}

.filter-clear {
  position: absolute;
  right: 0.2rem;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 1.1rem;
  height: 1.1rem;
  padding: 0;
  border: none;
  border-radius: 3px;
  background: transparent;
  color: var(--muted);
  font-size: 0.95rem;
  line-height: 1;
  cursor: pointer;
}

.filter-clear:hover {
  color: var(--text);
  background: rgba(255, 255, 255, 0.08);
}

.text-warn { color: var(--red); }
.empty { text-align: center; color: var(--muted); padding: 1.5rem !important; }
</style>
