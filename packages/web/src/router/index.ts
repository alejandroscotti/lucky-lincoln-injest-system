import { createRouter, createWebHistory } from 'vue-router';
import AppLayout from '../layouts/AppLayout.vue';
import LiveFeed from '../views/LiveFeed.vue';
import Dashboard from '../views/Dashboard.vue';
import Locations from '../views/Locations.vue';
import Reconcile from '../views/Reconcile.vue';
import Faults from '../views/Faults.vue';
import Submissions from '../views/Submissions.vue';
import Diagrams from '../views/Diagrams.vue';
import DiagramFullPage from '../views/DiagramFullPage.vue';

const router = createRouter({
  history: createWebHistory(),
  routes: [
    {
      path: '/',
      component: AppLayout,
      children: [
        { path: '', redirect: '/dashboard' },
        { path: 'dashboard', name: 'dashboard', component: Dashboard },
        { path: 'live', name: 'live', component: LiveFeed },
        { path: 'submissions', name: 'submissions', component: Submissions },
        { path: 'locations', name: 'locations', component: Locations },
        { path: 'reconcile', name: 'reconcile', component: Reconcile },
        { path: 'faults', name: 'faults', component: Faults },
        { path: 'diagrams', name: 'diagrams', component: Diagrams },
      ],
    },
    { path: '/diagram/:type', name: 'diagram-full', component: DiagramFullPage },
  ],
});

export default router;
