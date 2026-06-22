<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import LocationPicker from '../components/LocationPicker.vue';
import PeriodFilter from '../components/PeriodFilter.vue';
import TablePager from '../components/TablePager.vue';
import { usePeriodFilter } from '../composables/usePeriodFilter';
import { onSubmission } from '../composables/useLiveStream';
import { formatReportTimestamp } from '../utils/datetime';
import { formatCount } from '../utils/labels';

interface Submission {
  id: number;
  source: string;
  location_id: string | null;
  location_name: string | null;
  report_date: string | null;
  idempotency_key: string | null;
  submission_kind: string;
  status: string;
  record_count: number;
  imported_count: number;
  updated_count: number;
  skipped_count: number;
  error_count: number;
  created_at: string;
}

interface LocationOption {
  location_id: string;
  location_name: string;
}

const PAGE_SIZE = 50;

const submissions = ref<Submission[]>([]);
const total = ref(0);
const page = ref(0);
const initialLoading = ref(true);
const refreshing = ref(false);
const kindFilter = ref('');
const locationId = ref('');
const pickerLocations = ref<LocationOption[]>([]);
const summary = ref({
  total_submissions: 0,
  daily_count: 0,
  resubmit_count: 0,
  failed_count: 0,
  total_imported: 0,
  total_updated: 0,
  total_skipped: 0,
  total_errors: 0,
});

const { applyToParams } = usePeriodFilter();

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / PAGE_SIZE)));
const pageLabel = computed(() => {
  if (!total.value) return 'No submissions';
  const start = page.value * PAGE_SIZE + 1;
  const end = Math.min((page.value + 1) * PAGE_SIZE, total.value);
  return `${start}–${end} of ${total.value.toLocaleString()}`;
});

function buildParams() {
  const params = new URLSearchParams({
    limit: String(PAGE_SIZE),
    offset: String(page.value * PAGE_SIZE),
  });
  if (kindFilter.value) params.set('submission_kind', kindFilter.value);
  if (locationId.value) params.set('location_id', locationId.value);
  applyToParams(params);
  return params;
}

async function loadPickerLocations() {
  pickerLocations.value = await fetch('/api/locations/options').then((r) => r.json()) as LocationOption[];
}

function mergeSubmissions(incoming: Submission[]) {
  const existing = new Map(submissions.value.map((s) => [s.id, s]));
  submissions.value = incoming.map((row) => {
    const prev = existing.get(row.id);
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
    const data = await fetch(`/api/submissions?${buildParams()}`).then((r) => r.json());
    mergeSubmissions(data.submissions || []);
    total.value = data.total ?? submissions.value.length;
    if (data.summary) summary.value = data.summary;
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

function kindClass(kind: string) {
  if (kind === 'daily') return 'badge green';
  if (kind === 'resubmit') return 'badge amber';
  return 'badge';
}

function statusClass(status: string) {
  if (status === 'completed') return 'pill pill-green';
  if (status === 'failed') return 'pill pill-red';
  return 'pill pill-amber';
}

function fmtTime(iso: string) {
  return new Date(iso).toLocaleString();
}

watch(kindFilter, () => {
  page.value = 0;
  load();
});

let unsubSubmission: (() => void) | undefined;

onMounted(async () => {
  await loadPickerLocations();
  await load();
  unsubSubmission = onSubmission(() => load({ background: true }));
});

onUnmounted(() => {
  unsubSubmission?.();
});
</script>

<template>
  <div class="submissions-page">
    <header class="page-header">
      <div>
        <h1>Location Submissions</h1>
        <p class="subtitle">
          Monitor nightly revenue file imports from each persisted location — daily batches and idempotency resubmits
        </p>
      </div>
      <button class="btn" :disabled="refreshing" @click="refresh">
        {{ refreshing ? 'Loading…' : 'Refresh' }}
      </button>
    </header>

    <div class="summary-strip">
      <span class="pill pill-green">{{ formatCount(summary.daily_count) }} daily complete</span>
      <span class="pill pill-amber">{{ formatCount(summary.resubmit_count) }} resubmits</span>
      <span v-if="summary.failed_count" class="pill pill-red">{{ formatCount(summary.failed_count) }} failed validation</span>
      <span class="pill pill-blue">{{ formatCount(summary.total_imported) }} imported</span>
      <span class="pill pill-blue">{{ formatCount(summary.total_updated) }} updated</span>
      <span class="pill pill-green">{{ formatCount(summary.total_skipped) }} skipped</span>
      <span v-if="summary.total_errors" class="pill pill-red">{{ formatCount(summary.total_errors) }} errors</span>
    </div>

    <div class="toolbar">
      <PeriodFilter @change="onPeriodChange" />
      <select v-model="kindFilter">
        <option value="">All kinds</option>
        <option value="daily">Daily</option>
        <option value="resubmit">Resubmit</option>
      </select>
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
      :loading="refreshing"
      @prev="goPage(page - 1)"
      @next="goPage(page + 1)"
    />

    <div class="card table-wrap" :class="{ 'table-wrap--refreshing': refreshing }">
      <table>
        <thead>
          <tr>
            <th>Time</th>
            <th>Kind</th>
            <th>File key</th>
            <th>Location</th>
            <th>Report date</th>
            <th>Status</th>
            <th>Machines</th>
            <th>Imported</th>
            <th>Updated</th>
            <th>Skipped</th>
            <th>Errors</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="initialLoading && !submissions.length">
            <td colspan="11" class="empty">Loading…</td>
          </tr>
          <tr v-else-if="!submissions.length">
            <td colspan="11" class="empty">No submissions match the current filters.</td>
          </tr>
          <tr
            v-for="s in submissions"
            v-else
            :key="s.id"
            :class="{ 'row-failed': s.status === 'failed' }"
          >
            <td>{{ fmtTime(s.created_at) }}</td>
            <td><span :class="kindClass(s.submission_kind)">{{ s.submission_kind }}</span></td>
            <td class="mono">{{ s.idempotency_key || '—' }}</td>
            <td>{{ s.location_id }}<template v-if="s.location_name"> — {{ s.location_name }}</template></td>
            <td>{{ formatReportTimestamp(s.report_date, s.created_at) }}</td>
            <td><span :class="statusClass(s.status)">{{ s.status }}</span></td>
            <td>{{ s.record_count }}</td>
            <td>{{ s.imported_count }}</td>
            <td>{{ s.updated_count }}</td>
            <td>{{ s.skipped_count }}</td>
            <td>{{ s.error_count }}</td>
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
.submissions-page {
  width: 100%;
  max-width: none;
  padding: 0 0.5rem 2rem;
}
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 1rem;
  margin-bottom: 1.25rem;
}
h1 { margin: 0 0 0.25rem; font-size: 1.75rem; font-weight: 700; }
.subtitle { margin: 0; color: var(--muted); font-size: 0.95rem; }
.summary-strip {
  display: flex;
  flex-wrap: wrap;
  gap: 0.5rem;
  margin-bottom: 1rem;
}
.toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.75rem;
  margin-bottom: 1rem;
}
.page-info { margin-left: auto; color: var(--muted); font-size: 0.85rem; }
.table-wrap { overflow-x: auto; }
.table-wrap--refreshing { opacity: 0.92; transition: opacity 0.15s ease; }
.empty { text-align: center; color: var(--muted); padding: 1.5rem !important; }
.mono { font-family: ui-monospace, monospace; font-size: 0.82rem; }
tr.row-failed td { background: color-mix(in srgb, var(--danger, #c0392b) 6%, transparent); }
</style>
