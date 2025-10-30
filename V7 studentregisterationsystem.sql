-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Oct 29, 2025 at 02:47 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `studentregisterationsystem`
--

--
-- Dumping data for table `user`
--

UPDATE `user` SET `UserID` = 1,`Password_Hash` = '$2y$10$g0opD5C7yR3XLGAZeKjztOBG61LxPwQDgfT4MiAPpx/DUXQQWuEVi',`Role` = 'Admin',`Created_At` = '2025-10-21 19:35:40',`Last_Login` = '2025-10-29 15:57:49',`Email` = 'admin@gmail.com' WHERE `user`.`UserID` = 1;
UPDATE `user` SET `UserID` = 7,`Password_Hash` = '$2y$10$dyz4NXZPYCFtODCWac/hseUVh4PB0bgDDU0Y4toDdag6qIgFepHGe',`Role` = 'Student',`Created_At` = '2025-10-25 21:44:48',`Last_Login` = '2025-10-29 15:09:40',`Email` = 'meen@gmail.com' WHERE `user`.`UserID` = 7;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
