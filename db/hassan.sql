-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Sep 21, 2025 at 08:59 PM
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
-- Database: `hassan`
--

-- --------------------------------------------------------

--
-- Table structure for table `comparisons`
--

CREATE TABLE `comparisons` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `criterion1_id` int(11) NOT NULL,
  `criterion2_id` int(11) NOT NULL,
  `value` float NOT NULL,
  `matrix_id` int(11) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `consistency_ratio` float DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `criteria`
--

CREATE TABLE `criteria` (
  `id` int(11) NOT NULL,
  `matrix_id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `sort_order` int(11) DEFAULT 0,
  `parent_id` int(11) DEFAULT NULL,
  `active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `final_weights`
--

CREATE TABLE `final_weights` (
  `id` int(11) NOT NULL,
  `criterion_id` int(11) NOT NULL,
  `weight` float NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `matrices`
--

CREATE TABLE `matrices` (
  `id` int(11) NOT NULL,
  `name` varchar(100) NOT NULL,
  `description` text DEFAULT NULL,
  `parent_id` int(11) DEFAULT NULL,
  `is_main` tinyint(1) DEFAULT 0,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `active` tinyint(1) DEFAULT 1,
  `is_criteria_matrix` tinyint(1) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `matrix_values`
--

CREATE TABLE `matrix_values` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `row_matrix_id` int(11) NOT NULL,
  `col_matrix_id` int(11) NOT NULL,
  `value` float NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `fullname` varchar(100) NOT NULL,
  `phone` varchar(15) NOT NULL,
  `position` varchar(100) NOT NULL,
  `education` varchar(100) DEFAULT NULL,
  `current_step` int(11) DEFAULT 1,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `consistency_checked` tinyint(1) DEFAULT 0,
  `current_matrix_row` int(11) DEFAULT 0,
  `completed` tinyint(1) DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_general_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `comparisons`
--
ALTER TABLE `comparisons`
  ADD PRIMARY KEY (`id`),
  ADD KEY `user_id` (`user_id`),
  ADD KEY `criterion1_id` (`criterion1_id`),
  ADD KEY `criterion2_id` (`criterion2_id`),
  ADD KEY `matrix_id` (`matrix_id`);

--
-- Indexes for table `criteria`
--
ALTER TABLE `criteria`
  ADD PRIMARY KEY (`id`),
  ADD KEY `matrix_id` (`matrix_id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `final_weights`
--
ALTER TABLE `final_weights`
  ADD PRIMARY KEY (`id`),
  ADD KEY `criterion_id` (`criterion_id`);

--
-- Indexes for table `matrices`
--
ALTER TABLE `matrices`
  ADD PRIMARY KEY (`id`),
  ADD KEY `parent_id` (`parent_id`);

--
-- Indexes for table `matrix_values`
--
ALTER TABLE `matrix_values`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `unique_comparison` (`user_id`,`row_matrix_id`,`col_matrix_id`),
  ADD KEY `row_matrix_id` (`row_matrix_id`),
  ADD KEY `col_matrix_id` (`col_matrix_id`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `comparisons`
--
ALTER TABLE `comparisons`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `criteria`
--
ALTER TABLE `criteria`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `final_weights`
--
ALTER TABLE `final_weights`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `matrices`
--
ALTER TABLE `matrices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `matrix_values`
--
ALTER TABLE `matrix_values`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `comparisons`
--
ALTER TABLE `comparisons`
  ADD CONSTRAINT `comparisons_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `comparisons_ibfk_2` FOREIGN KEY (`criterion1_id`) REFERENCES `criteria` (`id`),
  ADD CONSTRAINT `comparisons_ibfk_3` FOREIGN KEY (`criterion2_id`) REFERENCES `criteria` (`id`),
  ADD CONSTRAINT `comparisons_ibfk_4` FOREIGN KEY (`matrix_id`) REFERENCES `matrices` (`id`);

--
-- Constraints for table `criteria`
--
ALTER TABLE `criteria`
  ADD CONSTRAINT `criteria_ibfk_1` FOREIGN KEY (`matrix_id`) REFERENCES `matrices` (`id`),
  ADD CONSTRAINT `criteria_ibfk_2` FOREIGN KEY (`parent_id`) REFERENCES `criteria` (`id`);

--
-- Constraints for table `final_weights`
--
ALTER TABLE `final_weights`
  ADD CONSTRAINT `final_weights_ibfk_1` FOREIGN KEY (`criterion_id`) REFERENCES `criteria` (`id`);

--
-- Constraints for table `matrices`
--
ALTER TABLE `matrices`
  ADD CONSTRAINT `matrices_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `matrices` (`id`);

--
-- Constraints for table `matrix_values`
--
ALTER TABLE `matrix_values`
  ADD CONSTRAINT `matrix_values_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`),
  ADD CONSTRAINT `matrix_values_ibfk_2` FOREIGN KEY (`row_matrix_id`) REFERENCES `matrices` (`id`),
  ADD CONSTRAINT `matrix_values_ibfk_3` FOREIGN KEY (`col_matrix_id`) REFERENCES `matrices` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
