-- =====================================================================
-- Cierre de Caja: base de datos MySQL 8 (utf8mb4)
--
-- Generado desde las migraciones de Laravel (carpeta api/database/migrations),
-- corriéndolas en MySQL 8.4.3. Si cambian las migraciones, hay que volver a generarlo.
--
-- Uso: importarlo en MySQL (HeidiSQL de Laragon, phpMyAdmin o la consola):
--   mysql -u root < cierre_caja.sql
-- Crea la base cierre_caja con todas sus tablas y deja registradas las
-- migraciones, para que "php artisan migrate" no intente crearlas otra vez.
-- Después se crean el admin y el negocio de prueba con: php artisan db:seed
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `cierre_caja` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `cierre_caja`;

SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- TABLAS DEL SISTEMA
-- ---------------------------------------------------------------------

-- negocios: Negocios suscritos. Toda la información de cada negocio cuelga de aquí (RNF-08).
CREATE TABLE `negocios` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `estado` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'activo',
  `suscripcion_inicio_el` date DEFAULT NULL,
  `suscripcion_vence_el` date DEFAULT NULL,
  `bloqueado_en` timestamp NULL DEFAULT NULL,
  `datos_borrados_en` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users: Admin (sin negocio) y dueños (un negocio cada uno, RN-09). Contraseñas cifradas (RNF-09).
CREATE TABLE `users` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `negocio_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rol` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `usuario` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `password` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `activo` tinyint(1) NOT NULL DEFAULT '1',
  `desactivado_en` timestamp NULL DEFAULT NULL,
  `debe_cambiar_contrasena` tinyint(1) NOT NULL DEFAULT '0',
  `sesiones_validas_desde` timestamp NULL DEFAULT NULL,
  `terminos_aceptados_en` timestamp NULL DEFAULT NULL,
  `remember_token` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `users_usuario_unique` (`usuario`),
  UNIQUE KEY `users_negocio_id_unique` (`negocio_id`),
  CONSTRAINT `users_negocio_id_foreign` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pagos_suscripcion: Historial de pagos mensuales de cada negocio (RF-09). vence_el sale de RN-12.
