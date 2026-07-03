/**
 * PlansApp - thin shell mounting the plan manager.
 *
 * The create/edit experience lives in ./components/plan-manager and its
 * registry-driven form/table. This component exists only as the mount target
 * referenced by ../index.js.
 */

import { PlanManager } from './components/plan-manager';

export function PlansApp() {
	return <PlanManager />;
}
