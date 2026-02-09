# SharePoint Integration Tests — Cross-Site Discovery & Relevance Scoring

## Prerequisites

- FrankenPHP container must be running: `docker-compose up -d frankenphp`
- **IMPORTANT**: FrankenPHP worker mode has a known pre-existing bug where the EntityManager gets closed after certain requests (token refresh failures, etc.). If you get `The EntityManager is closed` errors, restart the container: `docker-compose restart frankenphp`
- Config ID 39 (org `3a46e7b6-bb35-4d13-a6c3-2d0b63496fda`) has valid SharePoint tokens
- Config ID 26 (org `afe4d4f4-06e0-4f82-9596-0de3fb577ff3`) has **expired** tokens (Sept 2025) — avoid using for execute tests

## Environment Variables

```bash
ORG_UUID="3a46e7b6-bb35-4d13-a6c3-2d0b63496fda"
ORG_UUID_ALT="afe4d4f4-06e0-4f82-9596-0de3fb577ff3"
WORKFLOW_USER_ID="45908692-019e-4436-810c-b417f58f5f4f"
AUTH_CREDS="workoflow:workoflow"
BASE_URL="http://localhost:3979"
```

---

## Test 1: List Tools — Verify `sharepoint_list_sites` appears

**Purpose**: Confirm the new `sharepoint_list_sites` tool and `userQuery` parameter on `sharepoint_search` are exposed via the API.

```bash
curl -s "$BASE_URL/api/integrations/$ORG_UUID_ALT?workflow_user_id=$WORKFLOW_USER_ID&tool_type=sharepoint" \
  -u "$AUTH_CREDS" \
  -H "Accept: application/json" | python3 -c "
import json, sys
data = json.load(sys.stdin)
tools = data.get('tools', [])
print(f'Total tools: {len(tools)}')
for t in tools:
    name = t.get('function', {}).get('name', 'unknown')
    params = list(t.get('function', {}).get('parameters', {}).get('properties', {}).keys())
    print(f'  - {name} (params: {params})')
"
```

**Expected**:
- `sharepoint_search_26` with params: `kql`, `limit`, `userQuery`
- `sharepoint_list_sites_26` with params: `searchQuery`
- `sharepoint_read_document_26`, `sharepoint_read_page_26` (existing tools)

**Status**: PASS (verified 2026-02-02, re-verified 2026-02-02)

---

## Test 2: Execute `sharepoint_list_sites` — Discover accessible sites

**Purpose**: Confirm the new list_sites tool returns real SharePoint sites with hostnames.

```bash
curl -s -X POST "$BASE_URL/api/integrations/$ORG_UUID/execute" \
  -u "$AUTH_CREDS" \
  -H "Content-Type: application/json" \
  -d '{"tool_id":"sharepoint_list_sites_39","parameters":{"searchQuery":"CEC"},"execution_id":"test-list-sites"}' | python3 -c "
import json, sys
d = json.load(sys.stdin)
r = d.get('result', {})
print('success:', d.get('success'))
print('count:', r.get('count', '?'))
for s in r.get('sites', [])[:5]:
    print(f'  {s.get(\"displayName\")} -> {s.get(\"webUrl\")} (hostname: {s.get(\"hostname\")})')
"
```

**Expected**:
- `success: True`
- Multiple sites returned (14 for "CEC" query)
- All sites have `hostname`, `webUrl`, `displayName`, `id` fields populated
- Hostname is `valanticmore.sharepoint.com` (NOT `valanticgroup.sharepoint.com`)

**Status**: PASS (verified 2026-02-02, re-verified 2026-02-02 — returned 14 sites)

---

## Test 2b: Execute `sharepoint_list_sites` — All sites (no filter)

**Purpose**: Confirm list_sites returns all accessible sites when no searchQuery is provided.

```bash
curl -s -X POST "$BASE_URL/api/integrations/$ORG_UUID/execute" \
  -u "$AUTH_CREDS" \
  -H "Content-Type: application/json" \
  -d '{"tool_id":"sharepoint_list_sites_39","parameters":{},"execution_id":"test-list-sites-all"}' | python3 -c "
import json, sys
d = json.load(sys.stdin)
r = d.get('result', {})
print('success:', d.get('success'))
print('count:', r.get('count', '?'))
print('First 3 sites:')
for s in r.get('sites', [])[:3]:
    print(f'  {s.get(\"displayName\")} ({s.get(\"hostname\")})')
"
```

