<!-- REVIEWED: 2026-07-16 -->
# Job Hunter Documentation

## Purpose

This document is the entrypoint for `web/modules/custom/job_hunter` in `forseti-job-hunter`.

## Scope

- Describe what this directory owns.
- Link to the most important sibling docs and implementation surfaces.

## Key References

- Add references to local README/ARCHITECTURE/TESTING docs where applicable.

## Agentic Development Readiness

- **Quick start:** Read `web/modules/custom/job_hunter/README.md` and capture the current scope before editing. Cross-check `web/modules/custom/job_hunter/ARCHITECTURE.md` to align terms, boundaries, and linked issue context. Align with parent context in `web/modules/custom/README.md` before finalizing the readiness block.
- **Key entry points:** `web/modules/custom/job_hunter/ARCHITECTURE.md`, `web/modules/custom/job_hunter/CODE_REVIEW_job_hunter.install.md`, `web/modules/custom/job_hunter/GITHUB_ISSUES_TO_CREATE.md`, `web/modules/custom/job_hunter/INSTALL.md`
- **Verification:** Run `drush php:eval "job_hunter_install();"`; then run `drush php:eval "node_access_rebuild();"`.
- **Source of truth:** **Storage Strategy:** This module uses a hybrid model — **nodes for canonical content** and **custom tables for operational/automation data** (AI artifacts, pipeline state, sync metadata). See [ARCHITECTURE.md](ARCHITECTURE.md) for policy and rules.
- **Constraints / gotchas:** **⚠️ IMPORTANT: This document must be read and understood before beginning any development work on this module.**.
- **Architecture map:** `web/modules/custom/job_hunter/README.md`, `web/modules/custom/job_hunter/ARCHITECTURE.md`, `web/modules/custom/job_hunter/CODE_REVIEW_job_hunter.install.md`, `web/modules/custom/job_hunter/GITHUB_ISSUES_TO_CREATE.md`, `web/modules/custom/job_hunter/INSTALL.md`
