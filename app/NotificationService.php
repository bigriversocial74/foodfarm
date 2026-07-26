<?php

declare(strict_types=1);

namespace Homestead;

use PDO;

require_once __DIR__ . '/NotificationSettingsTrait.php';
require_once __DIR__ . '/NotificationSyncTrait.php';
require_once __DIR__ . '/NotificationLifecycleTrait.php';
require_once __DIR__ . '/NotificationQueryTrait.php';
require_once __DIR__ . '/NotificationSupportTrait.php';

final class NotificationService
{
    use NotificationSettingsTrait;
    use NotificationSyncTrait;
    use NotificationLifecycleTrait;
    use NotificationQueryTrait;
    use NotificationSupportTrait;

    private const CATEGORIES = [
        'task', 'inventory', 'prepared_food', 'forecast', 'garden',
        'preservation', 'finance', 'nutrition', 'meal', 'system',
    ];

    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    private const MODEL_VERSION = 'deterministic-v1';

    public function __construct(private PDO $pdo)
    {
    }
}
