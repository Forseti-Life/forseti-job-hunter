/**
 * Ashby ATS — Playwright handler stub
 * Priority: 4 | Track: A (no login)
 * Spec: docs/PHASE2_BROWSER_AUTOMATION_REQUIREMENTS.md §4.3
 */
'use strict';
async function apply(payload, buildResult) {
  return buildResult({
    outcome: 'manual_required', reason: 'phase2_pending', apply_url: payload.apply_url,
    error: 'Ashby automation not yet implemented (Phase 2, Priority 4).',
    instructions: 'Apply manually via the link below.',
    field_map: { name: (payload.personal_info||{}).full_name||'', email: (payload.personal_info||{}).email||'' },
  });
}
module.exports = { apply };
