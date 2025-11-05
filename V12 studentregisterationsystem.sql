-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 05, 2025 at 06:32 AM
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

-- --------------------------------------------------------

--
-- Table structure for table `class`
--

CREATE TABLE `class` (
  `ClassID` int(11) NOT NULL,
  `CourseID` int(11) DEFAULT NULL,
  `Class_Name` varchar(50) NOT NULL,
  `timetable_image` varchar(255) DEFAULT NULL,
  `Semester` varchar(10) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `class`
--

INSERT INTO `class` (`ClassID`, `CourseID`, `Class_Name`, `timetable_image`, `Semester`, `deleted_at`) VALUES
(1, 1, 'DMET1', NULL, '1', NULL),
(2, 1, 'DMET2', NULL, '1', NULL),
(3, 1, 'DMET1', NULL, '2', NULL),
(4, 1, 'DMET2', NULL, '2', NULL),
(5, 2, 'DPIC1', NULL, '1', NULL),
(6, 2, 'DPIC2', NULL, '1', NULL),
(7, 2, 'DPIC1', NULL, '2', NULL),
(8, 2, 'DPIC2', NULL, '2', NULL),
(9, 3, 'DEIT1', NULL, '1', NULL),
(10, 3, 'DEIT2', NULL, '1', NULL),
(11, 3, 'DEIT1', NULL, '2', NULL),
(12, 3, 'DEIT2', NULL, '2', NULL),
(13, 4, 'DCNC1', NULL, '1', NULL),
(14, 4, 'DCNC2', NULL, '1', NULL),
(15, 4, 'DCNC1', NULL, '2', NULL),
(16, 4, 'DCNC2', NULL, '2', NULL),
(17, 5, 'DPDM1', NULL, '1', NULL),
(18, 5, 'DPDM2', NULL, '1', NULL),
(19, 5, 'DPDM1', NULL, '2', NULL),
(20, 5, 'DPDM2', NULL, '2', NULL),
(21, 6, 'DMTM1', NULL, '1', NULL),
(22, 6, 'DMTM2', NULL, '1', NULL),
(23, 6, 'DMTM1', NULL, '2', NULL),
(24, 6, 'DMTM2', NULL, '2', NULL),
(25, 7, 'DCBS1', NULL, '1', NULL),
(26, 7, 'DCBS2', NULL, '1', NULL),
(27, 7, 'DCBS1', NULL, '2', NULL),
(28, 7, 'DCBS2', NULL, '2', NULL),
(29, 8, 'DSWE1', NULL, '1', NULL),
(30, 8, 'DSWE2', NULL, '1', NULL),
(31, 8, 'DSWE1', NULL, '2', NULL),
(32, 8, 'DSWE2', NULL, '2', NULL),
(33, 9, 'DCRM1', NULL, '1', NULL),
(34, 9, 'DCRM2', NULL, '1', NULL),
(35, 9, 'DCRM1', NULL, '2', NULL),
(36, 9, 'DCRM2', NULL, '2', NULL),
(37, 11, 'GEL10', NULL, '1', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `course`
--

CREATE TABLE `course` (
  `CourseID` int(11) NOT NULL,
  `DepartmentID` int(11) DEFAULT NULL,
  `Course_Code` varchar(10) NOT NULL,
  `Course_Name` varchar(100) NOT NULL,
  `Credit` int(11) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `course`
--

INSERT INTO `course` (`CourseID`, `DepartmentID`, `Course_Code`, `Course_Name`, `Credit`, `deleted_at`) VALUES
(1, 2, 'DMET', 'DIPLOMA IN MECHATRONICS', 3, NULL),
(2, 2, 'DPIC', 'DIPLOMA IN PROCESS INSTRUMENTATION AND CONTROL', 3, NULL),
(3, 2, 'DEIT', 'DIPLOMA IN ELECTRONICS AND INFORMATION TECHNOLOGY', 3, NULL),
(4, 3, 'DCNC', 'DIPLOMA IN CNC PRECISION TECHNOLOGY', 3, NULL),
(5, 3, 'DPDM', 'DIPLOMA IN PRODUCT DESIGN AND MANUFACTURING', 3, NULL),
(6, 3, 'DMTM', 'DIPLOMA IN MACHINE TOOL MAINTENANCE', 3, NULL),
(7, 1, 'DCBS', 'DIPLOMA IN CYBER SECURITY', 3, NULL),
(8, 1, 'DSWE', 'DIPLOMA IN SOFTWARE ENGINEERING', 3, NULL),
(9, 1, 'DCRM', 'DIPLOMA IN CREATIVE MULTIMEDIA', 3, NULL),
(10, 1, 'DNWS', 'DIPLOMA IN NETWORK SECURITY', NULL, '2025-10-26 12:08:13'),
(11, 5, 'GAPP', 'PRE-GERMAN', NULL, NULL),
(12, 6, 'FED', 'TEST', NULL, NULL),
(13, 6, 'FED', 'TEST', NULL, NULL),
(14, 6, 'FED', 'TEST', NULL, NULL),
(15, 6, 'FED', 'TEST', NULL, NULL),
(16, 6, 'FED', 'TEST', NULL, NULL),
(17, 6, 'FED', 'TEST', NULL, NULL),
(18, 6, 'FED', 'TEST', NULL, NULL),
(19, 6, 'FED', 'TEST', NULL, NULL),
(20, 6, 'FED', 'TEST', NULL, NULL),
(21, 6, 'FED', 'TEST', NULL, NULL),
(22, 7, 'FED', 'TEST', NULL, '2025-11-03 16:47:36'),
(23, 1, 'FED', 'TEST', NULL, '2025-11-03 16:47:19'),
(24, 2, 'FED', 'TEST', NULL, NULL),
(25, 7, 'FBI', 'FEDERAL AGENT', NULL, NULL),
(26, 7, 'FBI', 'FEDERAL AGENT', NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `department`
--

CREATE TABLE `department` (
  `DepartmentID` int(11) NOT NULL,
  `Dept_Code` varchar(10) NOT NULL,
  `Dept_Name` varchar(100) NOT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `department`
--

INSERT INTO `department` (`DepartmentID`, `Dept_Code`, `Dept_Name`, `deleted_at`) VALUES
(1, 'CID', 'COMPUTER & INFORMATION DEPARTMENT', NULL),
(2, 'MED', 'MECHANICAL ENGINEERING DEPARTMENT', NULL),
(3, 'EED', 'ELECTRICAL ENGINEERING DEPARTMENT', NULL),
(5, 'FPGS', 'GENERAL STUDY', '2025-10-26 13:26:35'),
(6, 'GS', 'GENERAL STUDY', '2025-10-27 20:18:41'),
(7, 'FED', 'FEDERAL STUDY', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `gpa`
--

CREATE TABLE `gpa` (
  `GPAID` int(11) NOT NULL,
  `UserID` int(11) NOT NULL,
  `Semester` int(11) NOT NULL,
  `GPA` decimal(4,2) NOT NULL CHECK (`GPA` >= 0.00 and `GPA` <= 4.00)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `gpa`
--

INSERT INTO `gpa` (`GPAID`, `UserID`, `Semester`, `GPA`) VALUES
(90, 7, 0, 0.00);

-- --------------------------------------------------------

--
-- Table structure for table `student`
--

CREATE TABLE `student` (
  `UserID` int(11) NOT NULL,
  `StudentID` varchar(11) NOT NULL,
  `ClassID` int(11) DEFAULT NULL,
  `FullName` varchar(100) NOT NULL,
  `IC_Number` varchar(15) DEFAULT NULL,
  `Phone` varchar(15) DEFAULT NULL,
  `deleted_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `student`
--

INSERT INTO `student` (`UserID`, `StudentID`, `ClassID`, `FullName`, `IC_Number`, `Phone`, `deleted_at`) VALUES
(7, 'CBS24070656', 26, 'Muhaimin', '060423031349', '01157168040', NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user`
--

CREATE TABLE `user` (
  `UserID` int(11) NOT NULL,
  `Password_Hash` varchar(255) NOT NULL,
  `Role` enum('Student','Admin') DEFAULT 'Student',
  `Created_At` datetime DEFAULT current_timestamp(),
  `Last_Login` datetime DEFAULT NULL,
  `Email` varchar(100) NOT NULL,
  `Display_Name` varchar(120) DEFAULT NULL,
  `Profile_Image` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `user`
--

INSERT INTO `user` (`UserID`, `Password_Hash`, `Role`, `Created_At`, `Last_Login`, `Email`, `Display_Name`, `Profile_Image`) VALUES
(1, '$2y$10$g0opD5C7yR3XLGAZeKjztOBG61LxPwQDgfT4MiAPpx/DUXQQWuEVi', 'Admin', '2025-10-21 19:35:40', '2025-11-04 22:31:44', 'admin@gmail.com', NULL, 'uploads/user_1_1761981967.jpg'),
(7, '$2y$10$dyz4NXZPYCFtODCWac/hseUVh4PB0bgDDU0Y4toDdag6qIgFepHGe', 'Student', '2025-10-25 21:44:48', '2025-11-05 02:05:47', 'meen@gmail.com', NULL, NULL);

--
-- Indexes for dumped tables
--

--
-- Indexes for table `class`
--
ALTER TABLE `class`
  ADD PRIMARY KEY (`ClassID`),
  ADD KEY `CourseID` (`CourseID`);

--
-- Indexes for table `course`
--
ALTER TABLE `course`
  ADD PRIMARY KEY (`CourseID`),
  ADD KEY `DepartmentID` (`DepartmentID`);

--
-- Indexes for table `department`
--
ALTER TABLE `department`
  ADD PRIMARY KEY (`DepartmentID`),
  ADD KEY `idx_deleted_at` (`deleted_at`);

--
-- Indexes for table `gpa`
--
ALTER TABLE `gpa`
  ADD PRIMARY KEY (`GPAID`),
  ADD KEY `UserID` (`UserID`);

--
-- Indexes for table `student`
--
ALTER TABLE `student`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `IC_Number` (`IC_Number`),
  ADD KEY `ClassID` (`ClassID`);

--
-- Indexes for table `user`
--
ALTER TABLE `user`
  ADD PRIMARY KEY (`UserID`),
  ADD UNIQUE KEY `Email` (`Email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `class`
--
ALTER TABLE `class`
  MODIFY `ClassID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=38;

--
-- AUTO_INCREMENT for table `course`
--
ALTER TABLE `course`
  MODIFY `CourseID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=27;

--
-- AUTO_INCREMENT for table `department`
--
ALTER TABLE `department`
  MODIFY `DepartmentID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `gpa`
--
ALTER TABLE `gpa`
  MODIFY `GPAID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=92;

--
-- AUTO_INCREMENT for table `student`
--
ALTER TABLE `student`
  MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `user`
--
ALTER TABLE `user`
  MODIFY `UserID` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `class`
--
ALTER TABLE `class`
  ADD CONSTRAINT `class_ibfk_1` FOREIGN KEY (`CourseID`) REFERENCES `course` (`CourseID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `course`
--
ALTER TABLE `course`
  ADD CONSTRAINT `course_ibfk_1` FOREIGN KEY (`DepartmentID`) REFERENCES `department` (`DepartmentID`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `gpa`
--
ALTER TABLE `gpa`
  ADD CONSTRAINT `gpa_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `student`
--
ALTER TABLE `student`
  ADD CONSTRAINT `student_ibfk_1` FOREIGN KEY (`UserID`) REFERENCES `user` (`UserID`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `student_ibfk_2` FOREIGN KEY (`ClassID`) REFERENCES `class` (`ClassID`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
