# Agent Evaluation Module Refactoring

## Summary
The agent_evaluation module has been refactored to depend on and utilize the ai_conversation module instead of duplicating functionality.

## Changes Made

### 1. Removed Duplicate Code
- **AIConversationSettingsForm.php** - Removed duplicate form (marked as .DEPRECATED)
- **AIApiService.php** - Deprecated in favor of ai_conversation's service (marked as .DEPRECATED)
- **Routing conflict** - Removed duplicate `/admin/config/ai-conversation/settings` route

### 2. Service Integration
**agent_evaluation.services.yml:**
```yaml
# Old (duplicated functionality):
agent_evaluation.api_service:
  class: Drupal\agent_evaluation\Service\AIApiService
  arguments: ['@config.factory', '@logger.factory', '@entity_type.manager', '@agent_evaluation.prompt_manager']

# New (alias to centralized service):
agent_evaluation.api_service:
  alias: ai_conversation.ai_api_service
  deprecated: Use '@ai_conversation.ai_api_service' directly instead
```

### 3. Configuration Migration
**Settings now managed by ai_conversation module:**
- AWS credentials (access key, secret key, region)
- AI model selection
- System prompt
- Max tokens (default and operation-specific)
- Conversation settings (max_recent_messages, summary_frequency, etc.)
- Debug settings

**Access the centralized settings at:**
- URL: https://forseti.life/admin/config/ai-conversation/settings
- Route name: `ai_conversation.settings`
- Form class: `\Drupal\ai_conversation\Form\AIConversationSettingsForm`

### 4. Controller Updates
**ChatController.php:**
- Now injects `ai_conversation.ai_api_service` instead of `agent_evaluation.api_service`
- Uses `AIApiServiceInterface` type hint for better compatibility

### 5. PromptManager Updates
**PromptManager.php:**
- Now reads/writes system_prompt from `ai_conversation.settings`
- No longer uses `agent_evaluation.settings` for system prompt

### 6. Dependencies
**agent_evaluation.info.yml** already had correct dependency:
```yaml
dependencies:
  - ai_conversation:ai_conversation
```

## Migration Steps for Administrators

### If you have custom settings in agent_evaluation.settings:

1. **Check current agent_evaluation settings:**
   ```bash
   drush config:get agent_evaluation.settings
   ```

2. **Check current ai_conversation settings:**
   ```bash
   drush config:get ai_conversation.settings
   ```

3. **Manually copy any custom values** from agent_evaluation.settings to ai_conversation.settings:
   ```bash
   drush config:set ai_conversation.settings system_prompt "your custom prompt"
   drush config:set ai_conversation.settings aws_region "your region"
   # etc.
   ```

4. **Clear cache:**
   ```bash
   drush cr
   ```

5. **Verify the centralized settings form works:**
   - Visit: /admin/config/ai-conversation/settings
   - Confirm all settings display correctly
   - Test the "Test AWS Bedrock Connection" button

### Developer Note:

If you have custom code referencing the old services, update:

**Old code:**
```php
$ai_service = \Drupal::service('agent_evaluation.api_service');
use Drupal\agent_evaluation\Service\AIApiService;
```

**New code:**
```php
$ai_service = \Drupal::service('ai_conversation.ai_api_service');
use Drupal\ai_conversation\Service\AIApiServiceInterface;
```

## Testing Checklist

- [ ] AI conversation chat interface still works
- [ ] Settings form at /admin/config/ai-conversation/settings displays correctly
- [ ] Connection test button works
- [ ] System prompt updates correctly
- [ ] AWS Bedrock API calls succeed
- [ ] No PHP errors in watchdog logs
- [ ] Agent evaluation functionality remains intact

## Files to Remove (After Testing)

Once confirmed working, these deprecated files can be deleted:
- `src/Form/AIConversationSettingsForm.php` (kept .DEPRECATED marker for now)
- `src/Service/AIApiService.php` (kept .DEPRECATED marker for now)
- `config/install/agent_evaluation.settings.yml` (if no longer needed)

## Date
February 11, 2026
