<?php

declare(strict_types=1);

namespace Homestead;

use InvalidArgumentException;

trait NotificationSettingsTrait
{
    public function settings(int $householdId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM household_notification_settings WHERE household_id = ?'
        );
        $statement->execute([$householdId]);
        $row = $statement->fetch();
        if (is_array($row)) {
            return $row;
        }
        return [
            'household_id' => $householdId,
            'due_soon_days' => 3,
            'forecast_alert_days' => 14,
            'prepared_food_alert_days' => 3,
            'digest_cadence' => 'daily',
            'digest_hour' => 7,
            'quiet_start' => null,
            'quiet_end' => null,
            'email_adapter_enabled' => 0,
            'web_push_adapter_enabled' => 0,
        ];
    }

    public function saveSettings(int $householdId, int $memberId, array $input): void
    {
        $this->assertActiveMember($householdId, $memberId);
        $dueSoonDays = $this->boundedInt($input['due_soon_days'] ?? 3, 1, 30, 'Due-soon days');
        $forecastDays = $this->boundedInt($input['forecast_alert_days'] ?? 14, 1, 90, 'Forecast alert days');
        $preparedDays = $this->boundedInt($input['prepared_food_alert_days'] ?? 3, 1, 30, 'Prepared-food alert days');
        $cadence = $this->choice($input['digest_cadence'] ?? 'daily', ['off', 'daily', 'weekly'], 'Digest cadence');
        $digestHour = $this->boundedInt($input['digest_hour'] ?? 7, 0, 23, 'Digest hour');
        $quietStart = $this->timeValue($input['quiet_start'] ?? null, 'Quiet start');
        $quietEnd = $this->timeValue($input['quiet_end'] ?? null, 'Quiet end');
        if (($quietStart === null) !== ($quietEnd === null)) {
            throw new InvalidArgumentException('Quiet start and quiet end must be supplied together.');
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO household_notification_settings
             (household_id, due_soon_days, forecast_alert_days, prepared_food_alert_days,
              digest_cadence, digest_hour, quiet_start, quiet_end,
              email_adapter_enabled, web_push_adapter_enabled, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                due_soon_days = VALUES(due_soon_days),
                forecast_alert_days = VALUES(forecast_alert_days),
                prepared_food_alert_days = VALUES(prepared_food_alert_days),
                digest_cadence = VALUES(digest_cadence),
                digest_hour = VALUES(digest_hour),
                quiet_start = VALUES(quiet_start),
                quiet_end = VALUES(quiet_end),
                email_adapter_enabled = VALUES(email_adapter_enabled),
                web_push_adapter_enabled = VALUES(web_push_adapter_enabled),
                updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([
            $householdId,
            $dueSoonDays,
            $forecastDays,
            $preparedDays,
            $cadence,
            $digestHour,
            $quietStart,
            $quietEnd,
            $this->boolValue($input['email_adapter_enabled'] ?? 0),
            $this->boolValue($input['web_push_adapter_enabled'] ?? 0),
            $memberId,
        ]);
    }

    public function saveMemberPreferences(int $householdId, int $memberId, array $input): void
    {
        $this->assertActiveMember($householdId, $memberId);
        $targetMemberId = $this->positiveInt($input['household_member_id'] ?? null, 'Household member');
        $this->assertHouseholdMember($householdId, $targetMemberId);
        $minimumPriority = $this->choice(
            $input['minimum_priority'] ?? 'low',
            self::PRIORITIES,
            'Minimum priority'
        );
        $cadence = $this->choice(
            $input['digest_cadence'] ?? 'inherit',
            ['inherit', 'off', 'daily', 'weekly'],
            'Member digest cadence'
        );
        $quietStart = $this->timeValue($input['quiet_start'] ?? null, 'Member quiet start');
        $quietEnd = $this->timeValue($input['quiet_end'] ?? null, 'Member quiet end');
        if (($quietStart === null) !== ($quietEnd === null)) {
            throw new InvalidArgumentException('Member quiet start and quiet end must be supplied together.');
        }

        $categoriesInput = $input['enabled_categories'] ?? self::CATEGORIES;
        if (!is_array($categoriesInput)) {
            throw new InvalidArgumentException('Enabled categories must be a list.');
        }
        $categories = [];
        foreach ($categoriesInput as $category) {
            $category = trim((string)$category);
            if (!in_array($category, self::CATEGORIES, true)) {
                throw new InvalidArgumentException('A notification category is invalid.');
            }
            $categories[$category] = true;
        }
        $categories = array_keys($categories);

        $statement = $this->pdo->prepare(
            'INSERT INTO member_notification_preferences
             (household_id, household_member_id, minimum_priority, enabled_categories,
              digest_cadence, email_enabled, web_push_enabled, allow_sensitive_previews,
              quiet_start, quiet_end, updated_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                minimum_priority = VALUES(minimum_priority),
                enabled_categories = VALUES(enabled_categories),
                digest_cadence = VALUES(digest_cadence),
                email_enabled = VALUES(email_enabled),
                web_push_enabled = VALUES(web_push_enabled),
                allow_sensitive_previews = VALUES(allow_sensitive_previews),
                quiet_start = VALUES(quiet_start),
                quiet_end = VALUES(quiet_end),
                updated_by_member_id = VALUES(updated_by_member_id)'
        );
        $statement->execute([
            $householdId,
            $targetMemberId,
            $minimumPriority,
            json_encode($categories, JSON_THROW_ON_ERROR),
            $cadence,
            $this->boolValue($input['email_enabled'] ?? 0),
            $this->boolValue($input['web_push_enabled'] ?? 0),
            $this->boolValue($input['allow_sensitive_previews'] ?? 0),
            $quietStart,
            $quietEnd,
            $memberId,
        ]);
    }

    private function memberPreference(int $householdId, int $memberId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM member_notification_preferences
             WHERE household_id = ? AND household_member_id = ?'
        );
        $statement->execute([$householdId, $memberId]);
        $row = $statement->fetch();
        if (is_array($row)) {
            $decoded = json_decode((string)($row['enabled_categories'] ?? '[]'), true);
            $row['enabled_categories_list'] = is_array($decoded) ? array_values($decoded) : self::CATEGORIES;
            return $row;
        }
        return [
            'household_id' => $householdId,
            'household_member_id' => $memberId,
            'minimum_priority' => 'low',
            'enabled_categories' => json_encode(self::CATEGORIES, JSON_THROW_ON_ERROR),
            'enabled_categories_list' => self::CATEGORIES,
            'digest_cadence' => 'inherit',
            'email_enabled' => 0,
            'web_push_enabled' => 0,
            'allow_sensitive_previews' => 0,
            'quiet_start' => null,
            'quiet_end' => null,
        ];
    }
}
