<script setup lang="ts">
import { onMounted, ref, watch, nextTick } from 'vue';
import { useRouter } from 'vue-router';
import { initMermaid } from '../utils/mermaidTheme';
import mermaid from 'mermaid';
import DiagramViewport from './DiagramViewport.vue';

const props = defineProps<{
  type: string;
  title: string;
  description: string;
  mermaid: string;
  preview?: boolean;
}>();

const router = useRouter();
const el = ref<HTMLElement | null>(null);
const viewport = ref<InstanceType<typeof DiagramViewport> | null>(null);

async function render() {
  await nextTick();
  if (!el.value || !props.mermaid) return;
  el.value.innerHTML = '';
  const id = `mmd-${props.type}-${Date.now()}`;
  const { svg } = await mermaid.render(id, props.mermaid);
  el.value.innerHTML = svg;
  await nextTick();
  requestAnimationFrame(() => viewport.value?.fitToView());
}

function openFullPage() {
  router.push(`/diagram/${props.type}`);
}

onMounted(() => {
  initMermaid();
  render();
});
watch(() => props.mermaid, render);
</script>

<template>
  <div class="card diagram-card">
    <div class="head">
      <div>
        <h3>{{ title }}</h3>
        <p class="desc">{{ description }}</p>
      </div>
      <div class="actions">
        <button type="button" class="btn" @click="openFullPage">Open full page</button>
      </div>
    </div>
    <DiagramViewport
      ref="viewport"
      preview
      :class="{ 'diagram-viewport--tall': type === 'idempotency' }"
    >
      <div ref="el" />
    </DiagramViewport>
  </div>
</template>

<style scoped>
.head { display: flex; justify-content: space-between; align-items: flex-start; gap: 1rem; margin-bottom: 0.75rem; }
h3 { margin: 0 0 0.25rem; }
.desc { margin: 0; color: var(--text); font-size: 0.85rem; max-width: 600px; }
</style>
