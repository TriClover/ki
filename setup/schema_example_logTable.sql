CREATE TABLE `log` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `when` DATETIME NOT NULL,
  `level` VARCHAR(45) NOT NULL,
  `url` TEXT NOT NULL,
  `user` VARCHAR(45) NULL,
  `message` TEXT NOT NULL,
  PRIMARY KEY (`id`));