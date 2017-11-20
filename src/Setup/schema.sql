ALTER DATABASE COLLATE = utf8mb4_unicode_ci;

CREATE TABLE `ki_IPs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ip` varbinary(16) NOT NULL,
  `block_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `ips_ip_idx` (`ip`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_failedLogins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `inputUsername` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` int(11) NOT NULL,
  `when` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `failedlogins_ip_ips_id_idx` (`ip`),
  CONSTRAINT `failedlogins_ip_ips_id` FOREIGN KEY (`ip`) REFERENCES `ki_IPs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_failedNonces` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `input` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` int(11) NOT NULL,
  `when` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `failednonces_ip_ips_id_idx` (`ip`),
  CONSTRAINT `failednonces_ip_ips_id` FOREIGN KEY (`ip`) REFERENCES `ki_IPs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_failedSessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `input` char(32) COLLATE utf8mb4_unicode_ci NOT NULL,
  `ip` int(11) NOT NULL,
  `when` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `failedsessions_ip_ips_id_idx` (`ip`),
  CONSTRAINT `failedsessions_ip_ips_id` FOREIGN KEY (`ip`) REFERENCES `ki_IPs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_groups` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(63) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `username` varchar(45) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(191) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email_verified` tinyint(1) NOT NULL DEFAULT '0',
  `password_hash` char(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `last_active` datetime DEFAULT NULL,
  `lockout_until` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `username_UNIQUE` (`username`),
  UNIQUE KEY `email_UNIQUE` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=35 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_groupsOfUser` (
  `user` int(11) NOT NULL,
  `group` int(11) NOT NULL,
  UNIQUE KEY `groupsOfUser_uc` (`user`,`group`),
  KEY `gou_group_groups_id_idx` (`group`),
  CONSTRAINT `gou_group_groups_id` FOREIGN KEY (`group`) REFERENCES `ki_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `gou_user_users_id` FOREIGN KEY (`user`) REFERENCES `ki_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_permissions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(127) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_permissionsOfGroup` (
  `group` int(11) NOT NULL,
  `permission` int(11) NOT NULL,
  UNIQUE KEY `permissionsOfGroup_uc` (`group`,`permission`),
  KEY `pog_perm_perms_id_idx` (`permission`),
  CONSTRAINT `pog_group_groups_id` FOREIGN KEY (`group`) REFERENCES `ki_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `pog_perm_perms_id` FOREIGN KEY (`permission`) REFERENCES `ki_permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_sessions` (
  `id_hash` char(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user` int(11) DEFAULT NULL,
  `ip` int(11) NOT NULL,
  `fingerprint` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `established` datetime NOT NULL,
  `last_active` datetime DEFAULT NULL,
  `remember` tinyint(4) NOT NULL,
  `last_id_reissue` datetime NOT NULL,
  PRIMARY KEY (`id_hash`),
  KEY `fk_sessions_user_users_id_idx` (`user`),
  KEY `fk_sessions_ip_ips_id_idx` (`ip`),
  CONSTRAINT `fk_sessions_ip_ips_id` FOREIGN KEY (`ip`) REFERENCES `ki_IPs` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sessions_user_users_id` FOREIGN KEY (`user`) REFERENCES `ki_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_nonces` (
  `nonce_hash` char(128) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user` int(11) DEFAULT NULL,
  `session` char(128) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requireUser` tinyint(1) NOT NULL,
  `requireSession` tinyint(1) NOT NULL,
  `purpose` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created` datetime NOT NULL,
  PRIMARY KEY (`nonce_hash`),
  KEY `nonces_user_users_id_idx` (`user`),
  KEY `nonces_ss_sss_idh_idx` (`session`),
  CONSTRAINT `nonces_ss_sss_idh` FOREIGN KEY (`session`) REFERENCES `ki_sessions` (`id_hash`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `nonces_user_users_id` FOREIGN KEY (`user`) REFERENCES `ki_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
