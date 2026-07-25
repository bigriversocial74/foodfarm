<?php

declare(strict_types=1);

namespace Homestead;

use PDO;

require_once __DIR__ . '/NutritionProfileTrait.php';
require_once __DIR__ . '/NutritionSnapshotTrait.php';
require_once __DIR__ . '/NutritionRecommendationTrait.php';
require_once __DIR__ . '/NutritionQueryTrait.php';
require_once __DIR__ . '/NutritionSupportTrait.php';

final class NutritionService
{
    use NutritionProfileTrait;
    use NutritionSnapshotTrait;
    use NutritionRecommendationTrait;
    use NutritionQueryTrait;
    use NutritionSupportTrait;

    private const MODEL_VERSION = 'deterministic-v1';

    public function __construct(private PDO $pdo)
    {
    }
}