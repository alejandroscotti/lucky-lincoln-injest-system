import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { onMounted, onUnmounted } from 'vue';
import { useAppStore } from '../stores/app';
import { usePeriodFilter } from './usePeriodFilter';

declare global {
  interface Window {
    Pusher: typeof Pusher;
  }
}

interface ReverbConfig {
  enabled: boolean;
  key?: string;
  host?: string;
  port?: number;
  scheme?: string;
}

const submissionListeners = new Set<() => void>();
const dashboardListeners = new Set<(data: Record<string, unknown>) => void>();

let refreshGeneration = 0;
let activeController: AbortController | undefined;
let activeTimeout: ReturnType<typeof setTimeout> | undefined;
let dashboardFetchInFlight = false;
let dashboardFetchQueued = false;
let echoClient: Echo<'reverb'> | undefined;
let streamStarted = false;
let pushRefreshTimer: ReturnType<typeof setTimeout> | undefined;
let pendingPushScope = 'import';
let lifecycleBound = false;
let lastDashboardPayload: Record<string, unknown> | undefined;
let pollTimer: ReturnType<typeof setInterval> | undefined;
let reverbConfigPromise: Promise<ReverbConfig> | undefined;

const POLL_MS = 15_000;

function applyDashboardToStore(store: ReturnType<typeof useAppStore>, dash: Record<string, unknown>) {
  const k = (dash.kpis || {}) as Record<string, unknown>;
  store.totalRecords = Number(k.total_records || 0);
  store.cleanCount = Number(k.clean_records || 0);
  store.cleanPct = Number(k.clean_pct || 0);
  store.faultyCount = Number(k.faulty_count || 0);
  store.matchedCount = Number(k.matched_locations || 0);
  store.netRevenueToday = Number(k.net_revenue_today || 0);
  store.shortfallCount = Number(k.shortfall_locations || 0);
  store.totalDelta = Number(k.total_delta || 0);
}

function clearActiveRequest() {
  if (activeTimeout) {
    clearTimeout(activeTimeout);
    activeTimeout = undefined;
  }
  activeController?.abort();
  activeController = undefined;
}

async function fetchDashboard(store: ReturnType<typeof useAppStore>): Promise<void> {
  if (document.visibilityState === 'hidden') {
    return;
  }
  if (dashboardFetchInFlight) {
    dashboardFetchQueued = true;
    return;
  }

  dashboardFetchInFlight = true;
  clearActiveRequest();
  const generation = ++refreshGeneration;
  activeController = new AbortController();
  activeTimeout = setTimeout(() => activeController?.abort(), 10_000);

  const { applyToParams } = usePeriodFilter();
  const params = new URLSearchParams();
  applyToParams(params);
  const qs = params.toString();

  try {
    const dashRes = await fetch(`/api/revenue/dashboard${qs ? `?${qs}` : ''}`, {
      signal: activeController.signal,
    });
    if (generation !== refreshGeneration) {
      return;
    }
    if (!dashRes.ok) {
      throw new Error('dashboard failed');
    }
    const dash = (await dashRes.json()) as Record<string, unknown>;
    lastDashboardPayload = dash;
    applyDashboardToStore(store, dash);
    for (const fn of dashboardListeners) {
      fn(dash);
    }
  } catch (e) {
    if (e instanceof DOMException && e.name === 'AbortError') {
      return;
    }
  } finally {
    if (generation === refreshGeneration) {
      clearActiveRequest();
    }
    dashboardFetchInFlight = false;
    if (dashboardFetchQueued) {
      dashboardFetchQueued = false;
      void fetchDashboard(store);
    }
  }
}

function notifySubmissionListeners() {
  for (const fn of submissionListeners) {
    fn();
  }
}

function runPushRefresh() {
  pushRefreshTimer = undefined;
  const scope = pendingPushScope;
  void fetchDashboard(useAppStore());
  if (scope === 'import' || scope === 'submissions') {
    notifySubmissionListeners();
  }
}

/** Coalesce burst import events — one refresh, no overlapping in-flight fetches. */
function schedulePushRefresh(scope: string) {
  pendingPushScope = scope;
  if (pushRefreshTimer) {
    clearTimeout(pushRefreshTimer);
  }
  pushRefreshTimer = setTimeout(runPushRefresh, 500);
}

function resolvePort(config: ReverbConfig): number {
  const pagePort = Number(window.location.port);
  if (pagePort > 0) {
    return pagePort;
  }
  if (config.port && config.port > 0) {
    return config.port;
  }
  return window.location.protocol === 'https:' ? 443 : 80;
}

function resolveHost(config: ReverbConfig): string {
  const configured = config.host?.trim();
  if (configured && configured !== 'localhost' && configured !== '127.0.0.1') {
    return configured;
  }
  return window.location.hostname;
}

