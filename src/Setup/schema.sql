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
  `remember` tinyint(1) NOT NULL,
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

CREATE TABLE `ki_savableForms` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(127) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `name_UNIQUE` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_savedFormCategories` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(127) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci,
  `permission_view` int(11) NOT NULL COMMENT 'Reference to the permission allowing viewing of the reports in this category.',
  `permission_edit` int(11) NOT NULL COMMENT 'Reference to the permission allowing (viewing,editing) of the reports in this category.',
  `permission_addDel` int(11) NOT NULL COMMENT 'Reference to the permission allowing (viewing,editing,adding,deleting) of the reports in this category.',
  PRIMARY KEY (`id`),
  KEY `fk_formcat_permView_idx` (`permission_view`),
  KEY `fk_formcat_permEdit_idx` (`permission_edit`),
  KEY `fk_formcat_permAddDel_idx` (`permission_addDel`),
  CONSTRAINT `fk_formcat_permAddDel` FOREIGN KEY (`permission_addDel`) REFERENCES `ki_permissions` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_formcat_permEdit` FOREIGN KEY (`permission_edit`) REFERENCES `ki_permissions` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_formcat_permView` FOREIGN KEY (`permission_view`) REFERENCES `ki_permissions` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `ki_savedFormData` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form` int(11) NOT NULL COMMENT 'Form identifier defined in the page''s code',
  `name` varchar(127) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Name given by the user to this particular setup of the form',
  `data` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Serialized form contents',
  `created_by` int(11) DEFAULT NULL,
  `created_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `lastEdited_by` int(11) DEFAULT NULL,
  `lastEdited_on` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `owner` int(11) NOT NULL COMMENT 'User who can edit the report even if they don''t have access to the category it''s in. Also, if the report has no category, it will be filed in this user''s personal category.',
  `category` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `fk_formdata_form_forms_id_idx` (`form`),
  KEY `fk_formdata_owner_users_id_idx` (`owner`),
  KEY `fk_formdata_category_categories_id_idx` (`category`),
  KEY `fk_formdata_lasteditedby_users_id_idx` (`lastEdited_by`),
  KEY `fk_formdata_createdby_uesrs_id_idx` (`created_by`),
  CONSTRAINT `fk_formdata_category_categories_id` FOREIGN KEY (`category`) REFERENCES `ki_savedFormCategories` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_formdata_createdby_uesrs_id` FOREIGN KEY (`created_by`) REFERENCES `ki_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_formdata_form_forms_id` FOREIGN KEY (`form`) REFERENCES `ki_savableForms` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_formdata_lasteditedby_users_id` FOREIGN KEY (`lastEdited_by`) REFERENCES `ki_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_formdata_owner_users_id` FOREIGN KEY (`owner`) REFERENCES `ki_users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='One row for each "report", or "saved form setup".';
