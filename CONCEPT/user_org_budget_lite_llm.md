# Per-User Token Budget Limits with LiteLLM Virtual Keys

> **Status**: Planned (not yet implemented)
> **Created**: 2026-01-22
> **Related Projects**: workoflow-promopage-v2, workoflow-bot, workoflow-ai-setup

## Overview
Implement per-user and per-organization token/cost limits using LiteLLM's virtual key system. Users will have their own LiteLLM API keys with budget constraints (e.g., 5€/month), which are passed through the MS Teams → workoflow-bot → n8n → LiteLLM flow.

## Architecture

```
┌─────────────────────────────────────────────────────────────────────┐
│                         User Profile Page                            │
│  - Generate LiteLLM virtual key (max_budget: 5€, duration: 30d)     │
│  - View current spend and remaining budget                           │
└────────────────────────────┬────────────────────────────────────────┘
                             │ stored in UserOrganisation.litellmApiKey
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    POST /api/register                                │
│  Response includes: litellm_api_key (if user has one)               │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                      workoflow-bot                                   │
│  Enriched payload now includes: custom.litellm_api_key              │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                         n8n Workflow                                 │
│  HTTP Request node adds: X-Litellm-Key: Bearer {litellm_api_key}    │
└────────────────────────────┬────────────────────────────────────────┘
                             │
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    LiteLLM Proxy (port 4000)                        │
│  - Validates X-Litellm-Key header                                   │
│  - Tracks spend per key                                             │
│  - Returns 429 when budget exceeded                                  │
└─────────────────────────────────────────────────────────────────────┘
```

## Requirements (Confirmed)

- **Budget Scope**: Both per-user AND per-organization levels
- **Budget Reset**: Monthly (30 days, automatic)
- **n8n Integration**: HTTP Request nodes with dynamic X-Litellm-Key header injection
- **LiteLLM Deployment**: Separate deployment (workoflow-ai-setup, port 4000)

## Implementation Plan

### Phase 1: LiteLLM Configuration (workoflow-ai-setup)

**File: `litellm_config.yaml`**
- Add `litellm_key_header_name: "X-Litellm-Key"` to `general_settings`
- This allows accepting virtual keys via custom header alongside Authorization

### Phase 2: Backend Service (workoflow-promopage-v2)

#### 2.1 Create LiteLLM Service
**New file: `src/Service/LiteLLMService.php`**
```php
class LiteLLMService {
    // Inject: HttpClientInterface, EncryptionService, litellmBaseUrl, litellmMasterKey

    public function generateVirtualKey(float $maxBudget, string $budgetDuration = '30d'): array
    // Calls POST /key/generate with max_budget and budget_duration

    public function getKeyInfo(string $apiKey): array
    // Calls GET /key/info?key={key} to get spend info

    public function deleteKey(string $apiKey): bool
    // Calls DELETE /key/delete with key parameter
}
```

#### 2.2 Update Entity: UserOrganisation
**File: `src/Entity/UserOrganisation.php`**

Add new fields:
- `litellmApiKey` (string, nullable, 255) - The LiteLLM virtual key
- `litellmKeyCreatedAt` (datetime, nullable) - When the key was generated
- `litellmMaxBudget` (decimal, nullable) - Budget limit in EUR (e.g., 5.00)

Add methods:
- `getLitellmApiKey()`, `setLitellmApiKey()`
- `getLitellmKeyCreatedAt()`, `setLitellmKeyCreatedAt()`
- `getLitellmMaxBudget()`, `setLitellmMaxBudget()`
- `hasLitellmKey(): bool`

#### 2.3 Update Entity: Organisation (for org-level budgets)
**File: `src/Entity/Organisation.php`**

Add new fields:
- `litellmTeamId` (string, nullable) - LiteLLM team ID for org-level tracking
- `litellmMaxBudget` (decimal, nullable) - Org-level budget cap

#### 2.4 Update ProfileController
**File: `src/Controller/ProfileController.php`**

Add new routes:
- `POST /profile/litellm/generate` - Generate new LiteLLM key
- `GET /profile/litellm/spend` - AJAX endpoint for current spend

```php
#[Route('/litellm/generate', name: 'app_profile_litellm_generate', methods: ['POST'])]
public function generateLitellmKey(Request $request, LiteLLMService $litellmService): Response
{
    // 1. Validate CSRF
    // 2. Check if user already has a key (delete old one first)
    // 3. Call LiteLLMService::generateVirtualKey(5.0, '30d')
    // 4. Store key in UserOrganisation
    // 5. Store key in session for one-time display
    // 6. Redirect to profile
}
```

#### 2.5 Update Profile Template
**File: `templates/profile/index.html.twig`**

Add new section for LiteLLM key management:
- Display current key status (masked: `sk-xxxx...xxxx`)
- Show current spend / max budget with progress bar
- "Generate New Key" button (warns about replacing existing)
- Budget reset countdown (days until reset)

#### 2.6 Update RegisterApiController
**File: `src/Controller/RegisterApiController.php`**

Modify response to include LiteLLM key:
```php
$response = [
    'success' => true,
    'magic_link' => $magicLinkUrl,
    // ... existing fields ...
    'litellm_api_key' => $userOrganisation?->getLitellmApiKey(), // NEW
];
```

