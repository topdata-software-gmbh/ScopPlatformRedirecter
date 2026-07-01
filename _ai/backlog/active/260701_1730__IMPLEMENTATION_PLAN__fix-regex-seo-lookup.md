---
filename: "_ai/backlog/active/260701_1730__IMPLEMENTATION_PLAN__fix-regex-seo-lookup.md"
title: "Fix: Add product SEO URL lookup to regex fallback"
createdAt: 2026-07-01 17:30
updatedAt: 2026-07-01 17:30
status: draft
priority: critical
tags: [hotfix, regex, seo-url, product-lookup]
estimatedComplexity: simple
documentType: IMPLEMENTATION_PLAN
---

# Fix: Add product SEO URL lookup to regex fallback

## Problem

The Phase 1 hotfix (`getRegexFallbackRedirect`) does a simple regex replacement:

```
Pattern:  ^/p/[^/]+/(\d+)p/?$
Request:  /p/permafix-abdeckfolien-transparent-270-cm-x-17-m/1503p/
Result:   /detail/1503
```

But Shopware 6's `/detail/{id}` route expects the **internal product UUID**, not the **product number** (SKU). `1503` is a product number, not a UUID, so `/detail/1503` returns 404.

## Solution

Add an optional **SEO lookup mode** to the regex fallback. When enabled, the captured value from the regex is treated as a product number. The plugin:
1. Queries the `product` table to find the product UUID by `productNumber`
2. Queries the `seo_url` table to find the canonical SEO URL for that product
3. Redirects directly to the correct SEO URL (e.g., `/permafix-abdeckfolie-mit-gewebeklebeband/1503`)

When disabled, the old replacement behavior is preserved for non-product generic redirects.

## Project Environment

- **Project**: ScopPlatformRedirecter (SW 6.7 Plugin)
- **Backend root**: `src/`
- **PHP Version**: 8.2+
- **Shopware Version**: ~6.7.4

## Files Changed

| # | File | Change |
|---|------|--------|
| 1 | `src/Resources/config/config.xml` | [MODIFY] Add `regexFallbackUseSeoLookup` bool field |
| 2 | `src/Resources/config/services.xml` | [MODIFY] Inject `product.repository` into decorator |
| 3 | `src/Decorator/CanonicalRedirectServiceDecorator.php` | [MODIFY] Add product repo, SEO lookup logic |

---

## Implementation

### 1. [MODIFY] `src/Resources/config/config.xml`

Add one new field after `regexFallbackHttpCode` in the Regex Fallback card:

```xml
        <input-field type="bool">
            <name>regexFallbackUseSeoLookup</name>
            <label>Look up product SEO URL</label>
            <label lang="de-DE">Produkt-SEO-URL suchen</label>
            <description>When enabled, the captured value from the regex is treated as a product number. The plugin looks up the product and redirects to its canonical SEO URL. The replacement pattern is ignored when this is active.</description>
            <description lang="de-DE">Wenn aktiviert, wird der erfasste Wert aus dem Regex als Produktnummer behandelt. Das Plugin sucht das Produkt und leitet auf dessen kanonische SEO-URL weiter. Das Ersetzungsmuster wird bei aktiver Option ignoriert.</description>
            <defaultValue>false</defaultValue>
        </input-field>
```

### 2. [MODIFY] `src/Resources/config/services.xml`

Add `product.repository` as a named parameter to the decorator service:

```xml
        <service id="Scop\PlatformRedirecter\Decorator\CanonicalRedirectServiceDecorator" decorates="Shopware\Core\Framework\Routing\CanonicalRedirectService">
            <argument type="service" id="Scop\PlatformRedirecter\Decorator\CanonicalRedirectServiceDecorator.inner"/>
            <argument type="service" id="Shopware\Core\System\SystemConfig\SystemConfigService"/>
            <argument type="service" id="scop_platform_redirecter_redirect.repository"/>
            <argument type="service" id="Shopware\Core\Framework\Extensions\ExtensionDispatcher"/>
            <argument type="service" id="seo_url.repository"/>
            <argument type="service" id="Shopware\Core\Framework\Store\InAppPurchase"/>
            <argument type="service" id="product.repository" key="$productRepository"/>
        </service>
```

