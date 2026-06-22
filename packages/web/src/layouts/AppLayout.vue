<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import AppHeader from '../components/AppHeader.vue';
import { useLiveStream } from '../composables/useLiveStream';
import { useAppStore } from '../stores/app';
import { formatCount, formatCurrency } from '../utils/labels';

useLiveStream();
const store = useAppStore();
onMounted(() => store.loadMeta());
const route = useRoute();

const isFluid = computed(() => ['dashboard', 'live', 'locations', 'reconcile', 'faults', 'diagrams'].includes(String(route.name)));

const hasExceptions = computed(() => store.faultyCount > 0 || store.shortfallCount > 0);
</script>

<template>
  <AppHeader />
  <div class="page-wrap">
    <div class="status-bar">
      <div class="status-positive">
        <span class="pill pill-green">{{ formatCount(store.cleanCount) }} clean transactions</span>
        <span class="pill pill-green">{{ store.cleanPct.toFixed(1) }}% validation pass rate</span>
        <span class="pill pill-blue">{{ formatCurrency(store.netRevenueToday) }} revenue today</span>
        <span class="pill pill-green">{{ formatCount(store.matchedCount) }} locations meeting expected</span>
      </div>
      <div v-if="hasExceptions" class="status-exceptions">
        <span class="pill pill-red">{{ formatCount(store.faultyCount) }} faults</span>
        <span class="pill pill-red">{{ formatCount(store.shortfallCount) }} shortfalls</span>
        <router-link to="/faults" class="exception-link">Review exceptions →</router-link>
      </div>
    </div>
    <main class="main-content" :class="{ 'main-content--fluid': isFluid }">
      <router-view />
    </main>
  </div>
</template>

<style scoped>
.page-wrap { padding-top: var(--header-h); min-height: 100vh; }

.status-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: space-between;
  gap: 0.75rem;
  margin: 0.75rem 1.5rem 0;
  padding: 0.65rem 1rem;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
}

.status-positive, .status-exceptions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0.5rem;
}

.exception-link {
  font-size: 0.8rem;
  color: var(--muted);
  margin-left: 0.25rem;
}

.exception-link:hover { color: var(--blue); }
</style>
