<?php

declare(strict_types=1);

namespace Homestead;

trait NotificationPrivacyTrait
{
    private function upsertCalendarEvent(
        int $householdId,
        int $memberId,
        ?int $notificationId,
        array $data
    ): int {
        $eventKey = hash('sha256', 'phase11-calendar|' . $householdId . '|' . (string)$data['event_seed']);
        $existing = $this->pdo->prepare(
            'SELECT id FROM household_calendar_events WHERE household_id = ? AND event_key = ?'
        );
        $existing->execute([$householdId, $eventKey]);
        $existingId = $existing->fetchColumn();

        $recipientId = null;
        if ($notificationId !== null) {
            $recipientQuery = $this->pdo->prepare(
                'SELECT recipient_member_id FROM household_notifications
                 WHERE id = ? AND household_id = ?'
            );
            $recipientQuery->execute([$notificationId, $householdId]);
            $recipientValue = $recipientQuery->fetchColumn();
            if ($recipientValue !== false && $recipientValue !== null) {
                $recipientId = (int)$recipientValue;
            }
        }
        if ($recipientId === null && array_key_exists('recipient_member_id', $data)
            && $data['recipient_member_id'] !== null) {
            $recipientId = (int)$data['recipient_member_id'];
        }
        if ($recipientId !== null) {
            $this->assertHouseholdMember($householdId, $recipientId);
        }

        $statement = $this->pdo->prepare(
            'INSERT INTO household_calendar_events
             (household_id, notification_id, recipient_member_id, source_type, source_id,
              event_key, title, description, starts_at, ends_at, all_day, visibility,
              status, created_by_member_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "scheduled", ?)
             ON DUPLICATE KEY UPDATE
                notification_id = VALUES(notification_id),
                recipient_member_id = VALUES(recipient_member_id),
                source_type = VALUES(source_type),
                source_id = VALUES(source_id),
                title = VALUES(title),
                description = VALUES(description),
                starts_at = VALUES(starts_at),
                ends_at = VALUES(ends_at),
                all_day = VALUES(all_day),
                visibility = VALUES(visibility),
                status = IF(status = "cancelled", "scheduled", status)'
        );
        $statement->execute([
            $householdId,
            $notificationId,
            $recipientId,
            $this->text($data['source_type'], 80, 'Calendar source type'),
            $data['source_id'] !== null ? (int)$data['source_id'] : null,
            $eventKey,
            $this->text($data['title'], 180, 'Calendar title'),
            $this->nullableText($data['description'], 5000, 'Calendar description'),
            (string)$data['starts_at'],
            $data['ends_at'],
            $this->boolValue($data['all_day'] ?? 0),
            $this->choice($data['visibility'], ['household', 'adults_only', 'private'], 'Calendar visibility'),
            $memberId,
        ]);
        $calendarId = $existingId === false ? (int)$this->pdo->lastInsertId() : (int)$existingId;
        if ($existingId === false) {
            $this->recordNotificationEvent(
                $householdId,
                $notificationId,
                $calendarId,
                null,
                $memberId,
                'calendar_event_created',
                null,
                'scheduled'
            );
        }
        return $calendarId;
    }

    private function queueDeliveryCandidates(int $householdId, int $notificationId): void
    {
        $settings = $this->settings($householdId);
        if ((int)$settings['email_adapter_enabled'] !== 1
            && (int)$settings['web_push_adapter_enabled'] !== 1) {
            return;
        }

        $query = $this->pdo->prepare(
            'SELECT * FROM household_notifications WHERE id = ? AND household_id = ?'
        );
        $query->execute([$notificationId, $householdId]);
        $notification = $query->fetch();
        if (!is_array($notification)) {
            return;
        }

        if ($notification['recipient_member_id'] !== null) {
            $memberIds = [(int)$notification['recipient_member_id']];
        } else {
            $members = $this->pdo->prepare(
                "SELECT id FROM household_members
                 WHERE household_id = ? AND status = 'active' AND user_id IS NOT NULL
                 ORDER BY id"
            );
            $members->execute([$householdId]);
            $memberIds = array_map('intval', $members->fetchAll(\PDO::FETCH_COLUMN));
        }

        $insert = $this->pdo->prepare(
            'INSERT IGNORE INTO notification_delivery_outbox
             (household_id, notification_id, recipient_member_id, channel, status, payload_json, available_at)
             VALUES (?, ?, ?, ?, "pending", ?, UTC_TIMESTAMP())'
        );
        $notificationRecipientId = $notification['recipient_member_id'] !== null
            ? (int)$notification['recipient_member_id']
            : null;
        foreach ($memberIds as $recipientId) {
            if (!$this->canAccessVisibility(
                $householdId,
                $recipientId,
                (string)$notification['visibility'],
                $notificationRecipientId
            )) {
                continue;
            }

            $preference = $this->memberPreference($householdId, $recipientId);
            if ($this->priorityRank((string)$notification['priority'])
                < $this->priorityRank((string)$preference['minimum_priority'])) {
                continue;
            }
            if (!in_array(
                (string)$notification['category'],
                (array)$preference['enabled_categories_list'],
                true
            )) {
                continue;
            }
            $privatePreview = (int)$notification['sensitive_preview'] === 1
                && (int)$preference['allow_sensitive_previews'] !== 1;
            $payload = json_encode([
                'notification_id' => $notificationId,
                'category' => (string)$notification['category'],
                'priority' => (string)$notification['priority'],
                'title' => $privatePreview
                    ? 'Private household notification'
                    : (string)$notification['title'],
                'body' => $privatePreview
                    ? 'Open Homestead to review this private household update.'
                    : (string)($notification['body'] ?? ''),
                'due_at' => $notification['due_at'],
            ], JSON_THROW_ON_ERROR);

            if ((int)$settings['email_adapter_enabled'] === 1
                && (int)$preference['email_enabled'] === 1) {
                $insert->execute([$householdId, $notificationId, $recipientId, 'email', $payload]);
            }
            if ((int)$settings['web_push_adapter_enabled'] === 1
                && (int)$preference['web_push_enabled'] === 1) {
                $insert->execute([$householdId, $notificationId, $recipientId, 'web_push', $payload]);
            }
        }
    }
}
