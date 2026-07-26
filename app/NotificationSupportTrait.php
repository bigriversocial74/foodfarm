<?php

declare(strict_types=1);

namespace Homestead;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use PDO;
use RuntimeException;

trait NotificationSupportTrait
{
    private const CATEGORIES = [
        'task', 'inventory', 'prepared_food', 'forecast', 'garden',
        'preservation', 'finance', 'nutrition', 'meal', 'system',
    ];

    private const PRIORITIES = ['low', 'medium', 'high', 'critical'];

    private function assertActiveMember(int $householdId, int $memberId): void
    {
        if ($householdId < 1 || $memberId < 1) {
            throw new InvalidArgumentException('A valid household member is required.');
        }
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM household_members
             WHERE id = ? AND household_id = ? AND status = 'active'"
        );
        $statement->execute([$memberId, $householdId]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new InvalidArgumentException('The household member is not active in this household.');
        }
    }

    private function lockHousehold(int $householdId): array
    {
        $statement = $this->pdo->prepare('SELECT id, timezone FROM households WHERE id = ? FOR UPDATE');
        $statement->execute([$householdId]);
        $row = $statement->fetch();
        if (!is_array($row)) {
            throw new RuntimeException('The household is unavailable.');
        }
        return $row;
    }

    private function assertHouseholdMember(int $householdId, int $memberId): void
    {
        $statement = $this->pdo->prepare(
            "SELECT COUNT(*) FROM household_members
             WHERE id = ? AND household_id = ? AND status = 'active'"
        );
        $statement->execute([$memberId, $householdId]);
        if ((int)$statement->fetchColumn() !== 1) {
            throw new InvalidArgumentException('The selected member is not active in this household.');
        }
    }

    private function date(mixed $value, string $label): DateTimeImmutable
    {
        $text = trim((string)$value);
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $text, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if (!$date
            || (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $text) {
            throw new InvalidArgumentException($label . ' must use YYYY-MM-DD.');
        }
        return $date;
    }

    private function timeValue(mixed $value, string $label, bool $nullable = true): ?string
    {
        $text = trim((string)$value);
        if ($text === '' && $nullable) {
            return null;
        }
        if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $text) !== 1) {
            throw new InvalidArgumentException($label . ' must use HH:MM.');
        }
        return $text . ':00';
    }

    private function positiveInt(mixed $value, string $label, int $max = PHP_INT_MAX): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $max],
        ]);
        if ($integer === false) {
            throw new InvalidArgumentException($label . ' is required.');
        }
        return (int)$integer;
    }

    private function boundedInt(mixed $value, int $min, int $max, string $label): int
    {
        $integer = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        if ($integer === false) {
            throw new InvalidArgumentException($label . ' is outside the allowed range.');
        }
        return (int)$integer;
    }

    private function choice(mixed $value, array $allowed, string $label): string
    {
        $choice = trim((string)$value);
        if (!in_array($choice, $allowed, true)) {
            throw new InvalidArgumentException($label . ' is invalid.');
        }
        return $choice;
    }

    private function boolValue(mixed $value): int
    {
        return in_array($value, [1, '1', true, 'true', 'on', 'yes'], true) ? 1 : 0;
    }

    private function text(mixed $value, int $maxLength, string $label): string
    {
        $text = trim((string)$value);
        if ($text === '' || mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . ' is required and must be at most ' . $maxLength . ' characters.');
        }
        return $text;
    }

    private function nullableText(mixed $value, int $maxLength, string $label): ?string
    {
        $text = trim((string)$value);
        if ($text === '') {
            return null;
        }
        if (mb_strlen($text) > $maxLength) {
            throw new InvalidArgumentException($label . ' must be at most ' . $maxLength . ' characters.');
        }
        return $text;
    }

    private function priorityRank(string $priority): int
    {
        $rank = array_search($priority, self::PRIORITIES, true);
        return $rank === false ? 0 : $rank;
    }

    private function memberRole(int $householdId, int $memberId): string
    {
        $statement = $this->pdo->prepare(
            "SELECT role FROM household_members
             WHERE id = ? AND household_id = ? AND status = 'active'"
        );
        $statement->execute([$memberId, $householdId]);
        $role = $statement->fetchColumn();
        if (!is_string($role) || $role === '') {
            throw new InvalidArgumentException('The household member is unavailable.');
        }
        return $role;
    }

    private function canAccessVisibility(
        int $householdId,
        int $memberId,
        string $visibility,
        ?int $recipientMemberId
    ): bool {
        if ($visibility === 'household') {
            return true;
        }
        if ($visibility === 'private') {
            return $recipientMemberId !== null && $recipientMemberId === $memberId;
        }
        if ($visibility === 'adults_only') {
            return in_array(
                $this->memberRole($householdId, $memberId),
                ['owner', 'administrator', 'adult_member'],
                true
            );
        }
        return false;
    }

    private function assertNotificationAccess(int $householdId, int $memberId, array $notification): void
    {
        $recipientId = $notification['recipient_member_id'] !== null
            ? (int)$notification['recipient_member_id']
            : null;
        if (!$this->canAccessVisibility(
            $householdId,
            $memberId,
            (string)$notification['visibility'],
            $recipientId
        )) {
            throw new InvalidArgumentException('This notification is not visible to the household member.');
        }
    }

    private function sourceWatermark(int $householdId): string
    {
        $queries = [
            'tasks' => "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(updated_at),'1970-01-01')
                FROM household_tasks WHERE household_id = ?",
            'inventory' => "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(updated_at),'1970-01-01')
                FROM inventory_items WHERE household_id = ?",
            'prepared' => "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(updated_at),'1970-01-01')
                FROM prepared_food_batches WHERE household_id = ?",
            'forecast' => "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(completed_at),'1970-01-01')
                FROM forecast_snapshots WHERE household_id = ?",
            'seasonal' => "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(updated_at),'1970-01-01')
                FROM seasonal_plan_entries WHERE household_id = ?",
            'finance' => "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(created_at),'1970-01-01')
                FROM finance_recommendations WHERE household_id = ?",
            'nutrition' => "SELECT COUNT(*), COALESCE(MAX(id),0), COALESCE(MAX(created_at),'1970-01-01')
                FROM nutrition_recommendations WHERE household_id = ?",
            'meal_plans' => "SELECT COUNT(*), COALESCE(MAX(mpi.id),0), COALESCE(MAX(mpi.meal_date),'1970-01-01')
                FROM meal_plan_items mpi JOIN meal_plans mp ON mp.id = mpi.meal_plan_id
                WHERE mp.household_id = ?",
            'plantings' => "SELECT COUNT(*), COALESCE(MAX(p.id),0), COALESCE(MAX(p.expected_harvest_start),'1970-01-01')
                FROM plantings p JOIN garden_zones gz ON gz.id = p.garden_zone_id
                WHERE gz.household_id = ?",
            'notification_settings' => "SELECT COUNT(*), 0, COALESCE(MAX(updated_at),'1970-01-01')
                FROM household_notification_settings WHERE household_id = ?",
            'notification_preferences' => "SELECT COUNT(*), COALESCE(MAX(household_member_id),0), COALESCE(MAX(updated_at),'1970-01-01')
                FROM member_notification_preferences WHERE household_id = ?",
        ];
        $parts = [];
        foreach ($queries as $name => $sql) {
            $statement = $this->pdo->prepare($sql);
            $statement->execute([$householdId]);
            $parts[$name] = $statement->fetch(PDO::FETCH_NUM) ?: [];
        }
        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    private function recordNotificationEvent(
        int $householdId,
        ?int $notificationId,
        ?int $calendarEventId,
        ?int $digestRunId,
        ?int $memberId,
        string $eventType,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        ?string $notes = null
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO notification_lifecycle_events
             (household_id, notification_id, calendar_event_id, digest_run_id, member_id,
              event_type, from_status, to_status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            $householdId,
            $notificationId,
            $calendarEventId,
            $digestRunId,
            $memberId,
            $eventType,
            $fromStatus,
            $toStatus,
            $notes,
        ]);
    }

    private function notificationVisibility(?int $recipientMemberId, bool $adultsOnly = false): string
    {
        if ($recipientMemberId !== null) {
            return 'private';
        }
        return $adultsOnly ? 'adults_only' : 'household';
    }
}
