-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Host: localhost:3306
-- Server version: 8.0.30
-- PHP Version: 8.1.10

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `planner`
--

-- --------------------------------------------------------

--
-- Table structure for table `modules`
--

CREATE TABLE `modules` (
  `module_id` int NOT NULL,
  `user_id` int NOT NULL,
  `module_name` varchar(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `study_plan`
--

CREATE TABLE `study_plan` (
  `session_id` int NOT NULL,
  `task_id` int NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `session_duration` int NOT NULL,
  `study_date` date NOT NULL,
  `plan_generated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `study_preferences`
--

CREATE TABLE `study_preferences` (
  `preference_id` int NOT NULL,
  `user_id` int NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `max_hours` int NOT NULL,
  `session_length` int NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `tasks`
--

CREATE TABLE `tasks` (
  `task_id` int NOT NULL,
  `module_id` int NOT NULL,
  `title` varchar(255) NOT NULL,
  `description` text,
  `difficulty` enum('Easy','Medium','Hard') DEFAULT 'Medium',
  `priority` enum('Low','Medium','High') DEFAULT 'Medium',
  `estimated_time` int DEFAULT NULL,
  `completed_time` int DEFAULT NULL,
  `status` enum('pending','in_progress','completed') NOT NULL DEFAULT 'pending',
  `deadline` date DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `timetable_events`
--

CREATE TABLE `timetable_events` (
  `event_id` int NOT NULL,
  `module_id` int NOT NULL,
  `day_of_week` enum('Monday','Tuesday','Wednesday','Thursday','Friday','Saturday','Sunday') NOT NULL,
  `start_time` time NOT NULL,
  `end_time` time NOT NULL,
  `event_type` varchar(50) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `user_id` int NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `modules`
--
ALTER TABLE `modules`
  ADD PRIMARY KEY (`module_id`),
  ADD KEY `user_id` (`user_id`);

--
-- Indexes for table `study_plan`
--
ALTER TABLE `study_plan`
  ADD PRIMARY KEY (`session_id`),
  ADD KEY `task_id` (`task_id`);

--
-- Indexes for table `study_preferences`
--
ALTER TABLE `study_preferences`
  ADD PRIMARY KEY (`preference_id`),
  ADD UNIQUE KEY `user_id` (`user_id`);

--
-- Indexes for table `tasks`
--
ALTER TABLE `tasks`
  ADD PRIMARY KEY (`task_id`),
  ADD KEY `module_id` (`module_id`);

--
-- Indexes for table `timetable_events`
--
ALTER TABLE `timetable_events`
  ADD PRIMARY KEY (`event_id`),
  ADD KEY `module_id` (`module_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`user_id`),
  ADD UNIQUE KEY `email` (`email`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `modules`
--
ALTER TABLE `modules`
  MODIFY `module_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `study_plan`
--
ALTER TABLE `study_plan`
  MODIFY `session_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `study_preferences`
--
ALTER TABLE `study_preferences`
  MODIFY `preference_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `tasks`
--
ALTER TABLE `tasks`
  MODIFY `task_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `timetable_events`
--
ALTER TABLE `timetable_events`
  MODIFY `event_id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `user_id` int NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `modules`
--
ALTER TABLE `modules`
  ADD CONSTRAINT `modules_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `study_plan`
--
ALTER TABLE `study_plan`
  ADD CONSTRAINT `study_plan_ibfk_1` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`task_id`) ON DELETE CASCADE;

--
-- Constraints for table `study_preferences`
--
ALTER TABLE `study_preferences`
  ADD CONSTRAINT `study_preferences_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE;

--
-- Constraints for table `tasks`
--
ALTER TABLE `tasks`
  ADD CONSTRAINT `tasks_ibfk_1` FOREIGN KEY (`module_id`) REFERENCES `modules` (`module_id`) ON DELETE CASCADE;

--
-- Constraints for table `timetable_events`
--
ALTER TABLE `timetable_events`
  ADD CONSTRAINT `timetable_events_ibfk_1` FOREIGN KEY (`module_id`) REFERENCES `modules` (`module_id`) ON DELETE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
