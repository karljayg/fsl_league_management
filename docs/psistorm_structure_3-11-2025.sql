-- MySQL dump 10.13  Distrib 8.0.41, for Linux (x86_64)
--
-- Host: localhost    Database: psistorm
-- ------------------------------------------------------
-- Server version	8.0.41

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `FAQ`
--

DROP TABLE IF EXISTS `FAQ`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `FAQ` (
  `FAQ_ID` int NOT NULL AUTO_INCREMENT,
  `Question` varchar(255) NOT NULL,
  `Answer` text NOT NULL,
  `Order_Number` int NOT NULL DEFAULT '0',
  `Created_At` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `Updated_At` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`FAQ_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=16 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `FSL_STATISTICS`
--

DROP TABLE IF EXISTS `FSL_STATISTICS`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `FSL_STATISTICS` (
  `Player_Record_ID` int NOT NULL AUTO_INCREMENT,
  `Player_ID` int NOT NULL,
  `Alias_ID` int NOT NULL,
  `Division` varchar(10) NOT NULL,
  `Race` char(1) NOT NULL,
  `MapsW` int DEFAULT '0',
  `MapsL` int DEFAULT '0',
  `SetsW` int DEFAULT '0',
  `SetsL` int DEFAULT '0',
  PRIMARY KEY (`Player_Record_ID`),
  UNIQUE KEY `Player_ID` (`Player_ID`,`Alias_ID`,`Division`,`Race`),
  KEY `Alias_ID` (`Alias_ID`),
  CONSTRAINT `FSL_STATISTICS_ibfk_1` FOREIGN KEY (`Player_ID`) REFERENCES `Players` (`Player_ID`) ON DELETE CASCADE,
  CONSTRAINT `FSL_STATISTICS_ibfk_2` FOREIGN KEY (`Alias_ID`) REFERENCES `Player_Aliases` (`Alias_ID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=95 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `Player_Aliases`
--

DROP TABLE IF EXISTS `Player_Aliases`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Player_Aliases` (
  `Alias_ID` int NOT NULL AUTO_INCREMENT,
  `Player_ID` int NOT NULL,
  `Alias_Name` varchar(255) NOT NULL,
  PRIMARY KEY (`Alias_ID`),
  UNIQUE KEY `Alias_Name` (`Alias_Name`),
  KEY `Player_ID` (`Player_ID`),
  CONSTRAINT `Player_Aliases_ibfk_1` FOREIGN KEY (`Player_ID`) REFERENCES `Players` (`Player_ID`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=77 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `Players`
--

DROP TABLE IF EXISTS `Players`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Players` (
  `Player_ID` int NOT NULL AUTO_INCREMENT,
  `Real_Name` varchar(255) NOT NULL,
  `Team_ID` int DEFAULT NULL,
  `Championship_Record` json DEFAULT NULL,
  `TeamLeague_Championship_Record` json DEFAULT NULL,
  `Teams_History` json DEFAULT NULL,
  `User_ID` varchar(36) DEFAULT NULL,
  `Intro_Url` varchar(255) DEFAULT NULL,
  `Status` enum('active','inactive','banned','other') NOT NULL DEFAULT 'active',
  PRIMARY KEY (`Player_ID`),
  UNIQUE KEY `Real_Name` (`Real_Name`),
  UNIQUE KEY `User_ID` (`User_ID`),
  KEY `Team_ID` (`Team_ID`),
  CONSTRAINT `fk_players_users` FOREIGN KEY (`User_ID`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `Players_ibfk_1` FOREIGN KEY (`Team_ID`) REFERENCES `Teams` (`Team_ID`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=91 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `Teams`
--

DROP TABLE IF EXISTS `Teams`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `Teams` (
  `Team_ID` int NOT NULL AUTO_INCREMENT,
  `Team_Name` varchar(255) NOT NULL,
  `Captain_ID` int DEFAULT NULL,
  `Co_Captain_ID` int DEFAULT NULL,
  `TeamLeague_Championship_Record` json DEFAULT NULL,
  PRIMARY KEY (`Team_ID`),
  UNIQUE KEY `Team_Name` (`Team_Name`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `bids`
--

DROP TABLE IF EXISTS `bids`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `bids` (
  `id` varchar(36) NOT NULL,
  `match_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `amount` decimal(10,2) NOT NULL,
  `status` enum('pending','accepted','rejected') NOT NULL,
  `bid_time` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `match_id` (`match_id`),
  KEY `user_id` (`user_id`),
  CONSTRAINT `bids_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`),
  CONSTRAINT `bids_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `fsl_matches`
--

DROP TABLE IF EXISTS `fsl_matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `fsl_matches` (
  `fsl_match_id` int NOT NULL AUTO_INCREMENT,
  `season` int NOT NULL,
  `season_extra_info` varchar(100) DEFAULT NULL,
  `notes` varchar(255) DEFAULT NULL,
  `t_code` varchar(50) DEFAULT NULL,
  `winner_player_id` int NOT NULL,
  `winner_race` varchar(50) NOT NULL,
  `best_of` int NOT NULL,
  `map_win` int NOT NULL,
  `map_loss` int NOT NULL,
  `loser_player_id` int NOT NULL,
  `loser_race` varchar(50) NOT NULL,
  `source` varchar(255) DEFAULT NULL COMMENT 'Link or info to verify the match',
  `vod` varchar(255) DEFAULT NULL COMMENT 'Video link (e.g., YouTube) to watch the match',
  PRIMARY KEY (`fsl_match_id`),
  KEY `winner_player_id` (`winner_player_id`),
  KEY `loser_player_id` (`loser_player_id`),
  CONSTRAINT `fsl_matches_ibfk_1` FOREIGN KEY (`winner_player_id`) REFERENCES `Players` (`Player_ID`),
  CONSTRAINT `fsl_matches_ibfk_2` FOREIGN KEY (`loser_player_id`) REFERENCES `Players` (`Player_ID`)
) ENGINE=InnoDB AUTO_INCREMENT=519 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `match_players`
--

DROP TABLE IF EXISTS `match_players`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `match_players` (
  `id` varchar(36) NOT NULL,
  `match_id` varchar(36) NOT NULL,
  `user_id` varchar(36) NOT NULL,
  `team_id` int NOT NULL,
  `is_pro` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_match_players_match_id` (`match_id`),
  KEY `idx_match_players_user_id` (`user_id`),
  KEY `idx_match_players_team_id` (`team_id`),
  CONSTRAINT `match_players_ibfk_1` FOREIGN KEY (`match_id`) REFERENCES `matches` (`id`) ON DELETE CASCADE,
  CONSTRAINT `match_players_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `matches`
--

DROP TABLE IF EXISTS `matches`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `matches` (
  `id` varchar(36) NOT NULL,
  `pro_id` varchar(36) NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `status` enum('scheduled','in_progress','completed','cancelled') NOT NULL DEFAULT 'scheduled',
  `date` date NOT NULL,
  `time` time NOT NULL,
  `match_type` enum('1v1','1v2','2v2','FFA','other') NOT NULL DEFAULT '1v1',
  `min_bid` decimal(10,2) NOT NULL DEFAULT '0.00',
  `winning_team` int DEFAULT NULL,
  `match_completed` tinyint(1) DEFAULT '0',
  `result_description` text,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_matches_status` (`status`),
  KEY `idx_matches_pro_id` (`pro_id`),
  CONSTRAINT `matches_ibfk_1` FOREIGN KEY (`pro_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` varchar(36) NOT NULL,
  `email` varchar(255) NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL,
  `role` enum('user','pro') NOT NULL,
  `mmr` int DEFAULT NULL,
  `race_preference` enum('Protoss','Terran','Zerg','Random') DEFAULT NULL,
  `avatar_url` varchar(255) DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `auth_token` varchar(64) DEFAULT NULL,
  `ws_role` int DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `auth_token` (`auth_token`),
  KEY `idx_ws_role` (`ws_role`),
  CONSTRAINT `fk_users_ws_roles` FOREIGN KEY (`ws_role`) REFERENCES `ws_roles` (`role_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ws_permissions`
--

DROP TABLE IF EXISTS `ws_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_permissions` (
  `permission_id` int NOT NULL AUTO_INCREMENT,
  `permission_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`permission_id`),
  UNIQUE KEY `permission_name` (`permission_name`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ws_role_permissions`
--

DROP TABLE IF EXISTS `ws_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_role_permissions` (
  `role_id` int NOT NULL,
  `permission_id` int NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  KEY `fk_role_perm_perm` (`permission_id`),
  CONSTRAINT `fk_role_perm_perm` FOREIGN KEY (`permission_id`) REFERENCES `ws_permissions` (`permission_id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_perm_role` FOREIGN KEY (`role_id`) REFERENCES `ws_roles` (`role_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Table structure for table `ws_roles`
--

DROP TABLE IF EXISTS `ws_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `ws_roles` (
  `role_id` int NOT NULL AUTO_INCREMENT,
  `role_name` varchar(50) NOT NULL,
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`role_id`),
  UNIQUE KEY `role_name` (`role_name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2025-03-11 18:55:43
