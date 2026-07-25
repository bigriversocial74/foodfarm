<?php

declare(strict_types=1);

namespace Homestead;

use PDO;

require_once __DIR__ . '/CostWasteSettingsTrait.php';
require_once __DIR__ . '/CostWasteTransactionTrait.php';
require_once __DIR__ . '/CostWasteSnapshotTrait.php';
require_once __DIR__ . '/CostWasteQueryTrait.php';
require_once __DIR__ . '/CostWasteSupportTrait.php';

final class CostWasteService
{
    use CostWasteSettingsTrait;
    use CostWasteTransactionTrait;
    use CostWasteSnapshotTrait;
    use CostWasteQueryTrait;
    use CostWasteSupportTrait;

    private const MODEL_VERSION = 'deterministic-v1';

    public function __construct(private PDO $pdo)
    {
    }
}