function resolveScheme(config: ReverbConfig): 'http' | 'https' {
  if (config.scheme === 'http' || config.scheme === 'https') {
    return config.scheme;
  }
  return window.location.protocol === 'https:' ? 'https' : 'http';
}

async function loadReverbConfig(): Promise<ReverbConfig> {
  if (!reverbConfigPromise) {
    reverbConfigPromise = fetch('/api/meta/reverb')
      .then(async (res) => {
        if (!res.ok) {
          return { enabled: false };
        }
        return (await res.json()) as ReverbConfig;
      })
      .catch(() => ({ enabled: false }));
  }
  return reverbConfigPromise;
}

function startPollingFallback(store: ReturnType<typeof useAppStore>) {
  if (pollTimer) {
    return;
  }
  pollTimer = setInterval(() => {
    if (document.visibilityState === 'visible' && !store.connected) {
      void fetchDashboard(store);
    }
  }, POLL_MS);
}

function stopPollingFallback() {
  if (!pollTimer) {
    return;
  }
  clearInterval(pollTimer);
  pollTimer = undefined;
}

async function connectReverb(store: ReturnType<typeof useAppStore>) {
  if (echoClient) {
    return;
  }

  const config = await loadReverbConfig();
  if (!config.enabled || !config.key) {
    startPollingFallback(store);
    return;
  }

  stopPollingFallback();

  window.Pusher = Pusher;
  const host = resolveHost(config);
  const port = resolvePort(config);
  const scheme = resolveScheme(config);

  echoClient = new Echo({
    broadcaster: 'reverb',
    key: config.key,
    wsHost: host,
    wsPort: port,
    wssPort: port,
    forceTLS: scheme === 'https',
    enabledTransports: ['ws', 'wss'],
    disableStats: true,
  });

  const connection = echoClient.connector.pusher.connection;
  connection.bind('connected', () => {
    store.connected = true;
  });
  connection.bind('disconnected', () => {
    store.connected = false;
    startPollingFallback(store);
  });
  connection.bind('unavailable', () => {
    store.connected = false;
    startPollingFallback(store);
  });
  connection.bind('error', () => {
    store.connected = false;
    startPollingFallback(store);
  });

  echoClient.channel('revenue').listen('.data.changed', (payload: { scope?: string }) => {
    schedulePushRefresh(payload.scope || 'import');
  });
}

function disconnectReverb() {
  echoClient?.disconnect();
  echoClient = undefined;
}

/**
 * Tie the socket to page/tab lifecycle so the browser never sees a lingering
 * "active transfer":
 *  - `pagehide` closes the socket before the window/tab is torn down
 *  - hiding the tab releases the connection; returning re-opens it
 */
function bindLifecycle(store: ReturnType<typeof useAppStore>) {
  if (lifecycleBound) {
    return;
  }
  lifecycleBound = true;

  const teardown = () => {
    if (pushRefreshTimer) {
      clearTimeout(pushRefreshTimer);
      pushRefreshTimer = undefined;
    }
    clearActiveRequest();
    stopPollingFallback();
    disconnectReverb();
  };

  window.addEventListener('pagehide', teardown);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') {
      disconnectReverb();
      stopPollingFallback();
      return;
    }
    void connectReverb(store);
    void fetchDashboard(store);
  });
}

function afterWindowLoad(cb: () => void) {
  if (document.readyState === 'complete') {
    cb();
    return;
  }
  window.addEventListener('load', cb, { once: true });
}

async function startLiveStream(store: ReturnType<typeof useAppStore>) {
  await fetchDashboard(store);
  afterWindowLoad(() => {
    void connectReverb(store);
    bindLifecycle(store);
  });
}

/** Reverb push when enabled; polling fallback otherwise. */
export function useLiveStream() {
  const store = useAppStore();

  onMounted(() => {
    if (streamStarted) {
      return;
    }
    streamStarted = true;
    void startLiveStream(store);
  });

  onUnmounted(() => {
    refreshGeneration++;
    clearActiveRequest();
    dashboardFetchInFlight = false;
    dashboardFetchQueued = false;
    if (pushRefreshTimer) {
      clearTimeout(pushRefreshTimer);
      pushRefreshTimer = undefined;
    }
    stopPollingFallback();
    disconnectReverb();
    streamStarted = false;
    reverbConfigPromise = undefined;
  });
}

export function onSubmission(cb: () => void) {
  submissionListeners.add(cb);
  return () => submissionListeners.delete(cb);
}

export function onDashboard(cb: (data: Record<string, unknown>) => void) {
  dashboardListeners.add(cb);
  if (lastDashboardPayload) {
    cb(lastDashboardPayload);
  }
  return () => dashboardListeners.delete(cb);
}

export function refreshNow() {
  return fetchDashboard(useAppStore());
}
