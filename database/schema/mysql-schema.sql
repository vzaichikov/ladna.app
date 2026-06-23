/*M!999999\- enable the sandbox mode */ 
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*M!100616 SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0 */;
DROP TABLE IF EXISTS `account_memberships`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_memberships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `role` varchar(255) NOT NULL DEFAULT 'owner',
  `permissions` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`permissions`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_memberships_account_id_user_id_unique` (`account_id`,`user_id`),
  KEY `account_memberships_user_id_role_index` (`user_id`,`role`),
  KEY `account_memberships_account_id_role_index` (`account_id`,`role`),
  CONSTRAINT `account_memberships_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `account_memberships_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `account_subscriptions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `account_subscriptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `subscription_plan_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'trialing',
  `started_at` timestamp NULL DEFAULT NULL,
  `ends_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_subscriptions_account_id_unique` (`account_id`),
  KEY `account_subscriptions_subscription_plan_id_status_index` (`subscription_plan_id`,`status`),
  CONSTRAINT `account_subscriptions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `account_subscriptions_subscription_plan_id_foreign` FOREIGN KEY (`subscription_plan_id`) REFERENCES `subscription_plans` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `accounts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `accounts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `default_language` varchar(5) NOT NULL DEFAULT 'uk',
  `country_code` varchar(2) NOT NULL DEFAULT 'UA',
  `default_currency` varchar(3) NOT NULL DEFAULT 'UAH',
  `logo_path` varchar(255) DEFAULT NULL,
  `brand_color` varchar(7) DEFAULT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `enabled_schedule_kinds` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`enabled_schedule_kinds`)),
  `schedule_kind_colors` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`schedule_kind_colors`)),
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounts_slug_unique` (`slug`),
  KEY `accounts_status_index` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `activity_directions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `activity_directions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `activity_directions_account_id_slug_unique` (`account_id`,`slug`),
  KEY `activity_directions_account_id_is_active_index` (`account_id`,`is_active`),
  CONSTRAINT `activity_directions_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `cache_locks`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `scheduled_class_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `booked_by_user_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'booked',
  `attended_at` timestamp NULL DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_bookings_scheduled_class_id_customer_id_unique` (`scheduled_class_id`,`customer_id`),
  KEY `class_bookings_booked_by_user_id_foreign` (`booked_by_user_id`),
  KEY `class_bookings_account_id_status_index` (`account_id`,`status`),
  KEY `class_bookings_customer_id_status_index` (`customer_id`,`status`),
  CONSTRAINT `class_bookings_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_bookings_booked_by_user_id_foreign` FOREIGN KEY (`booked_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `class_bookings_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_bookings_scheduled_class_id_foreign` FOREIGN KEY (`scheduled_class_id`) REFERENCES `scheduled_classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_pass_plan_activity_direction`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_pass_plan_activity_direction` (
  `class_pass_plan_id` bigint(20) unsigned NOT NULL,
  `activity_direction_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`class_pass_plan_id`,`activity_direction_id`),
  KEY `class_pass_plan_direction_direction_index` (`activity_direction_id`),
  CONSTRAINT `class_pass_plan_activity_direction_activity_direction_id_foreign` FOREIGN KEY (`activity_direction_id`) REFERENCES `activity_directions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_pass_plan_activity_direction_class_pass_plan_id_foreign` FOREIGN KEY (`class_pass_plan_id`) REFERENCES `class_pass_plans` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_pass_plan_class_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_pass_plan_class_type` (
  `class_pass_plan_id` bigint(20) unsigned NOT NULL,
  `class_type_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`class_pass_plan_id`,`class_type_id`),
  KEY `class_pass_plan_class_type_type_index` (`class_type_id`),
  CONSTRAINT `class_pass_plan_class_type_class_pass_plan_id_foreign` FOREIGN KEY (`class_pass_plan_id`) REFERENCES `class_pass_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_pass_plan_class_type_class_type_id_foreign` FOREIGN KEY (`class_type_id`) REFERENCES `class_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_pass_plan_room`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_pass_plan_room` (
  `class_pass_plan_id` bigint(20) unsigned NOT NULL,
  `room_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`class_pass_plan_id`,`room_id`),
  KEY `class_pass_plan_room_room_index` (`room_id`),
  CONSTRAINT `class_pass_plan_room_class_pass_plan_id_foreign` FOREIGN KEY (`class_pass_plan_id`) REFERENCES `class_pass_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_pass_plan_room_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_pass_plan_trainer_type`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_pass_plan_trainer_type` (
  `class_pass_plan_id` bigint(20) unsigned NOT NULL,
  `trainer_type_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`class_pass_plan_id`,`trainer_type_id`),
  KEY `class_pass_plan_trainer_type_type_index` (`trainer_type_id`),
  CONSTRAINT `class_pass_plan_trainer_type_class_pass_plan_id_foreign` FOREIGN KEY (`class_pass_plan_id`) REFERENCES `class_pass_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_pass_plan_trainer_type_trainer_type_id_foreign` FOREIGN KEY (`trainer_type_id`) REFERENCES `trainer_types` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_pass_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_pass_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `schedule_kind` varchar(255) NOT NULL DEFAULT 'group_class',
  `description` text DEFAULT NULL,
  `price_cents` int(10) unsigned NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'UAH',
  `sessions_count` smallint(5) unsigned NOT NULL,
  `validity_days` smallint(5) unsigned NOT NULL DEFAULT 30,
  `available_from_time` time DEFAULT NULL,
  `available_until_time` time DEFAULT NULL,
  `allows_any_time` tinyint(1) NOT NULL DEFAULT 0,
  `is_trial` tinyint(1) NOT NULL DEFAULT 0,
  `any_time_addon_price_cents` int(10) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_pass_plans_account_id_slug_unique` (`account_id`,`slug`),
  KEY `class_pass_plans_account_id_is_active_sort_order_index` (`account_id`,`is_active`,`sort_order`),
  KEY `class_pass_plans_account_active_trial_index` (`account_id`,`is_active`,`is_trial`),
  KEY `class_pass_plans_account_kind_active_sort_index` (`account_id`,`schedule_kind`,`is_active`,`sort_order`),
  CONSTRAINT `class_pass_plans_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `class_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `class_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `activity_direction_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `color` varchar(7) DEFAULT NULL,
  `schedule_kind` varchar(255) NOT NULL DEFAULT 'group_class',
  `default_duration_minutes` smallint(5) unsigned NOT NULL DEFAULT 60,
  `booking_cutoff_minutes` smallint(5) unsigned DEFAULT NULL,
  `default_capacity` smallint(5) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_types_account_id_slug_unique` (`account_id`,`slug`),
  KEY `class_types_account_id_is_active_index` (`account_id`,`is_active`),
  KEY `class_types_activity_direction_id_foreign` (`activity_direction_id`),
  KEY `class_types_account_id_schedule_kind_is_active_index` (`account_id`,`schedule_kind`,`is_active`),
  CONSTRAINT `class_types_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `class_types_activity_direction_id_foreign` FOREIGN KEY (`activity_direction_id`) REFERENCES `activity_directions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_auth_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_auth_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `allow_email_password` tinyint(1) NOT NULL DEFAULT 1,
  `allow_otp` tinyint(1) NOT NULL DEFAULT 0,
  `allow_google` tinyint(1) NOT NULL DEFAULT 0,
  `otp_sender_scope` varchar(255) NOT NULL DEFAULT 'platform',
  `otp_provider` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_auth_settings_account_id_unique` (`account_id`),
  KEY `customer_auth_settings_otp_sender_scope_otp_provider_index` (`otp_sender_scope`,`otp_provider`),
  CONSTRAINT `customer_auth_settings_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_class_pass_reservations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_class_pass_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `customer_class_pass_id` bigint(20) unsigned NOT NULL,
  `class_booking_id` bigint(20) unsigned NOT NULL,
  `scheduled_class_id` bigint(20) unsigned NOT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'reserved',
  `reserved_at` timestamp NULL DEFAULT NULL,
  `used_at` timestamp NULL DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `class_pass_reservation_booking_unique` (`class_booking_id`),
  KEY `customer_class_pass_reservations_scheduled_class_id_foreign` (`scheduled_class_id`),
  KEY `class_pass_reservation_pass_status_index` (`customer_class_pass_id`,`status`),
  KEY `class_pass_reservation_account_status_index` (`account_id`,`status`),
  CONSTRAINT `customer_class_pass_reservations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_class_pass_reservations_class_booking_id_foreign` FOREIGN KEY (`class_booking_id`) REFERENCES `class_bookings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_class_pass_reservations_customer_class_pass_id_foreign` FOREIGN KEY (`customer_class_pass_id`) REFERENCES `customer_class_passes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_class_pass_reservations_scheduled_class_id_foreign` FOREIGN KEY (`scheduled_class_id`) REFERENCES `scheduled_classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_class_passes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_class_passes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned NOT NULL,
  `class_pass_plan_id` bigint(20) unsigned DEFAULT NULL,
  `code` varchar(16) NOT NULL,
  `source` varchar(255) NOT NULL DEFAULT 'manual',
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `plan_name` varchar(255) NOT NULL,
  `plan_slug` varchar(255) DEFAULT NULL,
  `price_cents` int(10) unsigned NOT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'UAH',
  `sessions_count` smallint(5) unsigned NOT NULL,
  `validity_days` smallint(5) unsigned NOT NULL,
  `reserved_sessions_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `used_sessions_count` smallint(5) unsigned NOT NULL DEFAULT 0,
  `purchased_at` timestamp NULL DEFAULT NULL,
  `opened_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_class_passes_code_unique` (`code`),
  UNIQUE KEY `customer_class_passes_account_id_code_unique` (`account_id`,`code`),
  KEY `customer_class_passes_account_active_status_index` (`account_id`,`is_active`,`status`,`purchased_at`),
  KEY `customer_class_passes_customer_active_index` (`customer_id`,`is_active`,`purchased_at`),
  KEY `customer_class_passes_plan_status_index` (`class_pass_plan_id`,`status`),
  CONSTRAINT `customer_class_passes_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `customer_class_passes_class_pass_plan_id_foreign` FOREIGN KEY (`class_pass_plan_id`) REFERENCES `class_pass_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `customer_class_passes_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_otp_challenges`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_otp_challenges` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `phone` varchar(255) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `resend_available_at` timestamp NULL DEFAULT NULL,
  `attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `send_count` tinyint(3) unsigned NOT NULL DEFAULT 1,
  `last_sent_at` timestamp NULL DEFAULT NULL,
  `provider_scope` varchar(255) NOT NULL,
  `provider` varchar(255) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `customer_otp_lookup_index` (`account_id`,`phone`,`consumed_at`,`expires_at`),
  KEY `customer_otp_challenges_expires_at_index` (`expires_at`),
  CONSTRAINT `customer_otp_challenges_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customer_remember_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customer_remember_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint(20) unsigned NOT NULL,
  `selector` varchar(32) NOT NULL,
  `token_hash` varchar(128) NOT NULL,
  `expires_at` timestamp NOT NULL,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customer_remember_tokens_selector_unique` (`selector`),
  KEY `customer_remember_tokens_customer_id_foreign` (`customer_id`),
  KEY `customer_remember_tokens_expires_at_index` (`expires_at`),
  CONSTRAINT `customer_remember_tokens_customer_id_foreign` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `customers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `password` varchar(255) DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `default_language` varchar(5) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `phone_verified_at` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `customers_account_id_email_unique` (`account_id`,`email`),
  UNIQUE KEY `customers_account_id_phone_unique` (`account_id`,`phone`),
  UNIQUE KEY `customers_account_id_google_id_unique` (`account_id`,`google_id`),
  KEY `customers_account_id_created_at_index` (`account_id`,`created_at`),
  CONSTRAINT `customers_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `failed_jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `integration_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `integration_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(255) NOT NULL,
  `scope_id` bigint(20) unsigned NOT NULL DEFAULT 0,
  `account_id` bigint(20) unsigned DEFAULT NULL,
  `provider` varchar(255) NOT NULL,
  `category` varchar(255) NOT NULL,
  `is_enabled` tinyint(1) NOT NULL DEFAULT 0,
  `credentials` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `integration_settings_scope_type_scope_id_provider_unique` (`scope_type`,`scope_id`,`provider`),
  KEY `integration_settings_account_id_foreign` (`account_id`),
  KEY `integration_settings_scope_type_scope_id_category_index` (`scope_type`,`scope_id`,`category`),
  KEY `integration_settings_provider_is_enabled_index` (`provider`,`is_enabled`),
  CONSTRAINT `integration_settings_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `job_batches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `jobs`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `locations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `locations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `address` text DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `email` varchar(255) DEFAULT NULL,
  `timezone` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `locations_account_id_slug_unique` (`account_id`,`slug`),
  KEY `locations_account_id_is_active_index` (`account_id`,`is_active`),
  CONSTRAINT `locations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `migrations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(255) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `rooms`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `rooms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `capacity` smallint(5) unsigned DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `rooms_location_id_slug_unique` (`location_id`,`slug`),
  KEY `rooms_account_id_location_id_is_active_index` (`account_id`,`location_id`,`is_active`),
  CONSTRAINT `rooms_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `rooms_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `schedule_series`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `schedule_series` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `room_id` bigint(20) unsigned NOT NULL,
  `class_type_id` bigint(20) unsigned NOT NULL,
  `trainer_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) DEFAULT NULL,
  `description` text DEFAULT NULL,
  `weekday` tinyint(3) unsigned NOT NULL,
  `start_time` time NOT NULL,
  `start_date` date NOT NULL,
  `end_date` date DEFAULT NULL,
  `capacity` smallint(5) unsigned DEFAULT NULL,
  `duration_minutes` smallint(5) unsigned DEFAULT NULL,
  `booking_cutoff_minutes` smallint(5) unsigned DEFAULT NULL,
  `status` varchar(255) NOT NULL DEFAULT 'active',
  `generated_until` date DEFAULT NULL,
  `generated_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `schedule_series_room_id_foreign` (`room_id`),
  KEY `schedule_series_class_type_id_foreign` (`class_type_id`),
  KEY `schedule_series_account_id_index` (`account_id`),
  KEY `schedule_series_location_id_room_id_weekday_index` (`location_id`,`room_id`,`weekday`),
  KEY `schedule_series_status_start_date_end_date_index` (`status`,`start_date`,`end_date`),
  KEY `schedule_series_trainer_id_foreign` (`trainer_id`),
  CONSTRAINT `schedule_series_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedule_series_class_type_id_foreign` FOREIGN KEY (`class_type_id`) REFERENCES `class_types` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedule_series_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedule_series_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `schedule_series_trainer_id_foreign` FOREIGN KEY (`trainer_id`) REFERENCES `trainers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `scheduled_classes`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `scheduled_classes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `room_id` bigint(20) unsigned DEFAULT NULL,
  `class_type_id` bigint(20) unsigned DEFAULT NULL,
  `trainer_id` bigint(20) unsigned DEFAULT NULL,
  `schedule_series_id` bigint(20) unsigned DEFAULT NULL,
  `title` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `capacity` smallint(5) unsigned DEFAULT NULL,
  `booking_cutoff_minutes` smallint(5) unsigned DEFAULT NULL,
  `is_generated` tinyint(1) NOT NULL DEFAULT 0,
  `is_manually_modified` tinyint(1) NOT NULL DEFAULT 0,
  `metadata` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata`)),
  `is_public` tinyint(1) NOT NULL DEFAULT 1,
  `status` varchar(255) NOT NULL DEFAULT 'scheduled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `scheduled_classes_class_type_id_foreign` (`class_type_id`),
  KEY `scheduled_classes_account_id_index` (`account_id`),
  KEY `scheduled_classes_location_id_index` (`location_id`),
  KEY `scheduled_classes_starts_at_index` (`starts_at`),
  KEY `scheduled_classes_is_public_status_index` (`is_public`,`status`),
  KEY `scheduled_classes_location_id_is_public_status_starts_at_index` (`location_id`,`is_public`,`status`,`starts_at`),
  KEY `scheduled_classes_room_id_starts_at_index` (`room_id`,`starts_at`),
  KEY `scheduled_classes_schedule_series_id_starts_at_index` (`schedule_series_id`,`starts_at`),
  KEY `scheduled_classes_trainer_id_foreign` (`trainer_id`),
  CONSTRAINT `scheduled_classes_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scheduled_classes_class_type_id_foreign` FOREIGN KEY (`class_type_id`) REFERENCES `class_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scheduled_classes_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `locations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `scheduled_classes_room_id_foreign` FOREIGN KEY (`room_id`) REFERENCES `rooms` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scheduled_classes_schedule_series_id_foreign` FOREIGN KEY (`schedule_series_id`) REFERENCES `schedule_series` (`id`) ON DELETE SET NULL,
  CONSTRAINT `scheduled_classes_trainer_id_foreign` FOREIGN KEY (`trainer_id`) REFERENCES `trainers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `sessions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `subscription_plans`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `subscription_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `description` text DEFAULT NULL,
  `price_cents` int(10) unsigned DEFAULT NULL,
  `currency` varchar(3) NOT NULL DEFAULT 'UAH',
  `billing_interval` varchar(255) NOT NULL DEFAULT 'monthly',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `subscription_plans_slug_unique` (`slug`),
  KEY `subscription_plans_is_active_sort_order_index` (`is_active`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `system_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `system_settings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `value` text DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `system_settings_key_unique` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trainer_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trainer_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `name` varchar(255) NOT NULL,
  `icon` varchar(255) NOT NULL DEFAULT 'user-round',
  `color` varchar(7) NOT NULL DEFAULT '#3B223F',
  `is_default` tinyint(1) NOT NULL DEFAULT 0,
  `sort_order` smallint(5) unsigned NOT NULL DEFAULT 0,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `trainer_types_account_id_name_unique` (`account_id`,`name`),
  KEY `trainer_types_account_id_is_default_sort_order_index` (`account_id`,`is_default`,`sort_order`),
  CONSTRAINT `trainer_types_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `trainers`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `trainers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `account_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `trainer_type_id` bigint(20) unsigned DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `bio` text DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `instructors_account_id_slug_unique` (`account_id`,`slug`),
  KEY `instructors_account_id_is_active_index` (`account_id`,`is_active`),
  KEY `trainers_user_id_foreign` (`user_id`),
  KEY `trainers_account_id_user_id_index` (`account_id`,`user_id`),
  KEY `trainers_trainer_type_id_foreign` (`trainer_type_id`),
  KEY `trainers_account_id_trainer_type_id_index` (`account_id`,`trainer_type_id`),
  CONSTRAINT `trainers_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `trainers_trainer_type_id_foreign` FOREIGN KEY (`trainer_type_id`) REFERENCES `trainer_types` (`id`) ON DELETE SET NULL,
  CONSTRAINT `trainers_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL,
  `email` varchar(255) NOT NULL,
  `phone` varchar(255) DEFAULT NULL,
  `avatar_path` varchar(255) DEFAULT NULL,
  `email_verified_at` timestamp NULL DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `system_role` varchar(255) DEFAULT NULL,
  `remember_token` varchar(100) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_email_unique` (`email`),
  KEY `users_system_role_index` (`system_role`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*M!100616 SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY */;

/*M!999999\- enable the sandbox mode */ 
SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (4,'2026_06_16_080915_create_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (5,'2026_06_16_080915_create_locations_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (6,'2026_06_16_080916_create_account_memberships_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (7,'2026_06_16_080916_create_class_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (8,'2026_06_16_080916_create_customers_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (9,'2026_06_16_080916_create_instructors_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (10,'2026_06_16_080916_create_scheduled_classes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (11,'2026_06_16_080951_create_customer_account_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (12,'2026_06_16_094958_create_activity_directions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (13,'2026_06_16_094958_create_rooms_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (14,'2026_06_16_094958_create_subscription_plans_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (15,'2026_06_16_094959_create_account_subscriptions_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (16,'2026_06_16_094959_create_schedule_series_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (17,'2026_06_16_095026_add_schedule_defaults_to_class_types_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (18,'2026_06_16_095026_add_status_to_accounts_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (19,'2026_06_16_095026_add_system_role_to_users_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (20,'2026_06_16_095027_add_room_series_and_cutoff_to_scheduled_classes_table',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (21,'2026_06_16_171501_add_account_id_to_customers_and_create_class_bookings',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (22,'2026_06_16_171501_rename_instructors_to_trainers_and_add_staff_permissions',1);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (23,'2026_06_17_110339_create_class_pass_plans_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (24,'2026_06_17_110340_create_class_pass_plan_activity_direction_table',2);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (25,'2026_06_17_162545_create_integration_settings_table',3);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (26,'2026_06_18_095511_create_system_settings_table',4);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (27,'2026_06_19_110219_create_trainer_types_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (28,'2026_06_19_110220_add_trainer_type_id_to_trainers_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (29,'2026_06_19_110220_create_class_pass_plan_trainer_type_table',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (30,'2026_06_19_110244_seed_default_trainer_types',5);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (31,'2026_06_19_122935_add_profile_fields_to_users_table',6);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (32,'2026_06_19_155528_add_any_time_addon_to_class_pass_plans_table',7);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (33,'2026_06_20_211603_add_class_type_room_and_trial_to_class_pass_plans',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (34,'2026_06_20_211603_create_class_pass_plan_class_type_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (35,'2026_06_20_211603_create_class_pass_plan_room_table',8);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (36,'2026_06_20_211603_create_customer_class_passes_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (37,'2026_06_20_211604_create_customer_class_pass_reservations_table',9);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (38,'2026_06_21_161000_add_country_code_to_accounts_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (39,'2026_06_21_161000_create_customer_auth_settings_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (40,'2026_06_21_161000_create_customer_otp_challenges_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (41,'2026_06_21_161000_create_customer_remember_tokens_table',10);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (42,'2026_06_23_163644_add_enabled_schedule_kinds_to_accounts_table',11);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (43,'2026_06_23_170541_add_schedule_kind_colors_to_accounts_table',12);
INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES (44,'2026_06_23_195224_add_schedule_kind_to_class_pass_plans_table',13);
COMMIT;
SET AUTOCOMMIT=@OLD_AUTOCOMMIT;
