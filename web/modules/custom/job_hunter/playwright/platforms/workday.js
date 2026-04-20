/**
 * Workday ATS — Playwright handler
 *
 * Handles: *.myworkdayjobs.com apply forms
 * Track: B (login required — stored credentials)
 * Priority: 3
 *
 * Workday uses per-company subdomains, multi-step wizard forms, and
 * data-automation-id attributes for stable field targeting.
 *
 * NOTE: This is a stub implementation. Full implementation tracked in:
 *   todo: phase2-workday
 *   spec: docs/PHASE2_BROWSER_AUTOMATION_REQUIREMENTS.md §4.6
 */

'use strict';

async function apply(payload, buildResult) {
  return buildResult({
    outcome:      'manual_required',
    reason:       'phase2_pending',
    apply_url:    payload.apply_url,
    error:        'Workday automation not yet implemented (Phase 2, Priority 3).',
    instructions: 'Apply manually. Your profile fields are pre-mapped below.',
    field_map:    buildFieldMap(payload),
  });
}

function buildFieldMap(payload) {
  const p = payload.personal_info || {};
  return {
    legalNameSection_firstName:  p.first_name  || '',
    legalNameSection_lastName:   p.last_name   || '',
    email:                       p.email       || '',
    'phone-number':              p.phone       || '',
    addressSection_addressLine1: '',
    addressSection_city:         p.city        || '',
    addressSection_postalCode:   p.zip         || '',
  };
}

module.exports = { apply };
