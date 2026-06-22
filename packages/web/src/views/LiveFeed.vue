<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import LocationPicker from '../components/LocationPicker.vue';
import PeriodFilter from '../components/PeriodFilter.vue';
import TablePager from '../components/TablePager.vue';
import { usePeriodFilter } from '../composables/usePeriodFilter';
import { onSubmission } from '../composables/useLiveStream';
import { useAppStore } from '../stores/app';
import { appendColumnFilters } from '../utils/columnFilters';
import { formatReportTimestamp } from '../utils/datetime';
import { faultLabel, formatCount } from '../utils/labels';

interface Record {
  location_id: string;
  location_name: string;
  machine_id: string;
  report_date: string;
  submitted_at?: string;
  cash_in: number;
  voucher_in: number;
  voucher_out: number;
  net_revenue: number;
  computed_net_revenue?: number;
  is_faulty: number | boolean;
  fault_type?: string;
  delta?: number;
}

interface Location {
  location_id: string;
  location_name: string;
}

const PAGE_SIZE = 100;

const store = useAppStore();

const { applyToParams } = usePeriodFilter();

const records = ref<Record[]>([]);
const total = ref(0);
const page = ref(0);
const initialLoading = ref(true);
const refreshing = ref(false);
const faultyOnly = ref(false);
const locationId = ref('');
const locations = ref<Location[]>([]);
const summary = computed(() => ({
  clean_records: store.cleanCount,
  faulty_count: store.faultyCount,
  total_records: store.totalRecords,
  clean_pct: store.cleanPct,
}));

const col = ref({
  status: '',
  location: '',
  machine: '',
  date: '',
  cash_in: '',
  voucher_in: '',
  voucher_out: '',
  net_revenue: '',
  computed: '',
  delta: '',
});

type ColKey = keyof typeof col.value;

const filterFields: { key: ColKey; placeholder: string }[] = [
  { key: 'status', placeholder: 'ok / fault' },
  { key: 'location', placeholder: 'Filter…' },
  { key: 'machine', placeholder: 'Filter…' },
  { key: 'date', placeholder: 'YYYY-MM-DD' },
  { key: 'cash_in', placeholder: 'Filter…' },
  { key: 'voucher_in', placeholder: 'Filter…' },
  { key: 'voucher_out', placeholder: 'Filter…' },
  { key: 'net_revenue', placeholder: 'Filter…' },
  { key: 'computed', placeholder: 'Filter…' },
  { key: 'delta', placeholder: 'Filter…' },
];

function clearFilter(key: ColKey) {
  col.value[key] = '';
}

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)));
const pageLabel = computed(() => {
  if (!total.value) return 'No records';
  const start = page.value * PAGE_SIZE + 1;
  const end = Math.min((page.value + 1) * PAGE_SIZE, total.value);
  return `${start}–${end} of ${total.value.toLocaleString()}`;
});

const pageStats = computed(() => {
  const clean = records.value.filter((r) => !r.is_faulty).length;
  const faulty = records.value.length - clean;
  return { clean, faulty };
});

async function loadLocations() {
  locations.value = await fetch('/api/locations/options').then((r) => r.json()) as Location[];
}

function recordKey(r: Record) {
  return `${r.machine_id}|${String(r.report_date).slice(0, 10)}`;
}

function mergeRecords(incoming: Record[]) {
  const existing = new Map(records.value.map((r) => [recordKey(r), r]));
  records.value = incoming.map((row) => {
    const prev = existing.get(recordKey(row));
    if (prev) {
      Object.assign(prev, row);
      return prev;
    }
    return row;
  });
}

let loadInFlight = false;

