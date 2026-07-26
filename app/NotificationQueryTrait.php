<?php

declare(strict_types=1);

namespace Homestead;

trait NotificationQueryTrait
{
    public function dashboardData(int $householdId, int $memberId): array
    {
        $this->assertActiveMember($householdId, $memberId);
        $settings = $this->settings($householdId);
        $preference = $this->memberPreference($householdId, $memberId);
        $adultAccess = in_array(
            $this->memberRole($householdId, $memberId),
            ['owner', 'administrator', 'adult_member'],
            true
        ) ? 1 : 0;

        $members = $this->pdo->prepare(
            "SELECT hm.id, hm.display_name, hm.role, hm.age_group,
                    mnp.minimum_priority, mnp.digest_cadence,
                    mnp.email_enabled, mnp.web_push_enabled,
                    mnp.allow_sensitive_previews, mnp.enabled_categories,
                    mnp.quiet_start, mnp.quiet_end
             FROM household_members hm
             LEFT JOIN member_notification_preferences mnp
               ON mnp.household_id = hm.household_id AND mnp.household_member_id = hm.id
             WHERE hm.household_id = ? AND hm.status = 'active'
             ORDER BY FIELD(hm.role,'owner','administrator','adult_member','youth_member','guest_helper'), hm.display_name"
        );
        $members->execute([$householdId]);

        $notifications = $this->pdo->prepare(
            "SELECT hn.*, hm.display_name AS recipient_name
             FROM household_notifications hn
             LEFT JOIN household_members hm
               ON hm.id = hn.recipient_member_id AND hm.household_id = hn.household_id
             WHERE hn.household_id = ?
               AND (
                   hn.visibility = 'household'
                   OR (hn.visibility = 'adults_only' AND ? = 1)
                   OR (hn.visibility = 'private' AND hn.recipient_member_id = ?)
               )
               AND hn.status <> 'expired'
             ORDER BY
               FIELD(hn.status,'unread','acknowledged','completed','dismissed'),
               FIELD(hn.priority,'critical','high','medium','low'),
               hn.due_at IS NULL, hn.due_at, hn.id DESC
             LIMIT 120"
        );
        $notifications->execute([$householdId, $adultAccess, $memberId]);

        $calendar = $this->pdo->prepare(
            "SELECT hce.*
             FROM household_calendar_events hce
             WHERE hce.household_id = ? AND hce.status = 'scheduled'
               AND (
                   hce.visibility = 'household'
                   OR (hce.visibility = 'adults_only' AND ? = 1)
                   OR (hce.visibility = 'private' AND hce.recipient_member_id = ?)
               )
               AND hce.starts_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 DAY)
             ORDER BY hce.starts_at, hce.id
             LIMIT 120"
        );
        $calendar->execute([$householdId, $adultAccess, $memberId]);

        $runs = $this->pdo->prepare(
            'SELECT * FROM notification_sync_runs
             WHERE household_id = ? ORDER BY started_at DESC, id DESC LIMIT 12'
        );
        $runs->execute([$householdId]);

        $digests = $this->pdo->prepare(
            'SELECT ndr.*, hm.display_name AS recipient_name
             FROM notification_digest_runs ndr
             JOIN household_members hm
               ON hm.id = ndr.recipient_member_id AND hm.household_id = ndr.household_id
             WHERE ndr.household_id = ?
               AND ndr.recipient_member_id = ?
             ORDER BY ndr.created_at DESC, ndr.id DESC LIMIT 12'
        );
        $digests->execute([$householdId, $memberId]);

        $countsStatement = $this->pdo->prepare(
            "SELECT
                SUM(status = 'unread') AS unread_count,
                SUM(status = 'acknowledged') AS acknowledged_count,
                SUM(priority IN ('high','critical') AND status IN ('unread','acknowledged')) AS urgent_count,
                SUM(related_task_id IS NOT NULL) AS task_link_count
             FROM household_notifications
             WHERE household_id = ?
               AND (
                   visibility = 'household'
                   OR (visibility = 'adults_only' AND ? = 1)
                   OR (visibility = 'private' AND recipient_member_id = ?)
               )
               AND status <> 'expired'"
        );
        $countsStatement->execute([$householdId, $adultAccess, $memberId]);
        $counts = $countsStatement->fetch() ?: [
            'unread_count' => 0,
            'acknowledged_count' => 0,
            'urgent_count' => 0,
            'task_link_count' => 0,
        ];

        $outbox = $this->pdo->prepare(
            "SELECT status, channel, COUNT(*) AS total
             FROM notification_delivery_outbox
             WHERE household_id = ?
             GROUP BY status, channel
             ORDER BY channel, status"
        );
        $outbox->execute([$householdId]);

        return [
            'settings' => $settings,
            'preference' => $preference,
            'members' => $members->fetchAll(),
            'notifications' => $notifications->fetchAll(),
            'calendar_events' => $calendar->fetchAll(),
            'sync_runs' => $runs->fetchAll(),
            'digests' => $digests->fetchAll(),
            'counts' => $counts,
            'outbox' => $outbox->fetchAll(),
            'categories' => self::CATEGORIES,
            'priorities' => self::PRIORITIES,
        ];
    }

    public function calendarEvents(int $householdId, int $memberId, string $startsOn, string $endsOn): array
    {
        $this->assertActiveMember($householdId, $memberId);
        $start = $this->date($startsOn, 'Calendar start');
        $end = $this->date($endsOn, 'Calendar end');
        $adultAccess = in_array(
            $this->memberRole($householdId, $memberId),
            ['owner', 'administrator', 'adult_member'],
            true
        ) ? 1 : 0;
        if ($end < $start || $end->diff($start)->days > 366) {
            throw new \InvalidArgumentException('Calendar export window is invalid.');
        }
        $statement = $this->pdo->prepare(
            "SELECT hce.*
             FROM household_calendar_events hce
             WHERE hce.household_id = ? AND hce.status = 'scheduled'
               AND (
                   hce.visibility = 'household'
                   OR (hce.visibility = 'adults_only' AND ? = 1)
                   OR (hce.visibility = 'private' AND hce.recipient_member_id = ?)
               )
               AND hce.starts_at >= ? AND hce.starts_at < ?
             ORDER BY hce.starts_at, hce.id"
        );
        $statement->execute([
            $householdId,
            $adultAccess,
            $memberId,
            $start->format('Y-m-d 00:00:00'),
            $end->modify('+1 day')->format('Y-m-d 00:00:00'),
        ]);
        return $statement->fetchAll();
    }
}
