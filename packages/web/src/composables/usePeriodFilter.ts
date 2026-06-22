import { computed, ref } from 'vue';
import {
  appendPeriodParams,
  defaultPeriodState,
  periodLabel,
  periodRange,
  type DateRange,
  type PeriodState,
  type PeriodType,
} from '../utils/periodFilter';

const state = ref<PeriodState>(defaultPeriodState());
const locationId = ref('');

export function usePeriodFilter() {
  const range = computed(() => periodRange(state.value));
  const label = computed(() => periodLabel(state.value));
  const isActive = computed(() => state.value.type !== 'all');

  function setType(type: PeriodType) {
    state.value = { ...state.value, type };
  }

  function applyToParams(params: URLSearchParams) {
    appendPeriodParams(params, range.value);
    if (locationId.value) params.set('location_id', locationId.value);
  }

  return {
    state,
    locationId,
    range,
    label,
    isActive,
    setType,
    applyToParams,
  };
}

export type { DateRange, PeriodState, PeriodType };
