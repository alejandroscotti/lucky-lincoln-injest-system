<script setup lang="ts">
import { usePeriodFilter } from '../composables/usePeriodFilter';
import type { PeriodType } from '../utils/periodFilter';

const emit = defineEmits<{ change: [] }>();

const { state, label, isActive, setType } = usePeriodFilter();

const presets: { type: PeriodType; label: string }[] = [
  { type: 'day', label: 'Day' },
  { type: 'month', label: 'Month' },
  { type: 'quarter', label: 'Quarter' },
  { type: 'year', label: 'Year' },
  { type: 'all', label: 'All' },
];

function pick(type: PeriodType) {
  setType(type);
  emit('change');
}

function onInputChange() {
  emit('change');
}
</script>

<template>
  <div class="period-filter" :class="{ active: isActive }">
    <span class="period-label">Period</span>
    <div class="preset-group" role="group" aria-label="Filter by period">
      <button
        v-for="p in presets"
        :key="p.type"
        type="button"
        class="preset-btn"
        :class="{ active: state.type === p.type }"
        @click="pick(p.type)"
      >
        {{ p.label }}
      </button>
    </div>

    <input
      v-if="state.type === 'day'"
      v-model="state.day"
      type="date"
      class="period-input"
      aria-label="Select day"
      @change="onInputChange"
    />
    <input
      v-else-if="state.type === 'month'"
      v-model="state.month"
      type="month"
      class="period-input"
      aria-label="Select month"
      @change="onInputChange"
    />
    <template v-else-if="state.type === 'quarter'">
      <select v-model.number="state.quarter" class="period-input" aria-label="Select quarter" @change="onInputChange">
        <option :value="1">Q1</option>
        <option :value="2">Q2</option>
        <option :value="3">Q3</option>
        <option :value="4">Q4</option>
      </select>
      <input
        v-model.number="state.year"
        type="number"
        class="period-input period-input--year"
        min="2000"
        max="2100"
        aria-label="Select year"
        @change="onInputChange"
      />
    </template>
    <input
      v-else-if="state.type === 'year'"
      v-model.number="state.year"
      type="number"
      class="period-input period-input--year"
      min="2000"
      max="2100"
      aria-label="Select year"
      @change="onInputChange"
    />

    <span v-if="isActive" class="period-range">{{ label }}</span>
  </div>
</template>

<style scoped>
.period-filter {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;
  padding: 0.35rem 0.6rem;
  border-radius: 6px;
  border: 1px solid var(--border);
  background: rgba(255, 255, 255, 0.02);
}
.period-filter.active {
  border-color: rgba(74, 158, 255, 0.45);
  background: rgba(74, 158, 255, 0.06);
}
.period-label {
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: var(--muted);
}
.preset-group {
  display: flex;
  gap: 0.2rem;
}
.preset-btn {
  border: 1px solid var(--border);
  background: transparent;
  color: var(--muted);
  font-size: 0.78rem;
  font-weight: 500;
  padding: 0.2rem 0.55rem;
  border-radius: 4px;
  cursor: pointer;
}
.preset-btn:hover {
  color: var(--text);
  border-color: rgba(74, 158, 255, 0.4);
}
.preset-btn.active {
  color: #fff;
  background: var(--blue);
  border-color: var(--blue);
}
.period-input {
  font-size: 0.82rem;
  padding: 0.2rem 0.45rem;
  border-radius: 4px;
  border: 1px solid var(--border);
  background: var(--bg);
  color: var(--text);
}
.period-input--year {
  width: 5rem;
}
.period-range {
  font-size: 0.78rem;
  color: var(--blue);
  white-space: nowrap;
}
</style>
