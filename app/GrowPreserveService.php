<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class GrowPreserveService
{
    private const GROWTH_STAGES = [
        'planned', 'germinating', 'seedling', 'vegetative', 'flowering',
        'fruiting', 'harvest_ready', 'completed', 'failed',
    ];

    private const HARVEST_DESTINATIONS = ['inventory', 'recipe', 'preservation', 'donation', 'compost'];
    private const PRESERVATION_METHODS = [
        'water_bath', 'pressure_canning', 'dehydrating', 'fermenting',
        'pickling', 'freezing', 'vacuum_sealing', 'dry_storage',
    ];

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function createZone(int $householdId, array $data): int
    {
        $name = $this->text($data['name'] ?? '', 'Zone name', 140, true);
        $type = $this->text($data['zone_type'] ?? '', 'Zone type', 80, true);
        $dimensions = $this->text($data['dimensions'] ?? '', 'Dimensions', 100);
        $temperatureMin = $this->nullableNumber($data['target_temperature_min'] ?? null, 'Minimum temperature', -100, 250);
        $temperatureMax = $this->nullableNumber($data['target_temperature_max'] ?? null, 'Maximum temperature', -100, 250);
        $humidityMin = $this->nullableNumber($data['target_humidity_min'] ?? null, 'Minimum humidity', 0, 100);
        $humidityMax = $this->nullableNumber($data['target_humidity_max'] ?? null, 'Maximum humidity', 0, 100);

        if ($householdId < 1) {
            throw new InvalidArgumentException('A valid household is required.');
        }
        if ($temperatureMin !== null && $temperatureMax !== null && $temperatureMin > $temperatureMax) {
            throw new InvalidArgumentException('Minimum temperature cannot exceed maximum temperature.');
        }
        if ($humidityMin !== null && $humidityMax !== null && $humidityMin > $humidityMax) {
            throw new InvalidArgumentException('Minimum humidity cannot exceed maximum humidity.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO garden_zones
             (household_id, name, zone_type, dimensions, target_temperature_min, target_temperature_max,
              target_humidity_min, target_humidity_max, active)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $statement->execute([
            $householdId,
            $name,
            $type,
            $dimensions,
            $temperatureMin,
            $temperatureMax,
            $humidityMin,
            $humidityMax,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function createPlanting(int $householdId, array $data): int
    {
        $zoneId = (int)($data['garden_zone_id'] ?? 0);
        $cropName = $this->text($data['crop_name'] ?? '', 'Crop name', 140, true);
        $variety = $this->text($data['variety'] ?? '', 'Variety', 140);
        $plantedOn = $this->date($data['planted_on'] ?? '', 'Planted date', true);
        $harvestStart = $this->date($data['expected_harvest_start'] ?? '', 'Expected harvest start');
        $harvestEnd = $this->date($data['expected_harvest_end'] ?? '', 'Expected harvest end');
        $stage = (string)($data['growth_stage'] ?? 'planned');
        $plantCount = ($data['plant_count'] ?? '') === ''
            ? null
            : (int)$this->number($data['plant_count'], 'Plant count', 1, 1000000);
        $notes = $this->text($data['notes'] ?? '', 'Notes', 5000);

        if (!in_array($stage, self::GROWTH_STAGES, true) || in_array($stage, ['completed', 'failed'], true)) {
            throw new InvalidArgumentException('Choose a valid active growth stage.');
        }
        $this->assertZone($householdId, $zoneId);
        if ($harvestStart !== null && $harvestStart < $plantedOn) {
            throw new InvalidArgumentException('Expected harvest cannot begin before planting.');
        }
        if ($harvestEnd !== null && $harvestStart !== null && $harvestEnd < $harvestStart) {
            throw new InvalidArgumentException('Expected harvest end cannot precede its start.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO plantings
             (garden_zone_id, crop_name, variety, planted_on, expected_harvest_start,
              expected_harvest_end, growth_stage, plant_count, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $zoneId,
            $cropName,
            $variety,
            $plantedOn,
            $harvestStart,
            $harvestEnd,
            $stage,
            $plantCount,
            $notes,
        ]);

        return (int)$this->pdo->lastInsertId();
    }

    public function recordReading(int $householdId, int $memberId, array $data): int
    {
        $zoneId = (int)($data['garden_zone_id'] ?? 0);
        $source = (string)($data['source'] ?? 'manual');
        if (!in_array($source, ['manual', 'simulated'], true)) {
            throw new InvalidArgumentException('Only manual or simulated readings can be recorded here.');
        }

        $temperature = $this->nullableNumber($data['temperature'] ?? null, 'Temperature', -100, 250);
        $humidity = $this->nullableNumber($data['humidity'] ?? null, 'Humidity', 0, 100);
        $soilMoisture = $this->nullableNumber($data['soil_moisture'] ?? null, 'Soil moisture', 0, 100);
        $vpd = $this->nullableNumber($data['vpd'] ?? null, 'VPD', 0, 20);
        $lightLevel = $this->nullableNumber($data['light_level'] ?? null, 'Light level', 0, 10000000);
        if ($temperature === null && $humidity === null && $soilMoisture === null && $vpd === null && $lightLevel === null) {
            throw new InvalidArgumentException('Record at least one environmental reading.');
        }

        $this->assertMember($householdId, $memberId);
        $this->assertZone($householdId, $zoneId);
        $statement = $this->pdo->prepare(
            'INSERT INTO garden_readings
             (garden_zone_id, recorded_by_member_id, temperature, humidity, soil_moisture, vpd, light_level, source)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$zoneId, $memberId, $temperature, $humidity, $soilMoisture, $vpd, $lightLevel, $source]);

        return (int)$this->pdo->lastInsertId();
    }

    public function updatePlantingStage(int $householdId, int $memberId, int $plantingId, string $stage): void
    {
        if (!in_array($stage, self::GROWTH_STAGES, true)) {
            throw new InvalidArgumentException('Choose a valid growth stage.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->assertMember($householdId, $memberId);
            $statement = $this->pdo->prepare(
                'SELECT p.id, p.crop_name, p.growth_stage
                 FROM plantings p
                 JOIN garden_zones z ON z.id = p.garden_zone_id
                 WHERE p.id = ? AND z.household_id = ? FOR UPDATE'
            );
            $statement->execute([$plantingId, $householdId]);
            $planting = $statement->fetch();
            if (!is_array($planting)) {
                throw new RuntimeException('Planting not found.');
            }
            $current = (string)$planting['growth_stage'];
            if ($current === $stage) {
                throw new RuntimeException('The planting is already in that stage.');
            }
            if (in_array($current, ['completed', 'failed'], true)) {
                throw new RuntimeException('Completed or failed plantings are terminal records.');
            }
            if ($stage !== 'failed') {
                $currentIndex = array_search($current, self::GROWTH_STAGES, true);
                $nextIndex = array_search($stage, self::GROWTH_STAGES, true);
                if (!is_int($currentIndex) || !is_int($nextIndex) || $nextIndex <= $currentIndex) {
                    throw new InvalidArgumentException('Growth stages cannot move backward.');
                }
            }

            $update = $this->pdo->prepare('UPDATE plantings SET growth_stage = ? WHERE id = ? AND growth_stage = ?');
            $update->execute([$stage, $plantingId, $current]);
            if ($update->rowCount() !== 1) {
                throw new RuntimeException('The planting changed while its stage was being updated.');
            }
            $this->activity(
                $householdId,
                $memberId,
                'planting_stage_updated',
                'planting',
                $plantingId,
                (string)$planting['crop_name'] . ' moved to ' . str_replace('_', ' ', $stage)
            );
            $this->pdo->commit();
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function recordHarvest(int $householdId, int $memberId, array $data): int
    {
        $plantingId = (int)($data['planting_id'] ?? 0);
        $quantity = $this->number($data['quantity'] ?? null, 'Harvest quantity', 0.0001, 9999999999.9999);
        $unit = $this->unit($data['unit'] ?? '');
        $destination = (string)($data['destination'] ?? 'inventory');
        $grade = $this->text($data['grade'] ?? '', 'Grade', 60);
        $notes = $this->text($data['notes'] ?? '', 'Notes', 5000);
        $harvestedAt = $this->dateTime($data['harvested_at'] ?? null, 'Harvest time');
        $inventoryItemId = (int)($data['inventory_item_id'] ?? 0) ?: null;
        $locationId = (int)($data['storage_location_id'] ?? 0) ?: null;
        $newInventoryName = $this->text($data['new_inventory_name'] ?? '', 'Inventory item name', 180);
        $bestUseDate = $this->date($data['best_use_date'] ?? '', 'Best-use date');
        $actionKey = $this->actionKey($data['action_key'] ?? null);
        $markComplete = !empty($data['mark_complete']);
        $preservationMethod = (string)($data['preservation_method'] ?? '');

        if (!in_array($destination, self::HARVEST_DESTINATIONS, true)) {
            throw new InvalidArgumentException('Choose a valid harvest destination.');
        }
        if ($destination === 'preservation' && !in_array($preservationMethod, self::PRESERVATION_METHODS, true)) {
            throw new InvalidArgumentException('Choose a preservation method for this harvest.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->assertMember($householdId, $memberId);
            $duplicate = $this->pdo->prepare('SELECT id FROM harvests WHERE action_key = ? LIMIT 1');
            $duplicate->execute([$actionKey]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('This harvest was already recorded.');
            }

            $plantingQuery = $this->pdo->prepare(
                'SELECT p.*, z.household_id, z.name AS zone_name
                 FROM plantings p JOIN garden_zones z ON z.id = p.garden_zone_id
                 WHERE p.id = ? AND z.household_id = ? FOR UPDATE'
            );
            $plantingQuery->execute([$plantingId, $householdId]);
            $planting = $plantingQuery->fetch();
            if (!is_array($planting) || $planting['growth_stage'] === 'failed') {
                throw new RuntimeException('The selected planting is unavailable for harvest.');
            }

            $stockItemId = null;
            $stockLocationId = null;
            if (in_array($destination, ['inventory', 'preservation'], true)) {
                if ($inventoryItemId !== null) {
                    $itemQuery = $this->pdo->prepare(
                        "SELECT id, current_quantity, unit, storage_location_id
                         FROM inventory_items WHERE id = ? AND household_id = ? AND status = 'active' FOR UPDATE"
                    );
                    $itemQuery->execute([$inventoryItemId, $householdId]);
                    $item = $itemQuery->fetch();
                    if (!is_array($item)) {
                        throw new RuntimeException('The selected inventory item is unavailable.');
                    }
                    if ($this->normalizeUnit((string)$item['unit']) !== $this->normalizeUnit($unit)) {
                        throw new InvalidArgumentException('Harvest and inventory units must match exactly.');
                    }
                    $update = $this->pdo->prepare(
                        'UPDATE inventory_items SET current_quantity = current_quantity + ?
                         WHERE id = ? AND household_id = ? AND current_quantity = ?'
                    );
                    $update->execute([$quantity, $inventoryItemId, $householdId, $item['current_quantity']]);
                    if ($update->rowCount() !== 1) {
                        throw new RuntimeException('Inventory changed while the harvest was being posted.');
                    }
                    $stockItemId = $inventoryItemId;
                    $stockLocationId = (int)$item['storage_location_id'] ?: null;
                } else {
                    if ($locationId !== null) {
                        $this->assertLocation($householdId, $locationId);
                    }
                    $categoryId = $this->categoryId($householdId, 'food');
                    $itemInsert = $this->pdo->prepare(
                        "INSERT INTO inventory_items
                         (household_id, category_id, storage_location_id, name, item_type, current_quantity,
                          unit, best_use_date, status, metadata, notes)
                         VALUES (?, ?, ?, ?, 'ingredient', ?, ?, ?, 'active', ?, ?)"
                    );
                    $itemInsert->execute([
                        $householdId,
                        $categoryId,
                        $locationId,
                        $newInventoryName ?? (string)$planting['crop_name'],
                        $quantity,
                        $unit,
                        $bestUseDate,
                        json_encode(['source' => 'garden_harvest', 'planting_id' => $plantingId], JSON_THROW_ON_ERROR),
                        $notes,
                    ]);
                    $stockItemId = (int)$this->pdo->lastInsertId();
                    $stockLocationId = $locationId;
                }
            }

            $harvestInsert = $this->pdo->prepare(
                'INSERT INTO harvests
                 (planting_id, harvested_by_member_id, quantity, unit, grade, destination,
                  inventory_item_id, action_key, harvested_at, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $harvestInsert->execute([
                $plantingId,
                $memberId,
                $quantity,
                $unit,
                $grade,
                $destination,
                $stockItemId,
                $actionKey,
                $harvestedAt,
                $notes,
            ]);
            $harvestId = (int)$this->pdo->lastInsertId();

            $ledger = $this->pdo->prepare(
                'INSERT INTO food_ledger_events
                 (household_id, inventory_item_id, member_id, event_type, quantity, unit,
                  destination_location_id, related_type, related_id, notes, occurred_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ledger->execute([
                $householdId,
                $stockItemId,
                $memberId,
                'harvested',
                $quantity,
                $unit,
                $stockLocationId,
                'harvest',
                $harvestId,
                'Harvested ' . $planting['crop_name'] . ' from ' . $planting['zone_name'],
                $harvestedAt,
            ]);

            if ($destination === 'preservation') {
                $batch = $this->pdo->prepare(
                    "INSERT INTO preservation_batches
                     (household_id, name, method, status, started_by_member_id, storage_location_id, notes)
                     VALUES (?, ?, ?, 'planned', ?, ?, ?)"
                );
                $batch->execute([
                    $householdId,
                    'Preserve ' . $planting['crop_name'],
                    $preservationMethod,
                    $memberId,
                    $stockLocationId,
                    'Planned from harvest #' . $harvestId,
                ]);
                $preservationBatchId = (int)$this->pdo->lastInsertId();
                $this->pdo->prepare('UPDATE harvests SET preservation_batch_id = ? WHERE id = ?')
                    ->execute([$preservationBatchId, $harvestId]);
            } elseif (in_array($destination, ['recipe', 'donation', 'compost'], true)) {
                $eventType = match ($destination) {
                    'recipe' => 'used_in_recipe',
                    'donation' => 'donated',
                    default => 'composted',
                };
                $ledger->execute([
                    $householdId,
                    null,
                    $memberId,
                    $eventType,
                    -$quantity,
                    $unit,
                    null,
                    'harvest',
                    $harvestId,
                    'Harvest directed to ' . $destination,
                    $harvestedAt,
                ]);
            }

            if ($markComplete && !in_array((string)$planting['growth_stage'], ['completed', 'failed'], true)) {
                $this->pdo->prepare("UPDATE plantings SET growth_stage = 'completed' WHERE id = ?")
                    ->execute([$plantingId]);
            } elseif (!in_array((string)$planting['growth_stage'], ['harvest_ready', 'completed'], true)) {
                $this->pdo->prepare("UPDATE plantings SET growth_stage = 'harvest_ready' WHERE id = ?")
                    ->execute([$plantingId]);
            }

            $this->activity(
                $householdId,
                $memberId,
                'harvest_recorded',
                'harvest',
                $harvestId,
                $quantity . ' ' . $unit . ' of ' . $planting['crop_name'] . ' recorded'
            );
            $this->pdo->commit();

            return $harvestId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function completePreservation(int $householdId, int $memberId, array $data): int
    {
        $batchId = (int)($data['preservation_batch_id'] ?? 0) ?: null;
        $inputItemId = (int)($data['input_inventory_item_id'] ?? 0);
        $inputQuantity = $this->number($data['input_quantity'] ?? null, 'Input quantity', 0.0001, 9999999999.9999);
        $inputUnit = $this->unit($data['input_unit'] ?? '');
        $name = $this->text($data['name'] ?? '', 'Batch name', 180, true);
        $method = (string)($data['method'] ?? '');
        $outputName = $this->text($data['output_name'] ?? '', 'Output item name', 180, true);
        $outputQuantity = $this->number($data['output_quantity'] ?? null, 'Output quantity', 0.0001, 9999999999.9999);
        $outputUnit = $this->unit($data['output_unit'] ?? '');
        $locationId = (int)($data['storage_location_id'] ?? 0) ?: null;
        $bestUseDate = $this->date($data['best_use_date'] ?? '', 'Best-use date');
        $notes = $this->text($data['notes'] ?? '', 'Notes', 5000);
        $safetySource = $this->text($data['safety_source'] ?? '', 'Safety source', 1000);
        $actionKey = $this->actionKey($data['action_key'] ?? null);

        if ($inputItemId < 1 || !in_array($method, self::PRESERVATION_METHODS, true)) {
            throw new InvalidArgumentException('Choose a valid inventory input and preservation method.');
        }

        try {
            $this->pdo->beginTransaction();
            $this->assertMember($householdId, $memberId);
            if ($locationId !== null) {
                $this->assertLocation($householdId, $locationId);
            }
            $duplicate = $this->pdo->prepare('SELECT id FROM preservation_batches WHERE action_key = ? LIMIT 1');
            $duplicate->execute([$actionKey]);
            if ($duplicate->fetchColumn()) {
                throw new RuntimeException('This preservation completion was already posted.');
            }

            $inputQuery = $this->pdo->prepare(
                "SELECT id, name, current_quantity, unit, storage_location_id
                 FROM inventory_items WHERE id = ? AND household_id = ? AND status = 'active' FOR UPDATE"
            );
            $inputQuery->execute([$inputItemId, $householdId]);
            $input = $inputQuery->fetch();
            if (!is_array($input)) {
                throw new RuntimeException('The preservation input is unavailable.');
            }
            if ($this->normalizeUnit((string)$input['unit']) !== $this->normalizeUnit($inputUnit)) {
                throw new InvalidArgumentException('Preservation input and inventory units must match exactly.');
            }
            if ((float)$input['current_quantity'] + 0.00001 < $inputQuantity) {
                throw new RuntimeException('Not enough input inventory is available.');
            }

            $sourceHarvestId = null;
            if ($batchId !== null) {
                $batchQuery = $this->pdo->prepare(
                    "SELECT pb.id, pb.method, h.id AS source_harvest_id
                     FROM preservation_batches pb
                     LEFT JOIN harvests h ON h.preservation_batch_id = pb.id
                     WHERE pb.id = ? AND pb.household_id = ? AND pb.status IN ('planned','prepared') FOR UPDATE"
                );
                $batchQuery->execute([$batchId, $householdId]);
                $batch = $batchQuery->fetch();
                if (!is_array($batch)) {
                    throw new RuntimeException('The planned preservation batch is unavailable.');
                }
                if ((string)$batch['method'] !== $method) {
                    throw new InvalidArgumentException('The preservation method must match the planned batch.');
                }
                $sourceHarvestId = (int)$batch['source_harvest_id'] ?: null;
            } else {
                $insertBatch = $this->pdo->prepare(
                    "INSERT INTO preservation_batches
                     (household_id, name, method, status, started_by_member_id, started_at,
                      storage_location_id, action_key, best_use_date, safety_data, notes)
                     VALUES (?, ?, ?, 'prepared', ?, UTC_TIMESTAMP(), ?, ?, ?, ?, ?)"
                );
                $insertBatch->execute([
                    $householdId,
                    $name,
                    $method,
                    $memberId,
                    $locationId,
                    $actionKey,
                    $bestUseDate,
                    json_encode(['source' => $safetySource], JSON_THROW_ON_ERROR),
                    $notes,
                ]);
                $batchId = (int)$this->pdo->lastInsertId();
            }

            $deduct = $this->pdo->prepare(
                'UPDATE inventory_items SET current_quantity = current_quantity - ?
                 WHERE id = ? AND household_id = ? AND current_quantity >= ?'
            );
            $deduct->execute([$inputQuantity, $inputItemId, $householdId, $inputQuantity]);
            if ($deduct->rowCount() !== 1) {
                throw new RuntimeException('Input inventory changed during preservation. Review quantities and try again.');
            }

            $categoryId = $this->categoryId($householdId, 'food');
            $outputInsert = $this->pdo->prepare(
                "INSERT INTO inventory_items
                 (household_id, category_id, storage_location_id, name, item_type, current_quantity,
                  unit, best_use_date, status, metadata, notes)
                 VALUES (?, ?, ?, ?, 'preserved_food', ?, ?, ?, 'active', ?, ?)"
            );
            $outputInsert->execute([
                $householdId,
                $categoryId,
                $locationId,
                $outputName,
                $outputQuantity,
                $outputUnit,
                $bestUseDate,
                json_encode(['preservation_batch_id' => $batchId, 'method' => $method], JSON_THROW_ON_ERROR),
                $notes,
            ]);
            $outputItemId = (int)$this->pdo->lastInsertId();

            $inputInsert = $this->pdo->prepare(
                'INSERT INTO preservation_batch_inputs
                 (preservation_batch_id, inventory_item_id, source_harvest_id, quantity, unit)
                 VALUES (?, ?, ?, ?, ?)'
            );
            $inputInsert->execute([$batchId, $inputItemId, $sourceHarvestId, $inputQuantity, $inputUnit]);

            $batchUpdate = $this->pdo->prepare(
                "UPDATE preservation_batches
                 SET name = ?, method = ?, status = 'stored', started_by_member_id = ?,
                     started_at = COALESCE(started_at, UTC_TIMESTAMP()), completed_at = UTC_TIMESTAMP(),
                     yield_quantity = ?, yield_unit = ?, storage_location_id = ?, output_inventory_item_id = ?,
                     action_key = ?, best_use_date = ?, safety_data = ?, notes = ?
                 WHERE id = ? AND household_id = ? AND status IN ('planned','prepared')"
            );
            $batchUpdate->execute([
                $name,
                $method,
                $memberId,
                $outputQuantity,
                $outputUnit,
                $locationId,
                $outputItemId,
                $actionKey,
                $bestUseDate,
                json_encode(['source' => $safetySource], JSON_THROW_ON_ERROR),
                $notes,
                $batchId,
                $householdId,
            ]);
            if ($batchUpdate->rowCount() !== 1) {
                throw new RuntimeException('The preservation batch changed before completion.');
            }

            $ledger = $this->pdo->prepare(
                'INSERT INTO food_ledger_events
                 (household_id, inventory_item_id, member_id, event_type, quantity, unit,
                  source_location_id, destination_location_id, related_type, related_id, notes)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $ledger->execute([
                $householdId,
                $inputItemId,
                $memberId,
                'preserved',
                -$inputQuantity,
                $inputUnit,
                (int)$input['storage_location_id'] ?: null,
                $locationId,
                'preservation_batch',
                $batchId,
                'Input used for ' . $name,
            ]);
            $ledger->execute([
                $householdId,
                $outputItemId,
                $memberId,
                'preserved',
                $outputQuantity,
                $outputUnit,
                null,
                $locationId,
                'preservation_batch',
                $batchId,
                'Preserved output from ' . $input['name'],
            ]);

            $this->activity(
                $householdId,
                $memberId,
                'preservation_completed',
                'preservation_batch',
                $batchId,
                $name . ' completed and stored'
            );
            $this->pdo->commit();

            return $batchId;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function assertZone(int $householdId, int $zoneId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM garden_zones WHERE id = ? AND household_id = ? AND active = 1');
        $statement->execute([$zoneId, $householdId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('The garden zone is unavailable.');
        }
    }

    private function assertMember(int $householdId, int $memberId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT id FROM household_members WHERE id = ? AND household_id = ? AND status = 'active'"
        );
        $statement->execute([$memberId, $householdId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('The household member is unavailable.');
        }
    }

    private function assertLocation(int $householdId, int $locationId): void
    {
        $statement = $this->pdo->prepare('SELECT id FROM storage_locations WHERE id = ? AND household_id = ?');
        $statement->execute([$locationId, $householdId]);
        if (!$statement->fetchColumn()) {
            throw new RuntimeException('The storage location is unavailable.');
        }
    }

    private function categoryId(int $householdId, string $type): ?int
    {
        $statement = $this->pdo->prepare(
            'SELECT id FROM inventory_categories
             WHERE category_type = ? AND (household_id = ? OR household_id IS NULL)
             ORDER BY household_id DESC LIMIT 1'
        );
        $statement->execute([$type, $householdId]);

        return (int)$statement->fetchColumn() ?: null;
    }

    private function activity(
        int $householdId,
        int $memberId,
        string $eventKey,
        string $subjectType,
        int $subjectId,
        string $summary
    ): void {
        $statement = $this->pdo->prepare(
            "INSERT INTO activity_events
             (household_id, member_id, event_key, subject_type, subject_id, summary, visibility)
             VALUES (?, ?, ?, ?, ?, ?, 'household')"
        );
        $statement->execute([$householdId, $memberId, $eventKey, $subjectType, $subjectId, $summary]);
    }

    private function text(mixed $value, string $field, int $maximum, bool $required = false): ?string
    {
        $text = trim((string)$value);
        if ($required && $text === '') {
            throw new InvalidArgumentException($field . ' is required.');
        }
        if (mb_strlen($text) > $maximum) {
            throw new InvalidArgumentException($field . ' is too long.');
        }

        return $text === '' ? null : $text;
    }

    private function unit(mixed $value): string
    {
        $unit = $this->text($value, 'Unit', 30, true);
        if (!is_string($unit) || preg_match('/^[\pL\pN ._%\/-]+$/u', $unit) !== 1) {
            throw new InvalidArgumentException('Unit contains unsupported characters.');
        }

        return $unit;
    }

    private function number(mixed $value, string $field, float $minimum, float $maximum): float
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException($field . ' must be a number.');
        }
        $number = (float)$value;
        if (!is_finite($number) || $number < $minimum || $number > $maximum) {
            throw new InvalidArgumentException($field . ' is outside the supported range.');
        }

        return round($number, 4);
    }

    private function nullableNumber(mixed $value, string $field, float $minimum, float $maximum): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $this->number($value, $field, $minimum, $maximum);
    }

    private function date(mixed $value, string $field, bool $required = false): ?string
    {
        $value = trim((string)$value);
        if ($value === '') {
            if ($required) {
                throw new InvalidArgumentException($field . ' is required.');
            }
            return null;
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if (!$date || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException($field . ' is invalid.');
        }

        return $value;
    }

    private function dateTime(mixed $value, string $field): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return gmdate('Y-m-d H:i:s');
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date instanceof DateTimeImmutable) {
                return $date->format('Y-m-d H:i:s');
            }
        }
        throw new InvalidArgumentException($field . ' is invalid.');
    }

    private function actionKey(mixed $value): string
    {
        $key = strtolower(trim((string)$value));
        if (preg_match('/^[a-f0-9]{64}$/', $key) !== 1) {
            throw new InvalidArgumentException('The action token is invalid. Reload the page and try again.');
        }

        return $key;
    }

    private function normalizeUnit(string $unit): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $unit) ?? $unit));
    }
}
