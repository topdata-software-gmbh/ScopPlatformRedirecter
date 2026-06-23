<?php declare(strict_types=1);

namespace Scop\PlatformRedirecter\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1750692001DefaultLowercaseRedirect extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1750692001;
    }

    public function update(Connection $connection): void
    {
        $configKey = 'ScopPlatformRedirecter.config.lowercaseUrlRedirect';

        $exists = $connection->fetchOne(
            'SELECT id FROM `system_config` WHERE `configuration_key` = :configKey LIMIT 1',
            ['configKey' => $configKey]
        );

        if ($exists) {
            return;
        }

        $connection->executeStatement(
            "INSERT INTO `system_config` (`id`, `configuration_key`, `configuration_value`, `sales_channel_id`, `created_at`)
             VALUES (UNHEX(REPLACE(UUID(),'-','')), :configKey, :configValue, NULL, NOW())",
            [
                'configKey' => $configKey,
                'configValue' => json_encode(['_value' => true]),
            ]
        );
    }

    public function updateDestructive(Connection $connection): void
    {
    }
}
