<?php
declare(strict_types=1);

namespace Scop\PlatformRedirecter\Subscriber;

use Scop\PlatformRedirecter\Redirect\RedirectDefinition;
use Shopware\Core\Content\ImportExport\Event\ImportExportBeforeImportRecordEvent;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class ImportSubscriber implements EventSubscriberInterface
{
    private ?array $sourceUrlMap = null;

    public function __construct(
        private readonly EntityRepository $redirectRepository,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ImportExportBeforeImportRecordEvent::class => 'preImportRecord',
        ];
    }

    public function preImportRecord(ImportExportBeforeImportRecordEvent $event): void
    {
        $config = $event->getConfig();
        if ($config->get('sourceEntity') !== RedirectDefinition::ENTITY_NAME) {
            return;
        }

        if ($this->sourceUrlMap === null) {
            $this->sourceUrlMap = $this->loadSourceUrlMap($event->getContext());
        }

        $record = $event->getRecord();
        $recordId = $record['id'] ?? null;
        if (!empty($record['sourceURL'])) {
            $sourceURL = $record['sourceURL'];
            $existingId = $this->sourceUrlMap[$sourceURL] ?? null;
            if ($existingId !== null && $existingId !== $recordId) {
                $record['id'] = $existingId;
            }
            $event->setRecord($record);
        }
    }

    private function loadSourceUrlMap(Context $context): array
    {
        $map = [];
        $criteria = new Criteria();
        $criteria->setLimit(500);
        $result = $this->redirectRepository->search($criteria, $context);
        $this->collectSourceUrls($result, $map);

        while ($result->getEntities()->count() >= 500) {
            $criteria->setOffset($criteria->getOffset() + 500);
            $result = $this->redirectRepository->search($criteria, $context);
            $this->collectSourceUrls($result, $map);
        }

        return $map;
    }

    private function collectSourceUrls($result, array &$map): void
    {
        foreach ($result->getEntities() as $entity) {
            $url = $entity->getSourceURL();
            if ($url !== null && $url !== '') {
                $map[$url] = $entity->getId();
            }
        }
    }
}