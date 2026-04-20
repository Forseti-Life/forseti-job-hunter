/**
 * iCIMS ATS — Playwright handler stub
 * Priority: 6 | Track: B (login required)
 */
'use strict';
async function apply(payload, buildResult) {
  return buildResult({ outcome: 'manual_required', reason: 'phase2_pending', apply_url: payload.apply_url,
    error: 'iCIMS automation not yet implemented.', instructions: 'Apply manually via the link below.',
    field_map: { first_name: (payload.personal_info||{}).first_name||'', last_name: (payload.personal_info||{}).last_name||'', email: (payload.personal_info||{}).email||'' } });
}
module.exports = { apply };