**Expected**:
- `success: True`
- Large number of sites (82 returned in initial test)
- All have hostname field populated

**Status**: PASS (verified 2026-02-02, re-verified 2026-02-02 — returned 82 sites)

---

## Test 3: Search with `userQuery` — Relevance scoring

**Purpose**: Confirm search results are scored and sorted by relevance when `userQuery` is provided.

```bash
curl -s -X POST "$BASE_URL/api/integrations/$ORG_UUID/execute" \
  -u "$AUTH_CREDS" \
  -H "Content-Type: application/json" \
  -d '{"tool_id":"sharepoint_search_39","parameters":{"kql":"Onboarding","limit":5,"userQuery":"onboarding process"},"execution_id":"test-relevance"}' | python3 -c "
import json, sys
d = json.load(sys.stdin)
r = d.get('result', {})
print('success:', d.get('success'))
print('count:', r.get('count', '?'))
print('relevanceScored:', r.get('relevanceScored', '?'))
print('autoBroadened:', r.get('autoBroadened', '?'))
print('summary:', r.get('grouped_summary', ''))
for v in r.get('value', [])[:5]:
    print(f'  [{v.get(\"relevanceScore\", \"N/A\")}] ({v.get(\"type\")}) {v.get(\"name\", v.get(\"title\", \"\"))[:60]}')
"
```

**Expected**:
- `success: True`
- `relevanceScored: True`
- `autoBroadened: False`
- Each result has a `relevanceScore` field (integer)
- Results sorted by relevanceScore descending
- "Onboarding Journey Guide" page should score higher than generic PDFs

**Status**: PASS (verified 2026-02-02, re-verified 2026-02-02 — page scored 44, site 35, file 24)

---

## Test 4: Search without `userQuery` — No scoring (backward compatibility)

**Purpose**: Confirm original behavior is preserved when `userQuery` is not provided.

```bash
curl -s -X POST "$BASE_URL/api/integrations/$ORG_UUID/execute" \
  -u "$AUTH_CREDS" \
  -H "Content-Type: application/json" \
  -d '{"tool_id":"sharepoint_search_39","parameters":{"kql":"Onboarding","limit":3},"execution_id":"test-no-scoring"}' | python3 -c "
import json, sys
d = json.load(sys.stdin)
r = d.get('result', {})
print('success:', d.get('success'))
print('count:', r.get('count', '?'))
print('relevanceScored:', r.get('relevanceScored', '?'))
print('autoBroadened:', r.get('autoBroadened', '?'))
has_scores = any('relevanceScore' in v for v in r.get('value', []))
print('results have relevanceScore:', has_scores)
"
```

**Expected**:
- `success: True`
- `relevanceScored: False`
- `autoBroadened: False`
- Results do NOT have `relevanceScore` field

**Status**: PASS (verified 2026-02-02, re-verified 2026-02-02)

---

## Test 5: Auto-retry — Wrong `path:` filter gets auto-broadened

**Purpose**: Confirm that when a `path:` filter returns 0 results, the server automatically retries without it.

**NOTE**: This test requires a fresh container restart (see prerequisites). The `path:` to `valanticgroup.sharepoint.com` should return 0 results (content is on `valanticmore.sharepoint.com`), triggering auto-broadening.

```bash
docker-compose restart frankenphp && sleep 5

curl -s -X POST "$BASE_URL/api/integrations/$ORG_UUID/execute" \
  -u "$AUTH_CREDS" \
  -H "Content-Type: application/json" \
  -d '{"tool_id":"sharepoint_search_39","parameters":{"kql":"path:\"https://valanticgroup.sharepoint.com\" AND Onboarding","limit":5,"userQuery":"onboarding on valanticgroup"},"execution_id":"test-auto-retry"}' | python3 -c "
import json, sys
d = json.load(sys.stdin)
r = d.get('result', {})
print('success:', d.get('success'))
print('count:', r.get('count', '?'))
print('relevanceScored:', r.get('relevanceScored', '?'))
print('autoBroadened:', r.get('autoBroadened', '?'))
print('searchQuery:', r.get('searchQuery', '')[:120])
for v in r.get('value', [])[:3]:
    print(f'  [{v.get(\"relevanceScore\", \"N/A\")}] ({v.get(\"type\")}) {v.get(\"name\", v.get(\"title\", \"\"))[:60]}')
"
```

