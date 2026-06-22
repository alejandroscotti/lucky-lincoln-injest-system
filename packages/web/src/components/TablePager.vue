<script setup lang="ts">
defineProps<{
  page: number;
  totalPages: number;
  loading?: boolean;
}>();

const emit = defineEmits<{
  prev: [];
  next: [];
}>();
</script>

<template>
  <div class="pager">
    <button class="btn secondary" :disabled="page <= 0 || loading" @click="emit('prev')">Previous</button>
    <span>Page {{ page + 1 }} of {{ totalPages }}</span>
    <button class="btn secondary" :disabled="page >= totalPages - 1 || loading" @click="emit('next')">Next</button>
  </div>
</template>

<style scoped>
.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 1rem;
  margin: 1rem 0;
  color: var(--muted);
  font-size: 0.9rem;
}
</style>
