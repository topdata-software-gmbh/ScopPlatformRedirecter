---
filename: "_ai/backlog/active/260701_1600__IMPLEMENTATION_PLAN__regex-fallback-hotfix.md"
title: "Phase 1: Configurable regex fallback for legacy URL patterns (hotfix)"
createdAt: 2026-07-01 16:00
updatedAt: 2026-07-01 16:00
status: completed
completedAt: 2026-07-01 18:00
priority: critical
tags: [hotfix, regex, redirect, iShop-legacy]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Phase 1: Configurable Regex Fallback for Legacy URL Patterns (Hotfix)

## Problem

Old iShop URLs like `https://focusshop.ch/p/permafix-abdeckfolien-transparent-270-cm-x-17-m/1503p/` produce 404 Not Found after migration to Shopware 6. These URLs are not present in the `scop_platform_redirecter_redirect` table because they were handled by the old iShop's internal redirect logic (URL A → URL B → final URL). The product number suffix (`1503p`) and the `/p/` prefix are iShop-specific artifacts that don't exist in the new Shopware URL format (`/product-name/1503`).

The redirect plugin's `CanonicalRedirectServiceDecorator` only checks exact-match entries in the database. There is no fallback mechanism for unrecognized URL patterns.

## Solution

Add a configurable regex-based fallback in `CanonicalRedirectServiceDecorator`. After the database lookup fails, attempt to match the request URI against a regex pattern defined in the plugin configuration. If matched, apply a replacement string and issue a 301/302 redirect.

The admin configures this entirely through the Shopware admin panel (`config.xml`) — no deployment, no code changes needed per pattern.

## Project Environment

- **Project**: ScopPlatformRedirecter (SW 6.7 Plugin)
- **Backend root**: `src/`
- **PHP Version**: 8.2+
- **Shopware Version**: ~6.7.4
- **Existing pattern**: CanonicalRedirectServiceDecorator decorates `Shopware\Core\Framework\Routing\CanonicalRedirectService`

## Scope

This is **Phase 1** — the absolute minimal hotfix. Changes are limited to:
1. `config.xml` — add one config card (4 fields)
2. `CanonicalRedirectServiceDecorator.php` — add regex fallback logic (~15 lines)

**No migrations. No new entities. No admin UI. No CLI commands.**

Phase 2 (clean rebuild with groups, successor products, etc.) will be planned separately.

## Implementation

### 1. Modify: `src/Resources/config/config.xml`

Add a new `<card>` after the URL Normalization card:

```xml
    <card>
        <title>Regex Fallback</title>
        <title lang="de-DE">Regex-Fallback</title>

        <input-field type="bool">
            <name>regexFallbackEnabled</name>
            <label>Enable regex fallback for unrecognized URLs</label>
            <label lang="de-DE">Regex-Fallback für nicht erkannte URLs aktivieren</label>
            <description>When enabled, the plugin will check the request URI against the regex pattern below if no database redirect matches.</description>
            <description lang="de-DE">Wenn aktiviert, wird die angefragte URI gegen das untenstehende Regex-Muster geprüft, falls kein Datenbank-Redirect gefunden wurde.</description>
            <defaultValue>false</defaultValue>
        </input-field>

        <input-field type="text">
            <name>regexFallbackPattern</name>
            <label>Regex pattern</label>
            <label lang="de-DE">Regex-Muster</label>
            <description>The regex pattern applied to the request path. Use capture groups for replacements. Example: ^\\/p\\/[^\\/]+\\/(\\d+)p\\/?$</description>
            <description lang="de-DE">Das Regex-Muster wird auf den Anfragepfad angewendet. Verwenden Sie Capture-Gruppen für Ersetzungen. Beispiel: ^\\/p\\/[^\\/]+\\/(\\d+)p\\/?$</description>
            <defaultValue></defaultValue>
        </input-field>

        <input-field type="text">
            <name>regexFallbackReplacement</name>
            <label>Replacement pattern</label>
            <label lang="de-DE">Ersetzungsmuster</label>
            <description>The replacement string using $1, $2, etc. from capture groups. Example: /detail/$1</description>
            <description lang="de-DE">Die Ersetzungszeichenfolge mit $1, $2, etc. aus den Capture-Gruppen. Beispiel: /detail/$1</description>
            <defaultValue></defaultValue>
        </input-field>

        <input-field type="int">
            <name>regexFallbackHttpCode</name>
            <label>HTTP redirect code</label>
            <label lang="de-DE">HTTP-Weiterleitungscode</label>
            <description>HTTP status code for the redirect (301 or 302).</description>
            <description lang="de-DE">HTTP-Statuscode für die Weiterleitung (301 oder 302).</description>
            <defaultValue>301</defaultValue>
            <options>
                <option>
                    <id>301</id>
                    <name>301 Moved Permanently</name>
                </option>
                <option>
                    <id>302</id>
                    <name>302 Found (Temporary)</name>
                </option>
            </options>
        </input-field>
    </card>
```

### 2. Modify: `src/Decorator/CanonicalRedirectServiceDecorator.php`

Add a private method for regex fallback and call it from `getRedirect()` before delegating to the inner service.

#### Changes:

**a) Add import:**

```php
use Psr\Log\LoggerInterface;
```

**b) Add constructor parameter:**

