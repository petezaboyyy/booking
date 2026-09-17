-- Concrete per-date rows behind every reservation (modules/booking/models/booking.php).
-- Prefix is `app` (settings/database.php). Without this table every booking
-- fails with "Base table or view not found: 1146 Table '<db>.app_reservation_occurrence'".
-- Deployments upgraded from an older schema need this; install/database.sql
-- already carries it for fresh installs.
CREATE TABLE IF NOT EXISTS `app_reservation_occurrence` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_id` int(11) NOT NULL,
  `room_id` int(11) NOT NULL,
  `begin` datetime NOT NULL,
  `end` datetime NOT NULL,
  `status` tinyint(1) NOT NULL DEFAULT 0,
  `cancelled_by` int(11) DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancellation_reason` varchar(255) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_reservation_occurrence` (`reservation_id`,`begin`,`end`),
  KEY `idx_occurrence_availability` (`room_id`,`status`,`begin`,`end`),
  KEY `idx_occurrence_reservation` (`reservation_id`,`status`,`begin`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Backfill for `continuous` bookings only. Recurring ones (daily-slot /
-- weekly-slot) need one row per day, so run scripts/migrate_occurrence.php
-- instead if the site already holds recurring reservations.
INSERT IGNORE INTO `app_reservation_occurrence`
  (`reservation_id`, `room_id`, `begin`, `end`, `status`, `created_at`, `updated_at`)
SELECT R.`id`, R.`room_id`, R.`begin`, R.`end`, R.`status`, NOW(), NOW()
FROM `app_reservation` R
WHERE R.`schedule_type` = 'continuous'
  AND R.`begin` IS NOT NULL AND R.`end` IS NOT NULL AND R.`end` > R.`begin`
  AND NOT EXISTS (SELECT 1 FROM `app_reservation_occurrence` O WHERE O.`reservation_id` = R.`id`);
