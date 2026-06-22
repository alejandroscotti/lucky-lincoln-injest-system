<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

export interface LocationOption {
  location_id: string;
  location_name: string;
}

const props = defineProps<{
  locations: LocationOption[];
  modelValue: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: string];
  change: [];
}>();

const open = ref(false);
const query = ref('');
const root = ref<HTMLElement | null>(null);

const sorted = computed(() =>
  [...props.locations].sort((a, b) =>
    a.location_name.localeCompare(b.location_name, undefined, { sensitivity: 'base' })
  )
);

const filtered = computed(() => {
  const q = query.value.trim().toLowerCase();
  if (!q) return sorted.value;
  return sorted.value.filter(
    (l) =>
      l.location_id.toLowerCase().includes(q) ||
      l.location_name.toLowerCase().includes(q) ||
      `${l.location_id} - ${l.location_name}`.toLowerCase().includes(q)
  );
});

const selectedLabel = computed(() => {
  if (!props.modelValue) return 'All locations';
  const loc = props.locations.find((l) => l.location_id === props.modelValue);
  return loc ? `${loc.location_id} - ${loc.location_name}` : props.modelValue;
});

function label(loc: LocationOption) {
  return `${loc.location_id} - ${loc.location_name}`;
}

function select(loc: LocationOption | null) {
  emit('update:modelValue', loc?.location_id || '');
  emit('change');
  open.value = false;
  query.value = '';
}

function onClickOutside(e: MouseEvent) {
  if (root.value && !root.value.contains(e.target as Node)) open.value = false;
}

onMounted(() => document.addEventListener('click', onClickOutside));
onUnmounted(() => document.removeEventListener('click', onClickOutside));
</script>

<template>
  <div ref="root" class="loc-picker">
    <button type="button" class="trigger" @click.stop="open = !open">
      <span class="trigger-label">{{ selectedLabel }}</span>
      <span class="chevron">{{ open ? '▴' : '▾' }}</span>
    </button>
    <div v-if="open" class="dropdown" @click.stop>
      <input
        v-model="query"
        class="search"
        type="text"
        placeholder="Search locations…"
        autofocus
      />
      <ul class="list">
        <li>
          <button type="button" class="option" :class="{ active: !modelValue }" @click="select(null)">
            All locations
          </button>
        </li>
        <li v-for="loc in filtered" :key="loc.location_id">
          <button
            type="button"
            class="option"
            :class="{ active: modelValue === loc.location_id }"
            @click="select(loc)"
          >
            {{ label(loc) }}
          </button>
        </li>
        <li v-if="!filtered.length" class="empty">No matches</li>
      </ul>
    </div>
  </div>
</template>

<style scoped>
.loc-picker { position: relative; min-width: 280px; }

.trigger {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 0.5rem;
  background: var(--surface);
  color: var(--text);
  border: 1px solid var(--border);
  border-radius: 6px;
  padding: 0.45rem 0.65rem;
  cursor: pointer;
  text-align: left;
}

.trigger-label {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  font-size: 0.9rem;
}

.chevron { color: var(--muted); font-size: 0.75rem; }

.dropdown {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  z-index: 50;
  background: var(--surface);
  border: 1px solid var(--border);
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.35);
  overflow: hidden;
}

.search {
  width: 100%;
  border: none;
  border-bottom: 1px solid var(--border);
  background: #121a26;
  color: var(--text);
  padding: 0.5rem 0.65rem;
  font-size: 0.9rem;
  outline: none;
}

.list {
  list-style: none;
  margin: 0;
  padding: 0.25rem 0;
  max-height: 320px;
  overflow-y: auto;
}

.option {
  width: 100%;
  text-align: left;
  background: transparent;
  border: none;
  color: var(--text);
  padding: 0.45rem 0.75rem;
  cursor: pointer;
  font-size: 0.85rem;
}

.option:hover, .option.active { background: #1a2f4a; color: var(--blue); }

.empty {
  padding: 0.6rem 0.75rem;
  color: var(--muted);
  font-size: 0.85rem;
}
</style>
