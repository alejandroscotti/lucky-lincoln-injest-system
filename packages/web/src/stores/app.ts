import { defineStore } from 'pinia';
import { ref } from 'vue';

export const useAppStore = defineStore('app', () => {
  const connected = ref(false);
  const faultyCount = ref(0);
  const cleanCount = ref(0);
  const totalRecords = ref(0);
  const cleanPct = ref(100);
  const shortfallCount = ref(0);
  const matchedCount = ref(0);
  const netRevenueToday = ref(0);
  const totalDelta = ref(0);
  const recentRecords = ref<Record<string, unknown>[]>([]);
  const faultTypeLabels = ref<Record<string, string>>({});

  function addRecent(record: Record<string, unknown>) {
    recentRecords.value.unshift(record);
    if (recentRecords.value.length > 200) recentRecords.value.pop();
  }

  async function loadMeta() {
    try {
      const res = await fetch('/api/meta/fault-types');
      if (!res.ok) return;
      const data = await res.json();
      if (data.labels && typeof data.labels === 'object') {
        faultTypeLabels.value = data.labels;
      }
    } catch {
      // ignore — faultLabel falls back to code formatting
    }
  }

  return {
    connected, faultyCount, cleanCount, totalRecords, cleanPct,
    shortfallCount, matchedCount, netRevenueToday, totalDelta,
    recentRecords, faultTypeLabels, addRecent, loadMeta,
  };
});