### 3. [MODIFY] `src/Decorator/CanonicalRedirectServiceDecorator.php`

**a) Add import:**

```php
use Shopware\Core\Content\Product\ProductEntity;
```

**b) Add property and update constructor:**

Replace existing constructor with:

```php
    private EntityRepository $productRepository;

    public function __construct(
        CanonicalRedirectService $inner,
        SystemConfigService $configService,
        EntityRepository $redirectRepository,
        ExtensionDispatcher $extensionDispatcher,
        EntityRepository $seoUrlRepository,
        InAppPurchase $inAppPurchase,
        EntityRepository $productRepository,
    ) {
        parent::__construct($configService, $extensionDispatcher);
        $this->configService = $configService;
        $this->repository = $redirectRepository;
        $this->inner = $inner;
        $this->seoUrlRepository = $seoUrlRepository;
        $this->inAppPurchase = $inAppPurchase;
        $this->productRepository = $productRepository;
    }
```

**c) Add new private method `resolveProductSeoUrl`:**

```php
    private function resolveProductSeoUrl(string $productNumber, ?string $salesChannelId, Context $context): ?string
    {
        // Find product by product number (SKU)
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('productNumber', $productNumber));
        $criteria->setLimit(1);

        $product = $this->productRepository->search($criteria, $context)->first();
        if ($product === null) {
            return null;
        }

        // Resolve canonical SEO URL for the product
        $productId = $product->getId();

        $seoCriteria = new Criteria();
        $seoCriteria->addFilter(new EqualsFilter('routeName', 'frontend.detail.page'));
        $seoCriteria->addFilter(new EqualsFilter('foreignKey', $productId));
        $seoCriteria->addFilter(new EqualsFilter('isCanonical', true));
        $seoCriteria->addFilter(new EqualsFilter('isDeleted', false));
        if ($salesChannelId !== null) {
            $seoCriteria->addFilter(new OrFilter([
                new EqualsFilter('salesChannelId', $salesChannelId),
                new EqualsFilter('salesChannelId', null),
            ]));
        }
        $seoCriteria->setLimit(1);

        $seoUrl = $this->seoUrlRepository->search($seoCriteria, $context)->first();
        if ($seoUrl === null) {
            return null;
        }

        $path = $seoUrl->getSeoPathInfo();
        if ($path === null || $path === '') {
            return null;
        }

        return '/' . ltrim($path, '/');
    }
```

**d) Replace the body of `getRegexFallbackRedirect`:**