async function load(opts?: { background?: boolean }) {
  if (loadInFlight) {
    return;
  }
  loadInFlight = true;
  const background = opts?.background ?? false;
  if (!background) refreshing.value = true;
  try {
    const params = new URLSearchParams({
      limit: String(PAGE_SIZE),
      offset: String(page.value * PAGE_SIZE),
    });
    if (faultyOnly.value) params.set('faulty_only', 'true');
    if (locationId.value) params.set('location_id', locationId.value);
    applyToParams(params);
    appendColumnFilters(params, col.value);

    const data = await fetch(`/api/revenue/recent?${params}`).then((r) => r.json());
    mergeRecords(data.records || []);
    total.value = data.total ?? records.value.length;
  } finally {
    initialLoading.value = false;
    refreshing.value = false;
    loadInFlight = false;
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


function rowClass(r: Record) {
  if (!r.is_faulty) return 'row-clean';
  if (r.fault_type === 'overreported_net') return 'fault-over';
  return 'fault-under';
}

function fmt(n: number | undefined) {
  return n == null ? '—' : `$${Number(n).toFixed(2)}`;
}

watch(faultyOnly, () => {
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

let unsubSubmission: (() => void) | undefined;

onMounted(async () => {
  await loadLocations();
  await load();
  unsubSubmission = onSubmission(() => load({ background: true }));
});

onUnmounted(() => {
  unsubSubmission?.();
});
</script>

<template>
  <div class="live-feed">
    <header class="feed-header">
      <div>
        <h1>Live Feed</h1>
        <p class="subtitle">Paginated revenue imports — {{ PAGE_SIZE }} rows per page</p>
      </div>
      <button class="btn" :disabled="refreshing" @click="refresh">
        {{ refreshing ? 'Loading…' : 'Refresh' }}
      </button>
    </header>

    <div class="summary-strip">
      <span class="pill pill-green">{{ formatCount(summary.clean_records) }} clean total</span>
      <span class="pill pill-green">{{ summary.clean_pct.toFixed(1) }}% pass rate</span>
      <span class="pill pill-red">{{ formatCount(summary.faulty_count) }} faults total</span>
      <span class="page-pills">
        <span class="pill pill-green">{{ pageStats.clean }} clean on page</span>
        <span v-if="pageStats.faulty" class="pill pill-red">{{ pageStats.faulty }} faults on page</span>
      </span>
    </div>

    <div class="toolbar">
      <PeriodFilter @change="onPeriodChange" />
      <label class="check"><input v-model="faultyOnly" type="checkbox" /> Show faults only</label>
      <LocationPicker
        v-model="locationId"
        :locations="locations"
        @change="onLocationChange"
      />
      <span class="page-info">{{ pageLabel }}</span>
    </div>

    <TablePager
      :page="page"
      :total-pages="totalPages"
      :loading="refreshing"
      @prev="goPage(page - 1)"
      @next="goPage(page + 1)"
    />

    <div class="card table-wrap" :class="{ 'table-wrap--refreshing': refreshing }">
      <table>
        <thead>
          <tr>
            <th>Status</th>
            <th>Location</th>
            <th>Machine</th>
            <th>Date</th>
            <th>Cash In</th>
            <th>Voucher In</th>
            <th>Voucher Out</th>
            <th>Net Revenue</th>
            <th>Computed</th>
            <th>Delta</th>
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
          <tr v-if="initialLoading && !records.length">
            <td colspan="10" class="empty">Loading…</td>
          </tr>
          <tr v-else-if="!records.length">
            <td colspan="10" class="empty">No records match the current filters.</td>
          </tr>
          <tr
            v-for="r in records"
            v-else
            :key="recordKey(r)"
            :class="rowClass(r)"
          >
            <td>
              <span v-if="r.is_faulty" class="badge red" :title="r.fault_type ? faultLabel(r.fault_type, store.faultTypeLabels) : ''">FAULT</span>
              <span v-else class="badge green">OK</span>
            </td>
            <td>{{ r.location_id }} — {{ r.location_name }}</td>
            <td>{{ r.machine_id }}</td>
            <td>{{ formatReportTimestamp(r.report_date, r.submitted_at) }}</td>
            <td>{{ fmt(r.cash_in) }}</td>
            <td>{{ fmt(r.voucher_in) }}</td>
            <td>{{ fmt(r.voucher_out) }}</td>
            <td>{{ fmt(r.net_revenue) }}</td>
            <td>{{ fmt(r.computed_net_revenue) }}</td>
            <td>{{ r.delta != null ? fmt(r.delta) : '—' }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <TablePager
      :page="page"
      :total-pages="totalPages"
      :loading="refreshing"
      @prev="goPage(page - 1)"
      @next="goPage(page + 1)"
    />
  </div>
</template>

<style scoped>
.live-feed { width: 100%; }

.feed-header {
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

.check { display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; color: var(--muted); }
.page-info { margin-left: auto; color: var(--muted); font-size: 0.85rem; }

.table-wrap { overflow-x: auto; }

.table-wrap--refreshing {
  opacity: 0.92;
  transition: opacity 0.15s ease;
}

table { min-width: 1100px; }

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

.empty { text-align: center; color: var(--muted); padding: 1.5rem !important; }
</style>