#### 2.7 Environment Variables
**File: `.env` / `.env.dist`**

Add:
```
LITELLM_BASE_URL=http://litellm:4000
LITELLM_MASTER_KEY=sk-your-master-key
LITELLM_DEFAULT_BUDGET=5.0
LITELLM_BUDGET_DURATION=30d
```

### Phase 3: Bot Integration (workoflow-bot)

**File: `bot.js`**

Update enriched payload assembly (~line 561):
```javascript
customData = {
    // ... existing fields ...
    litellm_api_key: registrationResponse?.litellm_api_key || null,
};
```

Update register-api.js to capture and store the LiteLLM key from registration response.

### Phase 4: n8n Workflow Updates

#### HTTP Request Node Approach (Selected)
Replace the `@n8n/n8n-nodes-langchain.lmChatOpenAi` node with an HTTP Request node for full header control:

```json
{
  "parameters": {
    "url": "http://litellm:4000/v1/chat/completions",
    "method": "POST",
    "headers": {
      "Content-Type": "application/json",
      "X-Litellm-Key": "Bearer {{ $json.custom.litellm_api_key }}"
    },
    "body": {
      "model": "gpt-4.1",
      "messages": "{{ $json.messages }}"
    }
  }
}
```

This approach provides:
- Full control over headers (required for X-Litellm-Key)
- Dynamic key injection from webhook payload
- Consistent with OpenAI-compatible API format
- Easy error handling for 429 responses

### Phase 5: Error Handling

When LiteLLM returns 429 (budget exceeded):
```json
{
  "detail": "Authentication Error, ExceededTokenBudget: Current spend for token: 5.01; Max Budget for Token: 5.00"
}
```

n8n should catch this and return a user-friendly message:
- "Your monthly AI budget has been exceeded. Please contact your administrator or wait for the budget to reset."

## Files to Modify

### workoflow-promopage-v2
| File | Changes |
|------|---------|
| `src/Service/LiteLLMService.php` | NEW - LiteLLM API client |
| `src/Entity/UserOrganisation.php` | Add litellm fields |
| `src/Entity/Organisation.php` | Add org-level budget fields |
| `src/Controller/ProfileController.php` | Add LiteLLM key generation routes |
| `src/Controller/RegisterApiController.php` | Include litellm_api_key in response |
| `templates/profile/index.html.twig` | Add LiteLLM key management UI |
| `translations/messages.en.yaml` | Add translations |
| `translations/messages.de.yaml` | Add translations |
| `config/services.yaml` | Configure LiteLLMService with env vars |
| `.env.dist` | Add LITELLM_* env vars |

### workoflow-ai-setup
| File | Changes |
|------|---------|
| `litellm_config.yaml` | Add `litellm_key_header_name` |

### workoflow-bot
| File | Changes |
|------|---------|
| `bot.js` | Include litellm_api_key in enriched payload |
| `register-api.js` | Capture litellm_api_key from response |

## Database Migration

Run after entity changes:
```bash
docker-compose exec frankenphp php bin/console doctrine:schema:update --force
```

## Verification

1. **Profile Page Test**
   - Login to https://subscribe-workflows.vcec.cloud/profile/
   - Generate a new LiteLLM key
   - Verify key appears (masked) with spend info

2. **API Test**
   ```bash
   curl -X POST http://localhost:3979/api/register \
     -H "Authorization: Basic $(echo -n 'user:pass' | base64)" \
     -H "Content-Type: application/json" \
     -d '{"name":"Test","org_uuid":"xxx","workflow_user_id":"yyy"}'
   # Should return litellm_api_key in response
   ```

3. **Bot Flow Test**
   - Send message in MS Teams
   - Check n8n execution logs for litellm_api_key in payload
   - Verify LLM requests include X-Litellm-Key header

4. **Budget Limit Test**
   - Set a very low budget (0.01€)
   - Make requests until 429 error
   - Verify user-friendly error message in Teams

## Security Considerations

- LiteLLM virtual keys are stored plain-text (they're already tokens, not credentials)
- Master key is only in environment variables, never exposed
- Keys are scoped to users, so compromise affects only that user's budget
- Consider key rotation mechanism for future enhancement

## LiteLLM API Reference

### Generate Virtual Key
```bash
curl -X POST 'http://localhost:4000/key/generate' \
  -H 'Authorization: Bearer LITELLM_MASTER_KEY' \
  -H 'Content-Type: application/json' \
  -d '{
    "max_budget": 5.0,
    "budget_duration": "30d"
  }'
# Returns: {"key": "sk-xxx..."}
```

### Get Key Spend Info
```bash
curl 'http://localhost:4000/key/info?key=sk-xxx' \
  -H 'Authorization: Bearer LITELLM_MASTER_KEY'
# Returns: {"info": {"spend": 1.23, "max_budget": 5.0, ...}}
```

### Budget Exceeded Response (429)
```json
{
  "detail": "Authentication Error, ExceededTokenBudget: Current spend for token: 5.01; Max Budget for Token: 5.00"
}
```
