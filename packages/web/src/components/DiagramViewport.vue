<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';

const props = defineProps<{
  preview?: boolean;
  expanded?: boolean;
}>();

const viewport = ref<HTMLElement | null>(null);
const content = ref<HTMLElement | null>(null);

const scale = ref(1);
const translateX = ref(0);
const translateY = ref(0);
const panning = ref(false);
const spaceHeld = ref(false);

const MIN_SCALE = 0.15;
const MAX_SCALE = 4;
const ZOOM_FACTOR = 1.12;

let panStartX = 0;
let panStartY = 0;
let panOriginX = 0;
let panOriginY = 0;

const transformStyle = computed(() => ({
  transform: `translate(${translateX.value}px, ${translateY.value}px) scale(${scale.value})`,
  transformOrigin: '0 0',
}));

const zoomLabel = computed(() => `${Math.round(scale.value * 100)}%`);

function clampScale(value: number): number {
  return Math.min(MAX_SCALE, Math.max(MIN_SCALE, value));
}

function zoomAt(factor: number, clientX?: number, clientY?: number) {
  const vp = viewport.value;
  if (!vp) return;

  const rect = vp.getBoundingClientRect();
  const px = clientX ?? rect.left + rect.width / 2;
  const py = clientY ?? rect.top + rect.height / 2;
  const anchorX = px - rect.left;
  const anchorY = py - rect.top;

  const oldScale = scale.value;
  const newScale = clampScale(oldScale * factor);
  if (newScale === oldScale) return;

  const ratio = newScale / oldScale;
  translateX.value = anchorX - (anchorX - translateX.value) * ratio;
  translateY.value = anchorY - (anchorY - translateY.value) * ratio;
  scale.value = newScale;
}

function zoomIn(clientX?: number, clientY?: number) {
  zoomAt(ZOOM_FACTOR, clientX, clientY);
}

function zoomOut(clientX?: number, clientY?: number) {
  zoomAt(1 / ZOOM_FACTOR, clientX, clientY);
}

function normalizeSvg(svg: SVGSVGElement): { width: number; height: number } | null {
  const vb = svg.viewBox.baseVal;
  if (vb.width <= 0 || vb.height <= 0) return null;

  svg.removeAttribute('width');
  svg.removeAttribute('height');
  svg.style.maxWidth = 'none';
  svg.style.width = `${vb.width}px`;
  svg.style.height = `${vb.height}px`;

  return { width: vb.width, height: vb.height };
}

function getSvgSize(svg: SVGSVGElement): { width: number; height: number } | null {
  const fromViewBox = normalizeSvg(svg);
  if (fromViewBox) return fromViewBox;

  const rect = svg.getBoundingClientRect();
  if (rect.width <= 0 || rect.height <= 0) return null;
  return { width: rect.width, height: rect.height };
}

function resetView() {
  const inner = content.value;
  const svg = inner?.querySelector('svg');
  if (svg instanceof SVGSVGElement) normalizeSvg(svg);

  scale.value = 1;
  translateX.value = 0;
  translateY.value = 0;
  centerContent();
}

function centerContent() {
  const vp = viewport.value;
  const inner = content.value;
  if (!vp || !inner) return;

  const svg = inner.querySelector('svg');
  if (!(svg instanceof SVGSVGElement)) return;

  const size = getSvgSize(svg);
  if (!size) return;

  const vpRect = vp.getBoundingClientRect();
  translateX.value = Math.max(0, (vpRect.width - size.width * scale.value) / 2);
  translateY.value = Math.max(0, (vpRect.height - size.height * scale.value) / 2);
}

function fitToView() {
  const vp = viewport.value;
  const inner = content.value;
  if (!vp || !inner) return;

  const svg = inner.querySelector('svg');
  if (!(svg instanceof SVGSVGElement)) return;

  scale.value = 1;
  translateX.value = 0;
  translateY.value = 0;

  const size = getSvgSize(svg);
  if (!size) return;

  const vpRect = vp.getBoundingClientRect();
  const padding = 16;
  const fitScale = Math.min(
    (vpRect.width - padding * 2) / size.width,
    (vpRect.height - padding * 2) / size.height,
  );

  scale.value = clampScale(fitScale);
  centerContent();
}

function canPan(event: MouseEvent): boolean {
  return event.button === 0;
}

function onPointerDown(event: MouseEvent) {
  if (!canPan(event)) return;
  event.preventDefault();
  panning.value = true;
  panStartX = event.clientX;
  panStartY = event.clientY;
  panOriginX = translateX.value;
  panOriginY = translateY.value;
}

function onWindowPointerMove(event: MouseEvent) {
  if (!panning.value) return;
  translateX.value = panOriginX + (event.clientX - panStartX);
  translateY.value = panOriginY + (event.clientY - panStartY);
}

function onPointerUp() {
  panning.value = false;
}

function onWheel(event: WheelEvent) {
  event.preventDefault();
  if (event.ctrlKey || event.metaKey) {
    const factor = event.deltaY > 0 ? 1 / ZOOM_FACTOR : ZOOM_FACTOR;
    zoomAt(factor, event.clientX, event.clientY);
    return;
  }
  const factor = event.deltaY > 0 ? 1 / ZOOM_FACTOR : ZOOM_FACTOR;
  zoomAt(factor, event.clientX, event.clientY);
}