```php
    private function getRegexFallbackRedirect(Request $request): ?RedirectResponse
    {
        if (!$this->configService->getBool('ScopPlatformRedirecter.config.regexFallbackEnabled')) {
            return null;
        }

        $pattern = $this->configService->getString('ScopPlatformRedirecter.config.regexFallbackPattern');
        $httpCode = $this->configService->getInt('ScopPlatformRedirecter.config.regexFallbackHttpCode');

        if ($pattern === '') {
            return null;
        }

        $requestUri = (string) $request->get('sw-original-request-uri');
        $delimiter = '@';
        $regex = $delimiter . $pattern . $delimiter;

        try {
            if (!preg_match($regex, $requestUri, $matches)) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }

        // SEO lookup mode
        if ($this->configService->getBool('ScopPlatformRedirecter.config.regexFallbackUseSeoLookup')) {
            if (!isset($matches[1]) || $matches[1] === '') {
                return null;
            }
            $salesChannelId = $request->get('sw-sales-channel-id');
            $context = Context::createDefaultContext();
            $targetUrl = $this->resolveProductSeoUrl($matches[1], $salesChannelId, $context);
            if ($targetUrl === null) {
                return null;
            }
            return new RedirectResponse($targetUrl, $httpCode);
        }

        // Original replacement mode (backward compatible)
        $replacement = $this->configService->getString('ScopPlatformRedirecter.config.regexFallbackReplacement');
        if ($replacement === '') {
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

---

## Configuration Example for iShop URLs

Enable both settings:

| Field | Value |
|-------|-------|
| `regexFallbackEnabled` | `true` |
| `regexFallbackPattern` | `^/p/[^/]+/(\d+)p/?$` |
| `regexFallbackReplacement` | (unchanged, but ignored when SEO lookup is on) |
| `regexFallbackHttpCode` | `301` |
| `regexFallbackUseSeoLookup` | **`true`** |

**Full flow:**

```
Request:  /p/permafix-abdeckfolien-transparent-270-cm-x-17-m/1503p/
          ↓ regex matches, captures group 1 = "1503"
          ↓ resolveProductSeoUrl("1503")
          ↓ productRepository: WHERE productNumber = "1503"
          ↓ found product UUID = "0123456789abcdef0123456789abcdef"
          ↓ seoUrlRepository: WHERE foreignKey = "01234..." AND routeName = "frontend.detail.page" AND isCanonical = 1
          ↓ found seo_path_info = "permafix-abdeckfolie-mit-gewebeklebeband/1503"
Redirect:  /permafix-abdeckfolie-mit-gewebeklebeband/1503  ✅
```

---

## Backward Compatibility

The `regexFallbackUseSeoLookup` defaults to `false`, preserving the original replacement behavior. Existing installations that already configured the regex fallback will not be affected — they see no change until they toggle the new option on.

---

### 4. [MODIFY] `Readme.md`

Append a new section after the existing content:

```markdown
## Regex Fallback Configuration

The plugin can fall back to a regex-based redirect when no exact redirect is found in the database. This is useful for handling legacy URL patterns that follow a predictable structure.

### Basic Usage (Direct Path Replacement)

Enable in **Settings → System → Plugins → ScopPlatformRedirecter → Regex Fallback**:

| Field | Value |
|-------|-------|
| `regexFallbackEnabled` | `true` |
| `regexFallbackPattern` | `^/old-prefix/[^/]+/(\d+)suffix/?$` |
| `regexFallbackReplacement` | `/detail/$1` |
| `regexFallbackHttpCode` | `301` |

This matches URLs like `/old-prefix/any-name/123suffix/` and redirects to `/detail/123`.

### Advanced Usage (Product SEO URL Lookup)

When the captured value is a product number, enable SEO lookup to redirect directly to the product's canonical URL:

| Field | Value |
|-------|-------|
| `regexFallbackEnabled` | `true` |
| `regexFallbackPattern` | `^/old-prefix/[^/]+/(\d+)suffix/?$` |
| `regexFallbackHttpCode` | `301` |
| `regexFallbackUseSeoLookup` | `true` |

The plugin extracts the product number from the first capture group `(\d+)`, looks up the product in the database, and redirects to the product's current SEO URL (e.g., `/product-name/123`). The `regexFallbackReplacement` field is ignored when SEO lookup is enabled.
```

---

## Verification

1. Set `regexFallbackEnabled = true`, `regexFallbackUseSeoLookup = true`
2. Set pattern: `^/p/[^/]+/(\d+)p/?$`, replacement: any value (ignored)
3. Visit `/p/any-product-name/1503p/`
4. Expected: 301 redirect to the canonical SEO URL (e.g., `/permafix-abdeckfolie-mit-gewebeklebeband/1503`)
5. Visit `/p/any-product-name/999999p/` (non-existent product)
6. Expected: no redirect (falls through, product not found)
7. Set `regexFallbackUseSeoLookup = false`, keep replacement = `/detail/$1`
8. Expected: old behavior, redirect to `/detail/1503` (may 404, as expected from old behavior)
