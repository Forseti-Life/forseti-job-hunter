# Copilot Agent Tracker

Admin module to track Copilot agent status and a sanitized event stream (no raw chat transcripts).

## Routes
- `/admin/reports/copilot-agent-tracker` (legacy route; 301 redirect to LangGraph dashboard)
- `/admin/reports/copilot-agent-tracker/langgraph` (LangGraph dashboard home)
- `/admin/reports/copilot-agent-tracker/agent/{agent_id}` (agent detail)
- `/admin/reports/copilot-agent-tracker/llm-management` (local LLM model selection + prompt test)
- `POST /api/copilot-agent-tracker/event` (internal telemetry; header token required)

## Telemetry auth
Telemetry POSTs must include the header:
- `X-Copilot-Agent-Tracker-Token: <value>`

The token is generated on module install and stored in Drupal state (not in git).
Admins can view the token on the dashboard page.

## Data model
- `copilot_agent_tracker_agents`: one row per agent, updated via merge/upsert
- `copilot_agent_tracker_events`: append-only event stream

## Quick health check (run locally before QA handoff)

```bash
# 1. Confirm module is enabled
cd /home/keithaumiller/forseti.life/sites/forseti && vendor/bin/drush pm:list --type=module --status=enabled 2>/dev/null | grep copilot_agent_tracker

# 2. Confirm dashboard route responds (expect 200 or 302→login for anon)
curl -sI http://localhost/admin/reports/copilot-agent-tracker | head -2

# 3. Confirm telemetry endpoint exists (expect 403 with no token, not 404)
curl -s -o /dev/null -w "%{http_code}" -X POST http://localhost/api/copilot-agent-tracker/event

# 4. Retrieve the telemetry token (requires Drupal state access)
cd /home/keithaumiller/forseti.life/sites/forseti && vendor/bin/drush php:eval "echo \Drupal::state()->get('copilot_agent_tracker.token', 'NOT SET');"
```

Expected results: step 1 lists the module; step 2 returns `HTTP/... 302` or `200`; step 3 returns `403` (not `404`); step 4 prints the token string.
