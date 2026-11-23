-- MariaDB dump 10.19  Distrib 10.4.32-MariaDB, for Win64 (AMD64)
--
-- Host: localhost    Database: tarefai_db
-- ------------------------------------------------------
-- Server version	10.4.32-MariaDB

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

--
-- Table structure for table `categorias`
--

DROP TABLE IF EXISTS `categorias`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categorias` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_projeto` int(11) NOT NULL,
  `nome` varchar(100) NOT NULL,
  `cor` char(7) NOT NULL DEFAULT '#64748B',
  PRIMARY KEY (`id`),
  KEY `id_projeto` (`id_projeto`),
  CONSTRAINT `categorias_ibfk_1` FOREIGN KEY (`id_projeto`) REFERENCES `projetos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categorias`
--

LOCK TABLES `categorias` WRITE;
/*!40000 ALTER TABLE `categorias` DISABLE KEYS */;
/*!40000 ALTER TABLE `categorias` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `categorias_aplicadas`
--

DROP TABLE IF EXISTS `categorias_aplicadas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `categorias_aplicadas` (
  `id_tarefa` int(11) NOT NULL,
  `id_categoria` int(11) NOT NULL,
  PRIMARY KEY (`id_tarefa`,`id_categoria`),
  KEY `id_categoria` (`id_categoria`),
  CONSTRAINT `categorias_aplicadas_ibfk_1` FOREIGN KEY (`id_tarefa`) REFERENCES `tarefas` (`id`) ON DELETE CASCADE,
  CONSTRAINT `categorias_aplicadas_ibfk_2` FOREIGN KEY (`id_categoria`) REFERENCES `categorias` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `categorias_aplicadas`
--

LOCK TABLES `categorias_aplicadas` WRITE;
/*!40000 ALTER TABLE `categorias_aplicadas` DISABLE KEYS */;
/*!40000 ALTER TABLE `categorias_aplicadas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `convites_projeto`
--

DROP TABLE IF EXISTS `convites_projeto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `convites_projeto` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_projeto` int(11) NOT NULL,
  `email` varchar(190) NOT NULL,
  `token_hash` char(64) NOT NULL,
  `id_usuario_convidou` int(11) NOT NULL,
  `status` enum('pendente','aceito','revogado','expirado') NOT NULL DEFAULT 'pendente',
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  `expira_em` datetime NOT NULL,
  `aceito_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_convites_token_hash` (`token_hash`),
  KEY `idx_convites_projeto_email_status` (`id_projeto`,`email`,`status`),
  KEY `fk_convites_usuario_convidou` (`id_usuario_convidou`),
  CONSTRAINT `fk_convites_projeto` FOREIGN KEY (`id_projeto`) REFERENCES `projetos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_convites_usuario_convidou` FOREIGN KEY (`id_usuario_convidou`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `convites_projeto`
--

LOCK TABLES `convites_projeto` WRITE;
/*!40000 ALTER TABLE `convites_projeto` DISABLE KEYS */;
INSERT INTO `convites_projeto` VALUES (1,1,'samuel@gmail.com','2983d1e1140311d49b64581d61a850fe782c1687225b078779a7f0c9d997ab6e',1,'aceito','2026-11-23 16:21:00','2026-11-30 13:21:00','2026-11-23 13:27:56');
/*!40000 ALTER TABLE `convites_projeto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `gestores_projeto`
--

DROP TABLE IF EXISTS `gestores_projeto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `gestores_projeto` (
  `id_usuario` int(11) NOT NULL,
  `id_projeto` int(11) NOT NULL,
  PRIMARY KEY (`id_usuario`,`id_projeto`),
  KEY `id_projeto` (`id_projeto`),
  CONSTRAINT `gestores_projeto_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `gestores_projeto_ibfk_2` FOREIGN KEY (`id_projeto`) REFERENCES `projetos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `gestores_projeto`
--

LOCK TABLES `gestores_projeto` WRITE;
/*!40000 ALTER TABLE `gestores_projeto` DISABLE KEYS */;
/*!40000 ALTER TABLE `gestores_projeto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `participantes_projeto`
--

DROP TABLE IF EXISTS `participantes_projeto`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `participantes_projeto` (
  `id_usuario` int(11) NOT NULL,
  `id_projeto` int(11) NOT NULL,
  `criado_em` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id_usuario`,`id_projeto`),
  KEY `fk_participantes_projeto` (`id_projeto`),
  CONSTRAINT `fk_participantes_projeto` FOREIGN KEY (`id_projeto`) REFERENCES `projetos` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_participantes_usuario` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `participantes_projeto`
--

LOCK TABLES `participantes_projeto` WRITE;
/*!40000 ALTER TABLE `participantes_projeto` DISABLE KEYS */;
INSERT INTO `participantes_projeto` VALUES (2,1,'2026-11-23 16:27:56'),(2,2,'2026-11-23 16:36:56'),(2,4,'2026-11-23 17:23:33'),(3,3,'2026-11-23 16:40:44');
/*!40000 ALTER TABLE `participantes_projeto` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `projetos`
--

DROP TABLE IF EXISTS `projetos`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `projetos` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(150) NOT NULL,
  `id_usuario_criador` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `id_usuario_criador` (`id_usuario_criador`),
  CONSTRAINT `projetos_ibfk_1` FOREIGN KEY (`id_usuario_criador`) REFERENCES `usuarios` (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `projetos`
--

LOCK TABLES `projetos` WRITE;
/*!40000 ALTER TABLE `projetos` DISABLE KEYS */;
INSERT INTO `projetos` VALUES (1,'Porra 2',1),(2,'Caralho!',2),(3,'Desenvolvimento de Humanos',3),(4,'67',2);
/*!40000 ALTER TABLE `projetos` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `responsaveis`
--

DROP TABLE IF EXISTS `responsaveis`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `responsaveis` (
  `id_usuario` int(11) NOT NULL,
  `id_tarefa` int(11) NOT NULL,
  PRIMARY KEY (`id_usuario`,`id_tarefa`),
  KEY `id_tarefa` (`id_tarefa`),
  CONSTRAINT `responsaveis_ibfk_1` FOREIGN KEY (`id_usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE,
  CONSTRAINT `responsaveis_ibfk_2` FOREIGN KEY (`id_tarefa`) REFERENCES `tarefas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `responsaveis`
--

LOCK TABLES `responsaveis` WRITE;
/*!40000 ALTER TABLE `responsaveis` DISABLE KEYS */;
INSERT INTO `responsaveis` VALUES (1,4),(2,2),(2,3),(3,1);
/*!40000 ALTER TABLE `responsaveis` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `tarefas`
--

DROP TABLE IF EXISTS `tarefas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `tarefas` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `id_projeto` int(11) NOT NULL,
  `nome` varchar(180) NOT NULL,
  `criado_em` datetime NOT NULL DEFAULT current_timestamp(),
  `completo_em` datetime DEFAULT NULL,
  `fechado_em` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `id_projeto` (`id_projeto`),
  CONSTRAINT `tarefas_ibfk_1` FOREIGN KEY (`id_projeto`) REFERENCES `projetos` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `tarefas`
--

LOCK TABLES `tarefas` WRITE;
/*!40000 ALTER TABLE `tarefas` DISABLE KEYS */;
INSERT INTO `tarefas` VALUES (1,3,'Desenvolver humanos 1','2026-11-23 13:43:15','2026-11-23 13:43:48',NULL),(2,1,'Toma no cu','2026-11-23 14:11:35','2026-11-23 14:21:41',NULL),(3,4,'67','2026-11-23 14:23:46',NULL,NULL),(4,1,'104','2026-11-23 14:42:16','2026-11-23 14:42:19',NULL);
/*!40000 ALTER TABLE `tarefas` ENABLE KEYS */;
UNLOCK TABLES;

