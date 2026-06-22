<script setup lang="ts">
import { computed } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import { useAppStore } from '../stores/app';
import logoUrl from '../assets/llg-logo.svg';

const store = useAppStore();
const route = useRoute();
const isFullDiagram = computed(() => route.name === 'diagram-full');

const links = [
  { to: '/dashboard', label: 'Dashboard' },
  { to: '/live', label: 'Live Feed' },
  { to: '/submissions', label: 'Submissions' },
  { to: '/locations', label: 'Locations' },
  { to: '/reconcile', label: 'Reconcile' },
  { to: '/faults', label: 'Faults' },
];
</script>

<template>
  <header class="header" :class="{ mini: isFullDiagram }">
    <div class="inner">
      <div class="brand-group">
        <RouterLink to="/dashboard" class="logo-link" aria-label="Dashboard">
          <img :src="logoUrl" alt="" class="logo-img" />
        </RouterLink>
        <RouterLink to="/dashboard" class="title-link">
          <span class="app-name">Revenue Reconciliation</span>
        </RouterLink>
        <RouterLink
          v-if="!isFullDiagram"
          to="/diagrams"
          class="nav-link nav-link--diagrams"
        >
          System Diagrams
        </RouterLink>
        <RouterLink
          v-else
          to="/diagrams"
          class="nav-link nav-link--diagrams nav-link--diagrams-back"
        >
          ← System Diagrams
        </RouterLink>
      </div>

      <nav v-if="!isFullDiagram" class="main-nav">
        <RouterLink v-for="l in links" :key="l.to" :to="l.to" class="nav-link">
          {{ l.label }}
          <span v-if="l.to === '/faults' && store.faultyCount" class="nav-badge red">{{ store.faultyCount }}</span>
          <span v-if="l.to === '/reconcile' && store.shortfallCount" class="nav-badge red">{{ store.shortfallCount }}</span>
        </RouterLink>
      </nav>

      <span class="status" :class="{ on: store.connected }">{{ store.connected ? '● Connected' : '○ Disconnected' }}</span>
    </div>
  </header>
</template>

<style scoped>
.header {
  position: fixed; top: 0; left: 0; right: 0; height: var(--header-h);
  background: #121a26; border-bottom: 1px solid var(--border); z-index: 1000;
  box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
.inner {
  display: flex;
  align-items: center;
  gap: 1.25rem;
  height: 100%;
  padding: 0 1.5rem;
  max-width: 1600px;
  margin: 0 auto;
}
.brand-group {
  display: flex;
  align-items: center;
  gap: 0.65rem;
  flex-shrink: 0;
}
.logo-link,
.title-link {
  display: flex;
  align-items: center;
  text-decoration: none;
  flex-shrink: 0;
}
.logo-img {
  height: 36px;
  width: auto;
  display: block;
}
.app-name {
  font-weight: 600;
  font-size: 1rem;
  color: var(--text);
  white-space: nowrap;
  letter-spacing: 0.01em;
}
.title-link:hover .app-name {
  color: #fff;
}
.main-nav {
  display: flex;
  align-items: center;
  gap: 0.75rem;
  flex: 1;
  flex-wrap: wrap;
  min-width: 0;
}
.nav-link {
  color: var(--text);
  font-size: 0.9rem;
  padding: 0.25rem 0.5rem;
  border-radius: 4px;
  text-decoration: none;
}
.nav-link.router-link-active { color: var(--blue); background: #1a2f4a; }
.nav-link--diagrams {
  margin-left: 0.15rem;
  font-size: 0.85rem;
  font-weight: 600;
  letter-spacing: 0.03em;
  text-transform: uppercase;
  color: #c4b5fd;
  border: 1px solid rgba(167, 139, 250, 0.45);
  background: linear-gradient(135deg, rgba(76, 29, 149, 0.35) 0%, rgba(30, 58, 95, 0.5) 100%);
  padding: 0.35rem 0.75rem;
  border-radius: 6px;
  box-shadow: 0 0 12px rgba(139, 92, 246, 0.15);
  white-space: nowrap;
}
.nav-link--diagrams-back {
  text-transform: none;
  letter-spacing: normal;
  font-size: 0.8rem;
}
.nav-link--diagrams:hover {
  color: #e9d5ff;
  border-color: rgba(167, 139, 250, 0.7);
  background: linear-gradient(135deg, rgba(91, 33, 182, 0.45) 0%, rgba(30, 58, 95, 0.6) 100%);
}
.nav-link--diagrams.router-link-active {
  color: #f3e8ff;
  border-color: #a78bfa;
  background: linear-gradient(135deg, rgba(109, 40, 217, 0.55) 0%, rgba(30, 64, 115, 0.65) 100%);
  box-shadow: 0 0 16px rgba(139, 92, 246, 0.3);
}
.nav-badge { background: var(--blue); color: #fff; font-size: 0.7rem; padding: 0.1rem 0.35rem; border-radius: 8px; margin-left: 0.25rem; }
.nav-badge.red { background: var(--red); }
.status { font-size: 0.8rem; color: var(--text); white-space: nowrap; margin-left: auto; flex-shrink: 0; }
.status.on { color: var(--green); }
</style>
