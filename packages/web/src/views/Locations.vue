<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import LocationPicker from '../components/LocationPicker.vue';
import PeriodFilter from '../components/PeriodFilter.vue';
import TablePager from '../components/TablePager.vue';
import { usePeriodFilter } from '../composables/usePeriodFilter';
import { appendColumnFilters } from '../utils/columnFilters';
import { formatCount, formatCurrency } from '../utils/labels';

interface Location {
  location_id: string;
  location_name: string;
  addr: string;
  city: string;
  st: string;
  zip: string;
  machine_count: number;
  total_shortfall: number;
  shortfall_count: number;
}

interface LocationOption {
  location_id: string;
  location_name: string;
}

const PAGE_SIZE = 100;

const { applyToParams } = usePeriodFilter();

const records = ref<Location[]>([]);
const total = ref(0);
const page = ref(0);
const loading = ref(false);
const shortfallOnly = ref(false);
const locationId = ref('');
const pickerLocations = ref<LocationOption[]>([]);
const summary = ref({
  total_locations: 0,
  shortfall_locations: 0,
  total_machines: 0,
});

const col = ref({
  location_id: '',
  location_name: '',
  addr: '',
  city: '',
  st: '',
  zip: '',
  machine_count: '',
  shortfall_count: '',
  total_shortfall: '',
});

type ColKey = keyof typeof col.value;

const filterFields: { key: ColKey; placeholder: string }[] = [
  { key: 'location_id', placeholder: 'Filter…' },
  { key: 'location_name', placeholder: 'Filter…' },
  { key: 'addr', placeholder: 'Filter…' },
  { key: 'city', placeholder: 'Filter…' },
  { key: 'st', placeholder: 'Filter…' },
  { key: 'zip', placeholder: 'Filter…' },
  { key: 'machine_count', placeholder: 'Filter…' },
  { key: 'shortfall_count', placeholder: 'Filter…' },
  { key: 'total_shortfall', placeholder: 'Filter…' },
];

function clearFilter(key: ColKey) {
  col.value[key] = '';
}

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)));
const pageLabel = computed(() => {
  if (!total.value) return 'No locations';
  const start = page.value * PAGE_SIZE + 1;
  const end = Math.min((page.value + 1) * PAGE_SIZE, total.value);
  return `${start}–${end} of ${total.value.toLocaleString()}`;
});

const pageStats = computed(() => {
  const withShortfall = records.value.filter((r) => r.shortfall_count > 0).length;
  const machines = records.value.reduce((sum, r) => sum + r.machine_count, 0);
  return { withShortfall, machines };
});

async function loadPickerLocations() {
  pickerLocations.value = await fetch('/api/locations/options').then((r) => r.json()) as LocationOption[];
}

async function load() {
  loading.value = true;
  try {
    const params = new URLSearchParams({
      limit: String(PAGE_SIZE),
      offset: String(page.value * PAGE_SIZE),
    });
    if (shortfallOnly.value) params.set('shortfall_only', 'true');
    if (locationId.value) params.set('location_id', locationId.value);
    applyToParams(params);
    appendColumnFilters(params, col.value);

    const data = await fetch(`/api/locations?${params}`).then((r) => r.json());
    records.value = data.records || [];
    total.value = data.total ?? records.value.length;
    if (data.summary) {
      summary.value = {
        total_locations: Number(data.summary.total_locations || 0),
        shortfall_locations: Number(data.summary.shortfall_locations || 0),
        total_machines: Number(data.summary.total_machines || 0),
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

watch(shortfallOnly, () => {
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

function rowClass(r: Location) {
  if (r.shortfall_count > 0) return 'fault-under';
  return 'row-clean';
}

function fmtShortfall(n: number) {
  return n > 0 ? formatCurrency(n) : '—';
}

watch(shortfallOnly, () => {
  page.value = 0;
  load();
});

onMounted(async () => {
  await loadPickerLocations();
  await load();
});
</script>

<template>
  <div class="locations-page">
    <header class="page-header">
      <div>
        <h1>Locations</h1>
        <p class="subtitle">All venue locations — {{ PAGE_SIZE }} rows per page</p>
      </div>
      <button class="btn" :disabled="loading" @click="refresh">
        {{ loading ? 'Loading…' : 'Refresh' }}
      </button>
    </header>

    <div class="summary-strip">
      <span class="pill pill-green">{{ formatCount(summary.total_locations) }} locations total</span>
      <span class="pill pill-green">{{ formatCount(summary.total_machines) }} machines total</span>
      <span v-if="summary.shortfall_locations" class="pill pill-red">
        {{ formatCount(summary.shortfall_locations) }} with shortfalls
      </span>
      <span class="page-pills">
        <span class="pill pill-green">{{ pageStats.machines }} machines on page</span>
        <span v-if="pageStats.withShortfall" class="pill pill-red">{{ pageStats.withShortfall }} shortfalls on page</span>
      </span>
    </div>

    <div class="toolbar">
      <PeriodFilter @change="onPeriodChange" />
      <label class="check"><input v-model="shortfallOnly" type="checkbox" /> Shortfalls only</label>
      <LocationPicker
        v-model="locationId"
        :locations="pickerLocations"
        @change="onLocationChange"
      />
      <span class="page-info">{{ pageLabel }}</span>
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
            <th>Address</th>
            <th>City</th>
            <th>State</th>
            <th>Zip</th>
            <th>Machines</th>
            <th>Shortfalls</th>
            <th>Total Shortfall</th>
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
            <td colspan="9" class="empty">Loading…</td>
          </tr>
          <tr v-else-if="!records.length">
            <td colspan="9" class="empty">No locations match the current filters.</td>
          </tr>
          <tr
            v-for="r in records"
            v-else
            :key="r.location_id"
            :class="rowClass(r)"
          >
            <td>{{ r.location_id }}</td>
            <td>{{ r.location_name }}</td>
            <td>{{ r.addr || '—' }}</td>
            <td>{{ r.city || '—' }}</td>
            <td>{{ r.st || '—' }}</td>
            <td>{{ r.zip || '—' }}</td>
            <td>{{ r.machine_count }}</td>
            <td>
              <span v-if="r.shortfall_count" class="badge red">{{ r.shortfall_count }}</span>
              <span v-else class="badge green">0</span>
            </td>
            <td>{{ fmtShortfall(r.total_shortfall) }}</td>
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
.locations-page { width: 100%; }

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

.check { display: flex; align-items: center; gap: 0.4rem; font-size: 0.9rem; color: var(--muted); }
.page-info { margin-left: auto; color: var(--muted); font-size: 0.85rem; }

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

.empty { text-align: center; color: var(--muted); padding: 1.5rem !important; }
</style>