--
-- Table structure for table `usuarios`
--

DROP TABLE IF EXISTS `usuarios`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8 */;
CREATE TABLE `usuarios` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nome` varchar(120) NOT NULL,
  `email` varchar(150) NOT NULL,
  `senha` varchar(255) NOT NULL,
  `role` enum('admin','default') NOT NULL DEFAULT 'default',
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=latin1 COLLATE=latin1_swedish_ci;
/*!40101 SET character_set_client = @saved_cs_client */;

--
-- Dumping data for table `usuarios`
--

LOCK TABLES `usuarios` WRITE;
/*!40000 ALTER TABLE `usuarios` DISABLE KEYS */;
INSERT INTO `usuarios` VALUES (1,'Yasmim','yasmim@gmail.com','$2y$10$zT5gQN5b.6XjBc7dcmKZ9.biEOLYj6UZK0uJJsDVc1SREfwnAZUNO','admin'),(2,'Samuel','samuel@gmail.com','$2y$10$bV5e2ODHz7GlHuv005wqX.SuorcehNmhHvCXngXO2ayu17UnRLeyy','default'),(3,'PedrinMatador','pedrinomatador@gmail.com','$2y$10$.wKW0NrawMYu3kmWhPvPeuo7cH4xV.4AdqqzGBjSkItnyj9Sh6GcW','default'),(4,'aiai','ai@gmail.com','$2y$10$yFzJhJWowHFNh1Kuo1qLkuemcCZYIE.Qokdi9.fm3uWRal2ggxYf6','default');
/*!40000 ALTER TABLE `usuarios` ENABLE KEYS */;
UNLOCK TABLES;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

-- Dump completed on 2026-11-23 14:57:09
