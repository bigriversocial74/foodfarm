<?php

declare(strict_types=1);

namespace Homestead;

use PDO;

require_once __DIR__ . '/CostWasteSettingsTrait.php';
require_once __DIR__ . '/CostWastePurchaseTrait.php';
require_once __DIR__ . '/CostWasteWasteTrait.php';
require_once __DIR__ . '/CostWasteRecipeCostTrait.php';
require_once __DIR__ . '/CostWasteFinanceSnapshotTrait.php';
require_once __DIR__ . '/CostWasteRecommendationTrait.php';
require_once __DIR__ . '/CostWasteQueryTrait.php';
require_once __DIR__ . '/CostWasteSupportTrait.php';

final class CostWasteService
{
    use CostWasteSettingsTrait;
    use CostWastePurchaseTrait;
    use CostWasteWasteTrait;
    use CostWasteRecipeCostTrait;
    use CostWasteFinanceSnapshotTrait;
    use CostWasteRecommendationTrait;
    use CostWasteQueryTrait;
    use CostWasteSupportTrait;

    private const MODEL_VERSION = 'deterministic-v1';

    public function __construct(private PDO $pdo)
    {
    }
}