CREATE TABLE `pagos_suscripcion` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `negocio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha` date NOT NULL,
  `valor` bigint unsigned NOT NULL,
  `vencia_el` date DEFAULT NULL,
  `vence_el` date NOT NULL,
  `nota` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `registrado_por` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pagos_suscripcion_registrado_por_foreign` (`registrado_por`),
  KEY `pagos_suscripcion_negocio_id_fecha_index` (`negocio_id`,`fecha`),
  CONSTRAINT `pagos_suscripcion_negocio_id_foreign` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`),
  CONSTRAINT `pagos_suscripcion_registrado_por_foreign` FOREIGN KEY (`registrado_por`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- sessions: Sesiones web de Laravel (la app usa tokens de personal_access_tokens).
CREATE TABLE `sessions` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint unsigned DEFAULT NULL,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `user_agent` text COLLATE utf8mb4_unicode_ci,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `last_activity` int NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- personal_access_tokens: Tokens de inicio de sesión de cada dispositivo (Sanctum, RF-01, RF-48).
CREATE TABLE `personal_access_tokens` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tokenable_type` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `tokenable_id` bigint unsigned NOT NULL,
  `name` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `token` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `abilities` text COLLATE utf8mb4_unicode_ci,
  `last_used_at` timestamp NULL DEFAULT NULL,
  `expires_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `personal_access_tokens_token_unique` (`token`),
  KEY `personal_access_tokens_tokenable_type_tokenable_id_index` (`tokenable_type`,`tokenable_id`),
  KEY `personal_access_tokens_expires_at_index` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLAS DEL NEGOCIO (se sincronizan con el celular; el id lo genera el dispositivo)
-- ---------------------------------------------------------------------

-- cierres: Un cierre por día y negocio (RN-01). Valores en pesos enteros (RN-06).
CREATE TABLE `cierres` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `negocio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha` date NOT NULL,
  `tipo` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `no_abrio` tinyint(1) NOT NULL DEFAULT '0',
  `base` bigint unsigned DEFAULT NULL,
  `efectivo` bigint unsigned DEFAULT NULL,
  `salida_caja` bigint unsigned NOT NULL DEFAULT '0',
  `total_historico` bigint unsigned DEFAULT NULL,
  `nequi_historico` bigint unsigned DEFAULT NULL,
  `modificado_en` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cierres_negocio_id_fecha_unique` (`negocio_id`,`fecha`),
  CONSTRAINT `cierres_negocio_id_foreign` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- pagos: Pagos digitales del día: Nequi u otro pago (RF-51, RF-52). Se anulan, no se borran (RN-36).
CREATE TABLE `pagos` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `negocio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha` date NOT NULL,
  `hora` time DEFAULT NULL,
  `medio` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `origen` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `estado` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmado',
  `valor` bigint unsigned DEFAULT NULL,
  `nombre_pagador` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `referencia` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_ruta` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `foto_borrar_el` date DEFAULT NULL,
  `anulado_en` timestamp NULL DEFAULT NULL,
  `motivo_anulacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `pagos_negocio_id_fecha_index` (`negocio_id`,`fecha`),
  KEY `pagos_negocio_id_referencia_index` (`negocio_id`,`referencia`),
  CONSTRAINT `pagos_negocio_id_foreign` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- deudores: Clientes con fiados. Teléfono único por negocio (RN-32); nombre vacío si se anonimizó (RN-42).
CREATE TABLE `deudores` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `negocio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `nombre` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `telefono` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `autorizacion_en` timestamp NULL DEFAULT NULL,
  `anonimizado_en` timestamp NULL DEFAULT NULL,
  `unido_a` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `deudores_negocio_id_telefono_unique` (`negocio_id`,`telefono`),
  CONSTRAINT `deudores_negocio_id_foreign` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- fiados: Cada fiado de un deudor, con su fecha de pago (RN-22, RN-24).
CREATE TABLE `fiados` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `negocio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deudor_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `fecha` date NOT NULL,
  `descripcion` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `valor` bigint unsigned NOT NULL,
  `fecha_pago` date NOT NULL,
  `anulado_en` timestamp NULL DEFAULT NULL,
  `motivo_anulacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fiados_negocio_id_foreign` (`negocio_id`),
  KEY `fiados_deudor_id_foreign` (`deudor_id`),
  CONSTRAINT `fiados_deudor_id_foreign` FOREIGN KEY (`deudor_id`) REFERENCES `deudores` (`id`),
  CONSTRAINT `fiados_negocio_id_foreign` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- abonos: Abonos de un deudor; pago_id enlaza el pago Nequi cuando el abono llegó por Nequi (RN-39).
CREATE TABLE `abonos` (
  `id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `negocio_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `deudor_id` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pago_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fecha` date NOT NULL,
  `valor` bigint unsigned NOT NULL,
  `nota` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `anulado_en` timestamp NULL DEFAULT NULL,
  `motivo_anulacion` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `abonos_negocio_id_foreign` (`negocio_id`),
  KEY `abonos_deudor_id_foreign` (`deudor_id`),
  KEY `abonos_pago_id_foreign` (`pago_id`),
  CONSTRAINT `abonos_deudor_id_foreign` FOREIGN KEY (`deudor_id`) REFERENCES `deudores` (`id`),
  CONSTRAINT `abonos_negocio_id_foreign` FOREIGN KEY (`negocio_id`) REFERENCES `negocios` (`id`),
  CONSTRAINT `abonos_pago_id_foreign` FOREIGN KEY (`pago_id`) REFERENCES `pagos` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- TABLAS INTERNAS DE LARAVEL
-- ---------------------------------------------------------------------

-- cache: Caché de Laravel.
CREATE TABLE `cache` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `value` mediumtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- cache_locks: Bloqueos de la caché de Laravel.
CREATE TABLE `cache_locks` (
  `key` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expiration` bigint NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- jobs: Cola de tareas en segundo plano (por ejemplo, leer fotos con el OCR).
CREATE TABLE `jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempts` smallint unsigned NOT NULL,
  `reserved_at` int unsigned DEFAULT NULL,
  `available_at` int unsigned NOT NULL,
  `created_at` int unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- job_batches: Lotes de tareas de la cola.
CREATE TABLE `job_batches` (
  `id` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_jobs` int NOT NULL,
  `pending_jobs` int NOT NULL,
  `failed_jobs` int NOT NULL,
  `failed_job_ids` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `options` mediumtext COLLATE utf8mb4_unicode_ci,
  `cancelled_at` int DEFAULT NULL,
  `created_at` int NOT NULL,
  `finished_at` int DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- failed_jobs: Tareas de la cola que fallaron.
CREATE TABLE `failed_jobs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `connection` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `queue` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `payload` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `exception` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrations: Control de migraciones de Laravel.
CREATE TABLE `migrations` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch` int NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migraciones ya aplicadas (para que Laravel no vuelva a crear las tablas)
INSERT INTO `migrations` VALUES (1,'0001_01_01_000000_create_users_table',1);
INSERT INTO `migrations` VALUES (2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO `migrations` VALUES (3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO `migrations` VALUES (4,'2026_11_19_052311_create_personal_access_tokens_table',1);
INSERT INTO `migrations` VALUES (5,'2026_11_19_100000_create_tablas_del_negocio',1);
INSERT INTO `migrations` VALUES (6,'2026_11_20_100000_add_unido_a_to_deudores',2);
INSERT INTO `migrations` VALUES (7,'2026_11_20_110000_create_suscripciones',3);

SET FOREIGN_KEY_CHECKS = 1;
