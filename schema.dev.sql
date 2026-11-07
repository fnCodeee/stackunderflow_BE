-- MySQL dump 10.13  Distrib 8.4.11, for Linux (x86_64)
--
-- Host: localhost    Database: db_stack_underflow
-- ------------------------------------------------------
-- Server version	8.4.11-0ubuntu0.26.04.1

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
-- Table structure for table `circle`
--

DROP TABLE IF EXISTS `circle`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `circle` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `collaboration_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `photo_profile_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_circle_collaboration_id` (`collaboration_id`),
  KEY `idx_circle_collaboration_id` (`collaboration_id`),
  CONSTRAINT `fk_circle_collaboration` FOREIGN KEY (`collaboration_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `circle`
--

LOCK TABLES `circle` WRITE;
/*!40000 ALTER TABLE `circle` DISABLE KEYS */;
INSERT INTO `circle` VALUES ('3cf9cc9f-8874-4a77-b364-de0182fe1a11','6b175e4d-d8ee-435e-a216-cc940189c363','Collab To Be Deleted 851bd4',NULL,'2026-11-06 18:05:31'),('6775d861-49c0-4a47-84e8-9c68de7fef4a','6d2f77f3-1f6f-45bd-88ba-45b4c5788dc6','Updated Audit Title',NULL,'2026-10-30 23:42:11'),('68a9fd2c-8c13-45c2-9462-a47ee047c197','61dd7b22-7280-43c1-a43d-d62107a898b0','Collab To Be Deleted ff99f8',NULL,'2026-11-06 18:08:16'),('ecc4ccf4-9f0e-4aa4-b586-8be59c600e71','293d1ed7-c3ce-4367-af14-afda698603fd','FADHILAH',NULL,'2026-11-06 17:45:16');
/*!40000 ALTER TABLE `circle` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `collab_media`
--

DROP TABLE IF EXISTS `collab_media`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_media` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_public_id` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_collab_media_collab_id` (`collab_id`),
  CONSTRAINT `fk_collab_media_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `collab_media`
--

LOCK TABLES `collab_media` WRITE;
/*!40000 ALTER TABLE `collab_media` DISABLE KEYS */;
INSERT INTO `collab_media` VALUES ('40df5170-d481-11f1-8689-200b7478e742','6d2f77f3-1f6f-45bd-88ba-45b4c5788dc6','https://y.example/1.jpg','y1','2026-10-30 16:45:02','2026-10-30 16:45:02'),('5d9d5552-72f9-4875-9c77-114262780b35','61dd7b22-7280-43c1-a43d-d62107a898b0','https://res.cloudinary.com/test/del_image.png','del_media_pub_id','2026-11-06 11:08:16','2026-11-06 11:08:16'),('b1c3a332-7344-4468-a79e-e69c42cdc99d','293d1ed7-c3ce-4367-af14-afda698603fd','https://example.com/image-2.jpg','images_2.jpg','2026-11-06 10:45:16','2026-11-06 10:45:16'),('b21f0447-526e-438a-a52f-3ad17736bdbe','6b175e4d-d8ee-435e-a216-cc940189c363','https://res.cloudinary.com/test/del_image.png','del_media_pub_id','2026-11-06 11:05:31','2026-11-06 11:05:31'),('d7e374aa-b25f-4642-98ac-c33e0bd35332','293d1ed7-c3ce-4367-af14-afda698603fd','https://example.com/image-1.jpg','images_1.jpg','2026-11-06 10:45:16','2026-11-06 10:45:16');
/*!40000 ALTER TABLE `collab_media` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `collab_skills`
--

DROP TABLE IF EXISTS `collab_skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collab_skills` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `skill_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collab_skills` (`collab_id`,`skill_id`),
  KEY `idx_collab_skills_collab_id` (`collab_id`),
  KEY `idx_collab_skills_skill_id` (`skill_id`),
  CONSTRAINT `fk_collab_skills_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_skills_skill` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `collab_skills`
--

LOCK TABLES `collab_skills` WRITE;
/*!40000 ALTER TABLE `collab_skills` DISABLE KEYS */;
INSERT INTO `collab_skills` VALUES ('33800df4-1780-4166-92f7-46d561850ef6','6b175e4d-d8ee-435e-a216-cc940189c363','6bfad856-bce9-11f1-b805-5254001db89e','2026-11-06 11:05:31'),('38cd06a9-c6a2-4999-becb-ef49dcfec12d','293d1ed7-c3ce-4367-af14-afda698603fd','6bfb4154-bce9-11f1-b805-5254001db89e','2026-11-06 10:45:16'),('44eb2db4-3e4b-4f93-9f3d-f69758de3803','6d2f77f3-1f6f-45bd-88ba-45b4c5788dc6','6bfad856-bce9-11f1-b805-5254001db89e','2026-10-30 16:42:11'),('558ccd15-b091-4f42-b6da-88705de74eba','61dd7b22-7280-43c1-a43d-d62107a898b0','6bfad856-bce9-11f1-b805-5254001db89e','2026-11-06 11:08:16');
/*!40000 ALTER TABLE `collab_skills` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `collaboration_applications`
--

DROP TABLE IF EXISTS `collaboration_applications`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collaboration_applications` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collab_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `status` enum('pending','accepted','rejected') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_collab_application` (`user_id`,`collab_id`),
  KEY `idx_collab_app_user_id` (`user_id`),
  KEY `idx_collab_app_collab_id` (`collab_id`),
  KEY `idx_collab_app_status` (`status`),
  CONSTRAINT `fk_collab_app_collab` FOREIGN KEY (`collab_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_collab_app_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `collaboration_applications`
--

LOCK TABLES `collaboration_applications` WRITE;
/*!40000 ALTER TABLE `collaboration_applications` DISABLE KEYS */;
INSERT INTO `collaboration_applications` VALUES ('cbc6337c-221c-4dfc-a5f6-ad5de1e4f28f','39842baa-ed59-4af5-a09b-f33de4cd7553','61dd7b22-7280-43c1-a43d-d62107a898b0','Mau join collab ini','pending','2026-11-06 11:08:16','2026-11-06 11:08:16'),('d0b4fe36-6102-4421-995f-c8667ba07f71','39842baa-ed59-4af5-a09b-f33de4cd7553','6b175e4d-d8ee-435e-a216-cc940189c363','Mau join collab ini','pending','2026-11-06 11:05:31','2026-11-06 11:05:31');
/*!40000 ALTER TABLE `collaboration_applications` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `collaborations`
--

DROP TABLE IF EXISTS `collaborations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `collaborations` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `required_member` int NOT NULL DEFAULT '1',
  `communication_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('open','closed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_collaborations_user_id` (`user_id`),
  KEY `idx_collaborations_status` (`status`),
  KEY `idx_collaborations_created_at` (`created_at`),
  CONSTRAINT `fk_collaborations_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `collaborations`
--

LOCK TABLES `collaborations` WRITE;
/*!40000 ALTER TABLE `collaborations` DISABLE KEYS */;
INSERT INTO `collaborations` VALUES ('293d1ed7-c3ce-4367-af14-afda698603fd','8c499722-c5dc-4708-999b-9a30bbd18d3b','FADHILAH','butuh anak yg bisa Laravel.',3,NULL,'open','2026-11-06 10:45:16','2026-11-06 10:45:16'),('61dd7b22-7280-43c1-a43d-d62107a898b0','8c499722-c5dc-4708-999b-9a30bbd18d3b','Collab To Be Deleted ff99f8','Deskripsi uji coba delete collaboration',3,NULL,'open','2026-11-06 11:08:16','2026-11-06 11:08:16'),('6b175e4d-d8ee-435e-a216-cc940189c363','8c499722-c5dc-4708-999b-9a30bbd18d3b','Collab To Be Deleted 851bd4','Deskripsi uji coba delete collaboration',3,NULL,'open','2026-11-06 11:05:31','2026-11-06 11:05:31'),('6d2f77f3-1f6f-45bd-88ba-45b4c5788dc6','fc6d488f-9b98-4237-a089-fcaa7552ffa5','Updated Audit Title','new desc',5,NULL,'closed','2026-10-30 16:42:11','2026-10-30 16:44:14');
/*!40000 ALTER TABLE `collaborations` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `comments`
--

DROP TABLE IF EXISTS `comments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `comments` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `post_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_comments_post_id` (`post_id`),
  KEY `idx_comments_user_id` (`user_id`),
  CONSTRAINT `fk_comments_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_comments_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `comments`
--

LOCK TABLES `comments` WRITE;
/*!40000 ALTER TABLE `comments` DISABLE KEYS */;
/*!40000 ALTER TABLE `comments` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `like_posts`
--

DROP TABLE IF EXISTS `like_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `like_posts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `post_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_post_like` (`user_id`,`post_id`),
  KEY `idx_like_posts_post_id` (`post_id`),
  KEY `idx_like_posts_user_id` (`user_id`),
  CONSTRAINT `fk_likes_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_likes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `like_posts`
--

LOCK TABLES `like_posts` WRITE;
/*!40000 ALTER TABLE `like_posts` DISABLE KEYS */;
/*!40000 ALTER TABLE `like_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `media_posts`
--

DROP TABLE IF EXISTS `media_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `media_posts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `post_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_url` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `media_public_id` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_media_posts_post_id` (`post_id`),
  CONSTRAINT `fk_media_post` FOREIGN KEY (`post_id`) REFERENCES `public_posts` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `media_posts`
--

LOCK TABLES `media_posts` WRITE;
/*!40000 ALTER TABLE `media_posts` DISABLE KEYS */;
INSERT INTO `media_posts` VALUES ('208802bd-6d07-42ef-b35d-a35f37ac7c78','02098313-6032-425d-8b4c-6100d08ad363','https://res.cloudinary.com/df9jelnu7/image/upload/q_auto/f_auto/w_1200/Screenshot_from_2026-06-15_20-25-53_fpypqv?_a=BAMAPqZW0','posts/screen1_public_id','2026-10-29 07:07:11');
/*!40000 ALTER TABLE `media_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `profile_social_medias`
--

DROP TABLE IF EXISTS `profile_social_medias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profile_social_medias` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `platform` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_social_media_user` (`user_id`),
  CONSTRAINT `fk_social_media_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profile_social_medias`
--

LOCK TABLES `profile_social_medias` WRITE;
/*!40000 ALTER TABLE `profile_social_medias` DISABLE KEYS */;
INSERT INTO `profile_social_medias` VALUES ('634e706e-fb87-4f09-9e41-af78136f32e8','6355a749-9d9b-4784-a1f9-3a0a1cd64ab5','github','https://github.com/fncodeee'),('af4186d4-4d6a-4dba-b754-5624bfb85008','6355a749-9d9b-4784-a1f9-3a0a1cd64ab5','instagram','https://instagram.com/anmee1322');
/*!40000 ALTER TABLE `profile_social_medias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `profiles`
--

DROP TABLE IF EXISTS `profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `profiles` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `bio` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_profile_url` varchar(150) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photo_profile_public_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_profiles_user_id` (`user_id`),
  CONSTRAINT `fk_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `profiles`
--

LOCK TABLES `profiles` WRITE;
/*!40000 ALTER TABLE `profiles` DISABLE KEYS */;
INSERT INTO `profiles` VALUES ('1deb93a3-39ee-4435-a44a-5cfe727c0356','f9761be2-439c-4fb1-8e0b-4877818724a8','','','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','','2026-10-07 16:18:48',NULL),('6e627a67-8657-4e2f-9161-8887679d745b','6355a749-9d9b-4784-a1f9-3a0a1cd64ab5','Faiz Atha Aghitsna','Aku suka ngoding','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','profile_zngu0l.png','2026-09-30 22:33:48','2026-09-30 23:00:33'),('7409fd3d-346b-4c60-9e5e-e3b5e8a1d20b','73fb97c9-a191-444b-83fb-93af2fb5faa3','','','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','','2026-10-07 15:49:30',NULL),('bb2af89d-7b4f-4a2b-b145-ac2b586ba1b6','fc6d488f-9b98-4237-a089-fcaa7552ffa5','','Aku suka ngoding , Laravel di dada','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','profile_zngu0l.png','2026-09-28 16:41:04',NULL),('df981c2b-ba44-495c-9716-befbccc62529','5bdcee86-b297-4eab-995a-ed9bf479c5dd','','','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','','2026-10-06 16:40:32',NULL),('ee07c8b6-07ac-4ba6-8e24-8c13ef29bb33','34862b7d-b800-48c4-9259-9c9793e23510','','','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','','2026-11-03 21:14:58',NULL),('f07b9f37-2f34-4376-9bfc-46942007a647','39842baa-ed59-4af5-a09b-f33de4cd7553','','','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','','2026-10-29 14:26:55',NULL),('ffa417a2-bf68-407c-b0be-d4010734f939','8c499722-c5dc-4708-999b-9a30bbd18d3b','','','https://res.cloudinary.com/df9jelnu7/image/upload/v1782362663/profile_zngu0l.png','','2026-10-13 18:43:04',NULL);
/*!40000 ALTER TABLE `profiles` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `public_posts`
--

DROP TABLE IF EXISTS `public_posts`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_posts` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `portfolio_type` enum('link','images') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'link',
  `external_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `view_count` int NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_public_posts_user_id` (`user_id`),
  CONSTRAINT `fk_posts_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `public_posts`
--

LOCK TABLES `public_posts` WRITE;
/*!40000 ALTER TABLE `public_posts` DISABLE KEYS */;
INSERT INTO `public_posts` VALUES ('02098313-6032-425d-8b4c-6100d08ad363','8c499722-c5dc-4708-999b-9a30bbd18d3b','PROJEK SERUCVEY','orang aaring HITAM','link','https://github.com/fncodeee',0,'2026-10-29 07:07:11','2026-10-29 07:07:11');
/*!40000 ALTER TABLE `public_posts` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `skills`
--

DROP TABLE IF EXISTS `skills`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `skills` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `skill_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope` enum('system','private') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'system',
  `created_by` varchar(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_skills_name` (`skill_name`),
  KEY `idx_skills_scope` (`scope`),
  KEY `idx_skills_created_by` (`created_by`),
  CONSTRAINT `fk_skills_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `skills`
--

LOCK TABLES `skills` WRITE;
/*!40000 ALTER TABLE `skills` DISABLE KEYS */;
INSERT INTO `skills` VALUES ('6bfad856-bce9-11f1-b805-5254001db89e','Frontend Development','system',NULL),('6bfb4154-bce9-11f1-b805-5254001db89e','Backend Development','system',NULL),('6bfb4369-bce9-11f1-b805-5254001db89e','Full Stack Development','system',NULL),('6bfb4460-bce9-11f1-b805-5254001db89e','DevOps & Cloud','system',NULL),('6bfb4542-bce9-11f1-b805-5254001db89e','Infrastructure & System','system',NULL),('6bfb4666-bce9-11f1-b805-5254001db89e','IoT & Embedded Systems','system',NULL),('6bfb4737-bce9-11f1-b805-5254001db89e','Mobile Development','system',NULL);
/*!40000 ALTER TABLE `skills` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_collaboration`
--

DROP TABLE IF EXISTS `user_collaboration`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_collaboration` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `collaboration_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_collaboration` (`user_id`,`collaboration_id`),
  KEY `idx_user_collab_user_id` (`user_id`),
  KEY `idx_user_collab_collab_id` (`collaboration_id`),
  CONSTRAINT `fk_user_collab_collab` FOREIGN KEY (`collaboration_id`) REFERENCES `collaborations` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_collab_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_collaboration`
--

LOCK TABLES `user_collaboration` WRITE;
/*!40000 ALTER TABLE `user_collaboration` DISABLE KEYS */;
INSERT INTO `user_collaboration` VALUES ('8a4d34ac-27f7-4c4a-83e6-ae84ffef2ccf','39842baa-ed59-4af5-a09b-f33de4cd7553','6b175e4d-d8ee-435e-a216-cc940189c363','2026-11-06 11:05:31'),('e8acfd43-ce3b-4ed1-86b1-3c52fe5cbe60','39842baa-ed59-4af5-a09b-f33de4cd7553','61dd7b22-7280-43c1-a43d-d62107a898b0','2026-11-06 11:08:16');
/*!40000 ALTER TABLE `user_collaboration` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `user_skill`
--

DROP TABLE IF EXISTS `user_skill`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_skill` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `skill_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_skill` (`user_id`,`skill_id`),
  KEY `idx_user_skill_skill_id` (`skill_id`),
  KEY `idx_user_skill_user_id` (`user_id`),
  CONSTRAINT `fk_user_skill_skill` FOREIGN KEY (`skill_id`) REFERENCES `skills` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_skill_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `user_skill`
--

LOCK TABLES `user_skill` WRITE;
/*!40000 ALTER TABLE `user_skill` DISABLE KEYS */;
INSERT INTO `user_skill` VALUES ('9e4f03d2-3e8a-4721-b6e5-ac297649dc30','6bfad856-bce9-11f1-b805-5254001db89e','6355a749-9d9b-4784-a1f9-3a0a1cd64ab5'),('0ea0c11e-302e-40dd-8335-e690eff767a9','6bfb4542-bce9-11f1-b805-5254001db89e','6355a749-9d9b-4784-a1f9-3a0a1cd64ab5');
/*!40000 ALTER TABLE `user_skill` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `users`
--

DROP TABLE IF EXISTS `users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!50503 SET character_set_client = utf8mb4 */;
CREATE TABLE `users` (
  `id` varchar(36) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT (uuid()),
  `username` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `password` varchar(150) COLLATE utf8mb4_unicode_ci NOT NULL,
  `email` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `users`
--

LOCK TABLES `users` WRITE;
/*!40000 ALTER TABLE `users` DISABLE KEYS */;
INSERT INTO `users` VALUES ('34862b7d-b800-48c4-9259-9c9793e23510','integration','$2y$12$9KBG/anZ8xwADeKjpdxOZuNesNUOo8vidE.W.C.kgex7v28fc7jFi','integration@test.com',1,'2026-11-03 21:14:58'),('39842baa-ed59-4af5-a09b-f33de4cd7553','fadhilhazza','$2y$12$OGtNUr635Ntv4MjKvmIHION4hxkZpkzYWTpUuQTXhzutS5PQL2U/.','fadhilhazza@gmail.com',1,'2026-10-29 14:26:55'),('5bdcee86-b297-4eab-995a-ed9bf479c5dd','testuser_showcase','$2y$10$zpB9K4V93TiioOgG94Vb7.77u43Ud/rmS0vEurxWlAkxtJgowHjQS','testshowcase@example.com',1,'2026-10-06 16:40:32'),('6355a749-9d9b-4784-a1f9-3a0a1cd64ab5','faiz','$2y$10$/Eozyj97UTecNh47WkxxqubfjBi95ejd1wJxFl1vm3fc3E2BLRfkK','faiz@gmail.com',1,'2026-09-30 22:33:48'),('73fb97c9-a191-444b-83fb-93af2fb5faa3','testuser_detail','$2y$10$9Qd6PpHIsDAVNirytI0o/eeZ9Sm97cM0nUdgkFc6gruzQjWkRvccy','testdetail@example.com',1,'2026-10-07 15:49:30'),('8c499722-c5dc-4708-999b-9a30bbd18d3b','dummy','$2y$10$BqxPlMfacDTsxzZ04E5ZtOJZ4om4jMv93LZ9U/zpEaGbo5Bqn3bfS','dummy@gmail.com',1,'2026-10-13 18:43:04'),('f9761be2-439c-4fb1-8e0b-4877818724a8','testuserbaru','$2y$10$/PswBadoB9Iv3GMetim43OZwWPN1MBcgaUesuO1GvB6ZTOBJ2cFGq','testbaru@example.com',1,'2026-10-07 16:18:48'),('fc6d488f-9b98-4237-a089-fcaa7552ffa5','raka','$2y$10$Z1inECHsGLltpJWFpK3TRep7utUrHKFDi.Bi.IKyI4WHqBPqzzpAa','raka@gmail.com',1,'2026-09-28 16:41:04');
/*!40000 ALTER TABLE `users` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-07 11:52:44
