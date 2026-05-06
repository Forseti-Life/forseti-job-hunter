# GenAI Fallback Service Standard

## Purpose

`GenAiFallbackService` is the standard fallback layer for process-flow gates when deterministic checks cannot fully confirm a decision (for example due to anti-bot blocks, incomplete evidence, or ambiguous page content).

This prevents each flow from re-implementing:
- AI service availability checks
- prompt/metadata conventions
- JSON parsing and validation
- response normalization (`used/available/success/confirmed/confidence/evidence`)

## Service Location

- Class: `Drupal\job_hunter\Service\GenAiFallbackService`
- File: `src/Service/GenAiFallbackService.php`
- Service ID: `job_hunter.genai_fallback_service`

## Standard Response Contract

Every fallback evaluation returns this shape:

```php
[
  'used' => bool,
  'available' => bool,
  'success' => bool,
  'confirmed' => bool,
  'confidence' => 'none|low|medium|high',
  'response' => string,
  'evidence' => string,
  'parsed' => array,
]
```

## Standard Invocation API

Use `evaluateBooleanDecision()`:

```php
$result = $genAiFallbackService->evaluateBooleanDecision(
  'application_location_validation',
  $context,
  'You are validating if a web page is the TRUE application location for a specific job requisition.',
  [
    'module' => 'job_hunter',
    'stage' => 'resolve_redirect_chain',
    'max_tokens' => 600,
    'decision_key' => 'is_true_application_location',
  ]
);
```

### Parameters

- `use_case`: unique use-case key for telemetry and routing
- `context`: JSON-safe evidence payload
- `instruction`: domain-specific adjudication instruction
- `options`:
  - `module` (default: `job_hunter`)
  - `stage` (default: `generic_fallback`)
  - `max_tokens` (default: `600`)
  - `decision_key` (default: `is_confirmed`)

## Current Production Usage

- `ApplicationLocationVerificationService::verify()`
  - deterministic checks run first
  - fallback runs only when hard gate fails and fallback is enabled
  - final decision uses deterministic pass OR fallback confirmation

## Reuse Guidelines For New Flows

1. Run deterministic checks first and produce explicit unmet reasons.
2. Call `GenAiFallbackService` only on inconclusive/failed hard checks.
3. Pass compact but sufficient context (no unnecessary payload bloat).
4. Use a flow-specific `use_case` and `decision_key`.
5. Persist/display both deterministic and fallback outcomes for auditability.

## Anti-Patterns To Avoid

- Calling GenAI before deterministic checks.
- Free-form response parsing in individual flows.
- Diverging response shapes across flows.
- Hiding fallback evidence from UI/logs.

## Troubleshooting

- If `available=false`, verify `ai_conversation.ai_api_service` is installed/enabled.
- If `success=false`, check upstream GenAI invocation errors in `evidence`.
- If JSON parsing fails, tighten instruction and context to enforce strict JSON output.