```php
public function __construct(
    CanonicalRedirectService $inner,
    SystemConfigService $configService,
    EntityRepository $redirectRepository,
    ExtensionDispatcher $extensionDispatcher,
    EntityRepository $seoUrlRepository,
    InAppPurchase $inAppPurchase,
    private readonly SystemConfigService $systemConfigService,
) {
    parent::__construct($configService, $extensionDispatcher);
    $this->configService = $configService;
    $this->repository = $redirectRepository;
    $this->inner = $inner;
    $this->seoUrlRepository = $seoUrlRepository;
    $this->inAppPurchase = $inAppPurchase;
}
```

Wait — `$configService` and `$this->configService` already exist. The `SystemConfigService` is already injected as `$configService`. So I just need to use the existing one. Let me re-check...

Looking at the existing constructor:
```php
public function __construct(CanonicalRedirectService $inner, SystemConfigService $configService, ...)
{
    parent::__construct($configService, $extensionDispatcher);
    $this->configService = $configService;
    ...
}
```

So `$this->configService` is already a `SystemConfigService`. I can use it directly.

**c) Add private method:**

```php
private function getRegexFallbackRedirect(Request $request): ?RedirectResponse
{
    if (!$this->configService->getBool('ScopPlatformRedirecter.config.regexFallbackEnabled')) {
        return null;
    }

    $pattern = $this->configService->getString('ScopPlatformRedirecter.config.regexFallbackPattern');
    $replacement = $this->configService->getString('ScopPlatformRedirecter.config.regexFallbackReplacement');
    $httpCode = $this->configService->getInt('ScopPlatformRedirecter.config.regexFallbackHttpCode');

    if ($pattern === '' || $replacement === '') {
        return null;
    }

    $requestUri = (string) $request->get('sw-original-request-uri');

    // Use @ delimiter for regex to avoid issues with forward slashes in the pattern
    $delimiter = '@';
    $regex = $delimiter . $pattern . $delimiter;

    try {
        if (!preg_match($regex, $requestUri)) {
            return null;
        }
    } catch (\Throwable $e) {
        return null;
    }

    $targetUrl = preg_replace($regex, $replacement, $requestUri);

    if ($targetUrl === null || $targetUrl === '' || $targetUrl === $requestUri) {
        return null;
    }

    if (strpos($targetUrl, '/') !== 0) {
        $targetUrl = '/' . $targetUrl;
    }

    return new RedirectResponse($targetUrl, $httpCode);
}
```

**d) Add calls to `getRegexFallbackRedirect()` in `getRedirect()`:**

There are two places where `$this->inner->getRedirect($request)` is called as a fallback:

1. Line ~170 (after query params lookup fails):
```php
// Before: return $this->inner->getRedirect($request);
// After:
$regexRedirect = $this->getRegexFallbackRedirect($request);
if ($regexRedirect !== null) {
    return $regexRedirect;
}
return $this->inner->getRedirect($request);
```

2. Line ~174 (after no match at all, no query params):
```php
// Before: return $this->inner->getRedirect($request);
// After:
$regexRedirect = $this->getRegexFallbackRedirect($request);
if ($regexRedirect !== null) {
    return $regexRedirect;
}
return $this->inner->getRedirect($request);
```

**Full modified method context (showing both insertion points):**

```php
public function getRedirect(Request $request): ?Response
{
    // ... existing code (blocked paths, search patterns, DB lookup) ...

    if ($redirects->count() === 0) {
        if (str_contains($requestUri, '?')) {
            // ... existing query params handling ...

            if ($redirects->count() === 0) {
                $regexRedirect = $this->getRegexFallbackRedirect($request);
                if ($regexRedirect !== null) {
                    return $regexRedirect;
                }
                return $this->inner->getRedirect($request);
            }
        } else {
            $regexRedirect = $this->getRegexFallbackRedirect($request);
            if ($regexRedirect !== null) {
                return $regexRedirect;
            }
            return $this->inner->getRedirect($request);
        }
    }

    // ... existing redirect response building ...
}
```

## Configuration Example

For the iShop legacy product URLs, configure:

| Field | Value |
|-------|-------|
| `regexFallbackEnabled` | `true` |
| `regexFallbackPattern` | `^/p/[^/]+/(\d+)p/?$` |
| `regexFallbackReplacement` | `/detail/$1` |
| `regexFallbackHttpCode` | `301` |

**How this works:**

1. Request: `/p/permafix-abdeckfolien-transparent-270-cm-x-17-m/1503p/`
2. No DB redirect found → regex check triggered
3. Pattern `^/p/[^/]+/(\d+)p/?$` matches:
   - Group 1 captures `1503` (the `p` suffix and optional trailing `/` are outside the capture group)
4. Replacement `/detail/$1` → `/detail/1503`
5. Shopware's `CanonicalRedirectService` then handles `/detail/1503` → `/product-name/1503`
6. Browser receives 301 → `/product-name/1503`

## Verification

1. Enable the feature in Shopware admin: Settings → System → Plugins → ScopPlatformRedirecter → Regex Fallback
2. Set pattern: `^/p/[^/]+/(\d+)p/?$`
3. Set replacement: `/detail/$1`
4. Visit `https://focusshop.ch/p/permafix-abdeckfolien-transparent-270-cm-x-17-m/1503p/`
5. Expected: 301 redirect to `/detail/1503`
6. Shopware's SEO URL system should then redirect to the canonical product URL

## Future Work (Phase 2)

These topics are deliberately excluded from this hotfix and will be part of the new plugin rebuild:

- `RedirectGroup` entity with `priority` for ordering
- `RegexRule` as peer of `RedirectGroup` in the unified pipeline
- Admin UI with drag-and-drop reordering
- Successor product redirects
- Proper import/export
- Multi-pattern support
- Per-rule sales channel binding
