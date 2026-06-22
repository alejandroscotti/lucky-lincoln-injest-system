<script setup lang="ts">
import { onMounted, ref } from 'vue';
import DiagramCard from '../components/DiagramCard.vue';

interface Diagram {
  type: string;
  title: string;
  description: string;
  mermaid: string;
}

const diagrams = ref<Diagram[]>([]);
const error = ref('');

onMounted(async () => {
  try {
    const res = await fetch('/api/diagrams/mermaid?type=all');
    const data = await res.json();
    if (!res.ok) {
      error.value = data.error || `Failed to load diagrams (${res.status})`;
      return;
    }
    diagrams.value = data.diagrams || [];
    if (diagrams.value.length === 0) {
      error.value = 'No diagrams returned from API.';
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load diagrams';
  }
});
</script>

<template>
  <h1>Architecture Diagrams</h1>
  <p class="muted">
    Live diagrams from the API. Each persisted location submits revenue via
    <code>POST /api/revenue/import</code> with <code>x-source: LOC-xxx</code>.
    The locations-feed command simulates all partners in this prototype.
    The idempotency diagram is the authoritative reference for import behaviour.
  </p>
  <p v-if="error" class="error">{{ error }}</p>
  <DiagramCard
    v-for="d in diagrams"
    :key="d.type"
    :type="d.type"
    :title="d.title"
    :description="d.description"
    :mermaid="d.mermaid"
    preview
  />
</template>

<style scoped>
.muted { color: var(--text); margin-bottom: 1rem; }
.error { color: #e74c5c; margin-bottom: 1rem; }
</style>
