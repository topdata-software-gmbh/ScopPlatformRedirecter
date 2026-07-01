---
title: "Implementation Report: Fix regex SEO lookup"
planFile: "_ai/backlog/active/260701_1730__IMPLEMENTATION_PLAN__fix-regex-seo-lookup.md"
implementedAt: 2026-07-01
status: completed
---

## Summary

Implemented product SEO URL lookup for the regex fallback redirect feature in ScopPlatformRedirecter.

## Changes Made

### 1. `src/Resources/config/config.xml` — Added new config field
- Added `regexFallbackUseSeoLookup` bool field after `regexFallbackHttpCode` in the Regex Fallback card
- Defaults to `false` (backward compatible)

### 2. `src/Resources/config/services.xml` — Injected product repository
- Added `product.repository` as a named argument (`$productRepository`) to `CanonicalRedirectServiceDecorator`

### 3. `src/Decorator/CanonicalRedirectServiceDecorator.php` — Added SEO lookup logic
- Added `use Shopware\Core\Content\Product\ProductEntity` import
- Added `private EntityRepository $productRepository` property
- Updated constructor with multi-line signature accepting `EntityRepository $productRepository`
- Added `resolveProductSeoUrl()` method that:
  1. Queries `product` table by `productNumber`
  2. Queries `seo_url` table for canonical SEO URL (`frontend.detail.page`)
  3. Returns the SEO path or null if not found
- Updated `getRegexFallbackRedirect()` to:
  - Check `regexFallbackUseSeoLookup` config option
  - When enabled: extract `$matches[1]`, resolve via `resolveProductSeoUrl()`, redirect to canonical SEO URL
  - When disabled: fall back to original replacement behavior
  - Also fixed `preg_match` to capture matches (required for SEO lookup) and removed `$e` variable from catch block

### 4. `Readme.md` — Added documentation
- Appended "Regex Fallback Configuration" section with basic and advanced usage examples

## Verification

1. Set `regexFallbackEnabled = true`, `regexFallbackUseSeoLookup = true`
2. Set pattern: `^/p/[^/]+/(\d+)p/?$`
3. Visit `/p/any-product-name/1503p/` → expects 301 redirect to canonical SEO URL
4. Visit `/p/any-product-name/999999p/` (non-existent) → expects no redirect
5. Set `regexFallbackUseSeoLookup = false` → old behavior preserved

## Backward Compatibility

The `regexFallbackUseSeoLookup` defaults to `false`. Existing installations are unaffected.
