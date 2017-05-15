CREATE TABLE `ki_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified` tinyint(4) NOT NULL DEFAULT '0',
  `password_hash` char(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `lockout_until` datetime DEFAULT NULL,
  `last_active` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_UNIQUE` (`username`),
  UNIQUE KEY `email_UNIQUE` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_sessions` (
  `id_hash` char(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user` int(11) NOT NULL,
  `fingerprint` char(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `established` datetime NOT NULL,
  `last_active` datetime DEFAULT NULL,
  `remember` tinyint(4) NOT NULL,
  `last_id_reissue` datetime NOT NULL,
  PRIMARY KEY (`id_hash`),
  KEY `fk_sessions_user_users_id_idx` (`user`),
  CONSTRAINT `fk_sessions_user_users_id` FOREIGN KEY (`user`) REFERENCES `ki_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_nonces` (
  `nonce_hash` char(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user` int(11) NOT NULL,
  `session` char(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created` datetime NOT NULL,
  `purpose` enum('email_verify','csrf') COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`nonce_hash`),
  KEY `nonces_user_users_id_idx` (`user`),
  CONSTRAINT `nonces_user_users_id` FOREIGN KEY (`user`) REFERENCES `ki_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;