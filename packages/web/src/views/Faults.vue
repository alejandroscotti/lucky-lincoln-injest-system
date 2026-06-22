<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import PeriodFilter from '../components/PeriodFilter.vue';
import { usePeriodFilter } from '../composables/usePeriodFilter';
import { useAppStore } from '../stores/app';
import { formatReportTimestamp } from '../utils/datetime';
import { faultLabel } from '../utils/labels';

interface Fault {
  location_id: string;
  location_name: string;
  machine_id: string;
  report_date: string;
  submitted_at?: string;
  fault_type: string;
  severity: string;
  delta: number;
  description: string;
  expected_value: number;
  reported_value: number;
  net_revenue: number;
  computed_net_revenue: number;
}

const faults = ref<Fault[]>([]);
const faultType = ref('');
const faultTypes = ref<string[]>([]);
const store = useAppStore();

const { applyToParams } = usePeriodFilter();

async function load() {
  const params = new URLSearchParams({ limit: '200' });
  if (faultType.value) params.set('fault_type', faultType.value);
  applyToParams(params);
  faults.value = await fetch(`/api/transactions/faults?${params}`).then((r) => r.json());
}

const downloadUrl = computed(() => {
  const params = new URLSearchParams();
  if (faultType.value) params.set('fault_type', faultType.value);
  applyToParams(params);
  const qs = params.toString();
  return `/api/reports/transaction-faults.xlsx${qs ? `?${qs}` : ''}`;
});

function fmt(n: number) {
  return `$${Number(n).toFixed(2)}`;
}

function isOverReport(type: string) {
  return type === 'overreported_net';
}

function onPeriodChange() {
  load();
}

onMounted(async () => {
  const meta = await fetch('/api/meta/fault-types').then((r) => r.json());
  faultTypes.value = meta.fault_types || [];
  if (meta.labels) store.faultTypeLabels = meta.labels;
  await load();
});
</script>

<template>
  <h1>Transaction Faults</h1>
  <div class="filters">
    <PeriodFilter @change="onPeriodChange" />
    <select v-model="faultType" @change="load">
      <option value="">All fault types</option>
      <option v-for="t in faultTypes" :key="t" :value="t">{{ faultLabel(t, store.faultTypeLabels) }}</option>
    </select>
    <button class="btn secondary" @click="load">Refresh</button>
    <a class="btn" :href="downloadUrl" download>Download XLSX</a>
  </div>
  <div class="card">
    <table>
      <thead>
        <tr>
          <th>Type</th>
          <th>Severity</th>
          <th>Location</th>
          <th>Machine</th>
          <th>Date</th>
          <th>Reported</th>
          <th>Expected</th>
          <th>Delta</th>
          <th>Description</th>
        </tr>
      </thead>
      <tbody>
        <tr
          v-for="(f, i) in faults"
          :key="i"
          :class="isOverReport(f.fault_type) ? 'fault-over' : 'fault-under'"
        >
          <td><span class="badge" :class="isOverReport(f.fault_type) ? 'blue' : 'red'">{{ faultLabel(f.fault_type, store.faultTypeLabels) }}</span></td>
          <td>{{ f.severity }}</td>
          <td>{{ f.location_id }} — {{ f.location_name }}</td>
          <td>{{ f.machine_id }}</td>
          <td>{{ formatReportTimestamp(f.report_date, f.submitted_at) }}</td>
          <td>{{ fmt(f.reported_value) }}</td>
          <td>{{ fmt(f.expected_value) }}</td>
          <td>{{ fmt(f.delta) }}</td>
          <td>{{ f.description }}</td>
        </tr>
      </tbody>
    </table>
  </div>
</template>
