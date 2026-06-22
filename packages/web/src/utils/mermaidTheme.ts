import mermaid from 'mermaid';

let initialized = false;

export function initMermaid(): void {
  if (initialized) return;
  mermaid.initialize({ startOnLoad: false, theme: 'default' });
  initialized = true;
}
