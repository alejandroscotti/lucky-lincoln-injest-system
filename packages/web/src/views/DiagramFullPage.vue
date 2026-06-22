<script setup lang="ts">
import { onMounted, ref, nextTick } from 'vue';
import { useRoute } from 'vue-router';
import { initMermaid } from '../utils/mermaidTheme';
import mermaid from 'mermaid';
import AppHeader from '../components/AppHeader.vue';
import DiagramViewport from '../components/DiagramViewport.vue';

const route = useRoute();
const el = ref<HTMLElement | null>(null);
const viewport = ref<InstanceType<typeof DiagramViewport> | null>(null);
const title = ref('');
const description = ref('');
const error = ref('');

onMounted(async () => {
  initMermaid();
  const type = route.params.type as string;
  try {
    const data = await fetch(`/api/diagrams/mermaid?type=${type}`).then((r) => r.json());
    const d = data.diagrams?.[0];
    if (!d) { error.value = 'Diagram not found'; return; }
    title.value = d.title;
    description.value = d.description;
    await nextTick();
    if (el.value) {
      const id = `mmd-full-${type}`;
      const { svg } = await mermaid.render(id, d.mermaid);
      el.value.innerHTML = svg;
      await nextTick();
      requestAnimationFrame(() => viewport.value?.fitToView());
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load diagram';
  }
});
</script>

<template>
  <AppHeader />
  <div class="full-page">
    <h1>{{ title }}</h1>
    <p class="desc">{{ description }}</p>
    <p v-if="error" class="error">{{ error }}</p>
    <DiagramViewport v-else ref="viewport" expanded>
      <div ref="el" />
    </DiagramViewport>
  </div>
</template>

<style scoped>
.full-page { padding: calc(var(--header-h) + 1rem) 1.5rem 2rem; max-width: none; margin: 0; width: 100%; }
.desc { color: var(--text); max-width: 900px; }
.error { color: var(--red); }
</style>
