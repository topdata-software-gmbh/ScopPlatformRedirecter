---
filename: "_ai/backlog/reports/260701_1600__IMPLEMENTATION_REPORT__regex-fallback-hotfix.md"
title: "Implementation Report: Configurable regex fallback for legacy URL patterns (hotfix)"
createdAt: 2026-07-01 16:00
updatedAt: 2026-07-01 16:00
status: completed
tags: [hotfix, regex, redirect, iShop-legacy]
documentType: IMPLEMENTATION_REPORT
---

# Implementation Report: Configurable Regex Fallback for Legacy URL Patterns (Hotfix)

## Summary

Implemented a configurable regex-based fallback mechanism in `CanonicalRedirectServiceDecorator`. After the database lookup fails, the request URI is checked against a regex pattern defined in the plugin configuration. If matched, a replacement string is applied and a 301/302 redirect is issued.

## Files Modified

### `src/Resources/config/config.xml`
- Added new `<card>` block "Regex Fallback" with 4 config fields:
  - `regexFallbackEnabled` (bool, default `false`)
  - `regexFallbackPattern` (text)
  - `regexFallbackReplacement` (text)
  - `regexFallbackHttpCode` (int, dropdown 301/302, default `301`)

### `src/Decorator/CanonicalRedirectServiceDecorator.php`
- Added `getRegexFallbackRedirect(Request $request): ?RedirectResponse` private method at `:231`
- Added calls to `getRegexFallbackRedirect()` in both fallback paths in `getRedirect()` (lines 170-174 and 178-182), before delegating to `$this->inner->getRedirect($request)`

## Verification

1. Enable in Shopware admin: Settings → System → Plugins → ScopPlatformRedirecter → Regex Fallback
2. Set pattern: `^/p/[^/]+/(\d+)p/?$`
3. Set replacement: `/detail/$1`
4. Visit `https://focusshop.ch/p/permafix-abdeckfolien-transparent-270-cm-x-17-m/1503p/`
5. Expected: 301 redirect → `/detail/1503` → canonical product URL via Shopware's SEO system