function onKeyDown(event: KeyboardEvent) {
  if (event.code === 'Space' && !spaceHeld.value) {
    spaceHeld.value = true;
  }

  if (!(event.ctrlKey || event.metaKey)) return;

  const target = event.target;
  if (target instanceof HTMLElement) {
    const tag = target.tagName;
    if (tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT' || target.isContentEditable) {
      return;
    }
  }

  if (event.key === '=' || event.key === '+') {
    event.preventDefault();
    zoomIn();
  } else if (event.key === '-' || event.key === '_') {
    event.preventDefault();
    zoomOut();
  } else if (event.key === '0') {
    event.preventDefault();
    resetView();
  }
}

function onKeyUp(event: KeyboardEvent) {
  if (event.code === 'Space') {
    spaceHeld.value = false;
  }
}

function focusViewport() {
  viewport.value?.focus();
}

defineExpose({ fitToView, resetView, zoomIn, zoomOut, focusViewport });

onMounted(() => {
  window.addEventListener('keydown', onKeyDown);
  window.addEventListener('keyup', onKeyUp);
  window.addEventListener('mousemove', onWindowPointerMove);
  window.addEventListener('mouseup', onPointerUp);
});

onUnmounted(() => {
  window.removeEventListener('keydown', onKeyDown);
  window.removeEventListener('keyup', onKeyUp);
  window.removeEventListener('mousemove', onWindowPointerMove);
  window.removeEventListener('mouseup', onPointerUp);
});
</script>

<template>
  <div
    class="diagram-viewport"
    :class="{ preview, expanded, panning, 'space-pan': spaceHeld }"
  >
    <div class="diagram-toolbar" role="toolbar" aria-label="Diagram zoom controls">
      <button type="button" class="btn secondary diagram-btn" title="Zoom out (Ctrl −)" @click="zoomOut()">−</button>
      <span class="diagram-zoom-label" aria-live="polite">{{ zoomLabel }}</span>
      <button type="button" class="btn secondary diagram-btn" title="Zoom in (Ctrl +)" @click="zoomIn()">+</button>
      <button type="button" class="btn secondary diagram-btn" title="Fit to view" @click="fitToView()">Fit</button>
      <button type="button" class="btn secondary diagram-btn" title="Reset zoom (Ctrl 0)" @click="resetView()">Reset</button>
    </div>

    <div
      ref="viewport"
      class="diagram-stage"
      tabindex="0"
      aria-label="Diagram canvas. Drag to pan. Scroll or Ctrl +/− to zoom."
      @mousedown="onPointerDown"
      @mouseup="onPointerUp"
      @mouseleave="onPointerUp"
      @wheel="onWheel"
      @focusin="focusViewport"
    >
      <div class="diagram-transform-layer">
        <div ref="content" class="diagram-content" :style="transformStyle">
          <slot />
        </div>
      </div>
    </div>

    <p class="diagram-hint">
      Drag to pan · Scroll to zoom · <kbd>Ctrl</kbd>+<kbd>+</kbd> / <kbd>−</kbd> · <kbd>Ctrl</kbd>+<kbd>0</kbd> reset
    </p>
  </div>
</template>

<style scoped>
.diagram-viewport {
  border: 1px solid var(--border);
  border-radius: 6px;
  background: var(--surface);
  overflow: hidden;
}

.diagram-toolbar {
  display: flex;
  align-items: center;
  gap: 0.35rem;
  padding: 0.45rem 0.6rem;
  border-bottom: 1px solid var(--border);
  background: var(--surface);
  color: var(--text);
}

.diagram-btn {
  min-width: 2.25rem;
  padding: 0.35rem 0.55rem;
  font-size: 0.95rem;
  line-height: 1;
}

.diagram-zoom-label {
  min-width: 3.5rem;
  text-align: center;
  font-size: 0.8rem;
  color: var(--text);
  font-variant-numeric: tabular-nums;
}

.diagram-stage {
  position: relative;
  width: 100%;
  min-height: 420px;
  height: 56vh;
  overflow: hidden;
  cursor: grab;
  touch-action: none;
  outline: none;
  background: #ffffff;
}

.diagram-viewport.preview .diagram-stage {
  min-height: 360px;
  height: 480px;
}

.diagram-viewport.preview.diagram-viewport--tall .diagram-stage {
  height: 560px;
}

.diagram-viewport.expanded .diagram-stage {
  min-height: 60vh;
  height: calc(100vh - var(--header-h) - 12rem);
}

.diagram-viewport.panning .diagram-stage,
.diagram-viewport.space-pan .diagram-stage {
  cursor: grabbing;
}

.diagram-transform-layer {
  position: absolute;
  inset: 0;
  overflow: visible;
}

.diagram-content {
  display: inline-block;
  min-width: min-content;
}

.diagram-content :deep(svg) {
  display: block;
  max-width: none !important;
  height: auto;
}

.diagram-hint {
  margin: 0;
  padding: 0.45rem 0.65rem;
  font-size: 0.75rem;
  color: var(--muted);
  border-top: 1px solid var(--border);
}

.diagram-hint kbd {
  display: inline-block;
  padding: 0.05rem 0.35rem;
  border-radius: 4px;
  border: 1px solid var(--border);
  background: var(--surface);
  font-size: 0.7rem;
  font-family: inherit;
}
</style>
