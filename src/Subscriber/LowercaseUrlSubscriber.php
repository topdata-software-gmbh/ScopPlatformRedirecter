<?php declare(strict_types=1);

namespace Scop\PlatformRedirecter\Subscriber;

use Doctrine\DBAL\Connection;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class LowercaseUrlSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly SystemConfigService $systemConfigService,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 33],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        if (!$this->systemConfigService->getBool('ScopPlatformRedirecter.config.lowercaseUrlRedirect')) {
            return;
        }

        $request = $event->getRequest();

        if (!$request->attributes->has('sw-sales-channel-id')) {
            return;
        }

        $originalUri = $request->attributes->get('sw-original-request-uri', '');

        $path = parse_url($originalUri, PHP_URL_PATH) ?: $originalUri;
        if (!preg_match('/[A-Z]/', $path)) {
            return;
        }

        $queryString = parse_url($originalUri, PHP_URL_QUERY);

        $lowerPath = mb_strtolower($path);
        $seoPath = ltrim($lowerPath, '/');
        $seoPathSlash = $seoPath . '/';

        $found = $this->connection->fetchOne(
            'SELECT seo_path_info FROM seo_url WHERE (seo_path_info = :path OR seo_path_info = :pathSlash) AND is_deleted = 0 AND is_canonical = 1 LIMIT 1',
            ['path' => $seoPath, 'pathSlash' => $seoPathSlash]
        );

        if (!$found) {
            return;
        }

        $requestPath = rtrim(ltrim($path, '/'), '/');
        $foundPath = rtrim($found, '/');
        if ($requestPath === $foundPath) {
            return;
        }

        $newUrl = $request->getSchemeAndHttpHost() . $request->getBaseUrl() . '/' . ltrim($found, '/');

        if ($queryString) {
            $newUrl .= '?' . $queryString;
        }

        $event->setResponse(new RedirectResponse($newUrl, RedirectResponse::HTTP_MOVED_PERMANENTLY));
    }
}