**Expected**:
- `success: True`
- `autoBroadened: True` (path filter was stripped and search retried)
- `count` > 0 (found results after broadening)
- `searchQuery` contains `(auto-broadened: path filter removed)`
- Results have `relevanceScore` (since `userQuery` was provided)

**Status**: PASS (verified 2026-02-02 — autoBroadened=True, 5 results scored, searchQuery="Onboarding (auto-broadened: path filter removed)", top scores: page=44, site=35, file=24)

---

## Test 6: Template rendering — Verify `{% verbatim %}` fix

**Purpose**: Confirm the Twig template renders without errors (the `{{document-1-name}}` placeholders in examples are no longer parsed as Twig variables).

```bash
curl -s -X GET "$BASE_URL/api/skills/?organisation_uuid=$ORG_UUID_ALT&workflow_user_id=$WORKFLOW_USER_ID&tool_type=sharepoint" \
  -u "$AUTH_CREDS" \
  -H "Accept: application/json" | python3 -c "
import json, sys
d = json.load(sys.stdin)
skills = d.get('skills', [])
for s in skills:
    if s.get('type') == 'sharepoint':
        prompt = s.get('system_prompt', '')
        has_verbatim = '{{document-1-name}}' in prompt
        has_version = 'Version: 6.0.0' in prompt
        has_list_sites = 'sharepoint_list_sites' in prompt
        has_user_query = 'userQuery' in prompt
        print(f'Template renders: OK (length: {len(prompt)})')
        print(f'Contains {{{{document-1-name}}}}: {has_verbatim}')
        print(f'Version 6.0.0: {has_version}')
        print(f'list_sites tool: {has_list_sites}')
        print(f'userQuery param: {has_user_query}')
        break
else:
    print('No sharepoint skill found')
"
```

**Expected**:
- Template renders without error (no Twig exception)
- Contains literal `{{document-1-name}}` (preserved by verbatim)
- Contains `Version: 6.0.0`
- Contains `sharepoint_list_sites` tool documentation
- Contains `userQuery` parameter documentation

**Status**: PASS (verified 2026-02-02 — template renders OK, length=41980, all 4 checks pass: verbatim placeholders, Version 6.0.0, list_sites tool, userQuery param. NOTE: Uses ORG_UUID_ALT since workflow_user_id is linked to that org)

---

## Test 7: Expired token handling (negative test)

**Purpose**: Confirm proper error response when SharePoint tokens are expired.

```bash
curl -s -X POST "$BASE_URL/api/integrations/$ORG_UUID_ALT/execute" \
  -u "$AUTH_CREDS" \
  -H "Content-Type: application/json" \
  -d '{"tool_id":"sharepoint_list_sites_26","parameters":{},"workflow_user_id":"'"$WORKFLOW_USER_ID"'","execution_id":"test-expired"}' | python3 -c "
import json, sys
d = json.load(sys.stdin)
print('success:', d.get('success'))
print('error:', d.get('message', '')[:100])
"
```

**Expected**:
- `success: false`
- Error message mentions token expired / refresh failed

**Status**: PASS (verified 2026-02-02, re-verified 2026-02-02 — returned AADSTS700082 refresh token expired, HTTP 500)

---

## Known Issues

### EntityManager closed in FrankenPHP worker mode

After certain requests (especially token refresh failures), the Doctrine EntityManager gets permanently closed for that worker process. Subsequent requests to the same worker fail with `The EntityManager is closed (500)`.

**Workaround**: Restart FrankenPHP before each test: `docker-compose restart frankenphp && sleep 5`

**Root cause**: Pre-existing issue, not introduced by these changes. The token refresh in `SharePointIntegration::refreshTokenIfNeeded()` can throw, and the exception isn't properly caught before the EntityManager state is corrupted. This affects all SharePoint execute calls, not just the new features.

**Evidence**: The search logic itself completes successfully (confirmed via container logs — "Search API found 5 results", "Results scored and sorted by relevance"). The 500 occurs in the controller's audit logging layer after search completion.
