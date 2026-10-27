-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Jul 07, 2026 at 05:53 PM
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
-- Database: `bottlebank_db`
--

-- --------------------------------------------------------

--
-- Table structure for table `bottle_entries`
--

CREATE TABLE `bottle_entries` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `bottle_type` varchar(50) DEFAULT NULL,
  `bottle_size` varchar(20) DEFAULT NULL,
  `quantity` int(11) DEFAULT NULL,
  `money_earned` decimal(10,2) DEFAULT 0.00,
  `points_earned` int(11) DEFAULT 0,
  `recorded_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `login_logs`
--

CREATE TABLE `login_logs` (
  `id` int(11) NOT NULL,
  `user_id` int(11) NOT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `login_time` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_logs`
--

INSERT INTO `login_logs` (`id`, `user_id`, `ip_address`, `user_agent`, `login_time`) VALUES
(1, 2, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-07 14:07:20'),
(2, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-07 15:20:12'),
(3, 3, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-07 15:28:05'),
(4, 1, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-07 15:29:50'),
(5, 4, '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/149.0.0.0 Safari/537.36', '2026-07-07 15:35:33');

-- --------------------------------------------------------

--
-- Table structure for table `password_resets`
--

CREATE TABLE `password_resets` (
  `id` int(11) NOT NULL,
  `email` varchar(100) NOT NULL,
  `token` varchar(255) NOT NULL,
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `price_logs`
--

CREATE TABLE `price_logs` (
  `id` int(11) NOT NULL,
  `waste_code` varchar(50) NOT NULL,
  `old_price` decimal(10,2) DEFAULT NULL,
  `new_price` decimal(10,2) DEFAULT NULL,
  `old_points` int(11) DEFAULT NULL,
  `new_points` int(11) DEFAULT NULL,
  `updated_by` int(11) DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `redemptions`
--

CREATE TABLE `redemptions` (
  `id` int(11) NOT NULL,
  `student_id` int(11) NOT NULL,
  `type` enum('money','points') DEFAULT NULL,
  `amount` decimal(10,2) DEFAULT 0.00,
  `points` int(11) DEFAULT 0,
  `status` enum('pending','approved','rejected') DEFAULT 'pending',
  `approver_id` int(11) DEFAULT NULL,
  `admin_note` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `system_settings`
--

CREATE TABLE `system_settings` (
  INSERT INTO system_settings (setting_key, setting_value) VALUES
('smtp_host', 'smtp.gmail.com'),
('smtp_port', '587'),
('smtp_user', 'your_email@gmail.com'),
('smtp_pass', 'your_app_password_16_digits'),
('smtp_secure', 'tls');
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(11) NOT NULL,
  `username` varchar(100) DEFAULT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) DEFAULT NULL,
  `role` enum('user','admin','superadmin') DEFAULT 'user',
  `first_name` varchar(100) DEFAULT NULL,
  `last_name` varchar(100) DEFAULT NULL,
  `id_code` varchar(20) DEFAULT NULL,
  `class_group` varchar(20) DEFAULT NULL,
  `avatar` varchar(255) DEFAULT NULL,
  `balance` decimal(10,2) DEFAULT 0.00,
  `points` int(11) DEFAULT 0,
  `is_active` tinyint(1) DEFAULT 1,
  `remember_token` varchar(64) DEFAULT NULL,
  `last_activity` datetime DEFAULT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `email_verification_token` varchar(255) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `email`, `password`, `role`, `first_name`, `last_name`, `id_code`, `class_group`, `avatar`, `balance`, `points`, `is_active`, `remember_token`, `last_activity`, `google_id`, `email_verification_token`, `email_verified_at`, `created_at`, `updated_at`) VALUES
(1, 'g', 'admin@suthi.ac.th', '$2y$10$ZAw4uyAWniwkVbascHveluIDgL0zUUF0Vwe7aVr8bzxnaEZn5vFmS', 'superadmin', 'Super', 'Admin', '54321', NULL, NULL, 0.00, 0, 1, NULL, '2026-07-07 22:29:50', NULL, NULL, NULL, '2026-07-07 13:10:09', '2026-07-07 15:29:50'),
(2, 'chxrxmxt@gmail.com', 'chxrxmxt@gmail.com', NULL, 'user', 'Chiramet', 'Bouaun', NULL, NULL, NULL, 0.00, 0, 1, NULL, '2026-07-07 21:09:07', '117242215248630358586', NULL, NULL, '2026-07-07 14:07:20', '2026-07-07 14:09:07'),
(3, NULL, '53706@ecobank.local', '$2y$10$kcpkh9HADHt0u9Bzhayv3eTLUQPHWxnZxmDukYzAhZp5ajJnsI6wm', 'admin', 'Chiramet', 'Bouaun', '53706', NULL, NULL, 0.00, 0, 1, NULL, '2026-07-07 22:28:06', NULL, NULL, NULL, '2026-07-07 15:23:25', '2026-07-07 15:28:06'),
(4, NULL, '12@gmail.com', '$argon2id$v=19$m=65536,t=4,p=1$VkhIZ1pUWDBucEl0M1ZXdw$NhOa77pNt7wzJhT8+L/37C4c012POAKMwlHQTOEpTOA', 'user', 'ee', 'e', '12345', NULL, NULL, 0.00, 0, 1, NULL, '2026-07-07 22:38:18', NULL, NULL, NULL, '2026-07-07 15:34:34', '2026-07-07 15:38:18');

-- --------------------------------------------------------

--
-- Table structure for table `waste_types`
--

CREATE TABLE `waste_types` (
  `id` int(11) NOT NULL,
  `code` varchar(50) NOT NULL,
  `name` varchar(100) NOT NULL,
  `price` decimal(10,2) DEFAULT 0.00,
  `points` int(11) DEFAULT 0,
  `unit` varchar(20) DEFAULT 'ชิ้น'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `waste_types`
--

INSERT INTO `waste_types` (`id`, `code`, `name`, `price`, `points`, `unit`) VALUES
(1, 'pet', 'ขวดพลาสติก PET', 2.00, 10, 'ชิ้น'),
(2, 'can', 'กระป๋องอลูมิเนียม', 3.00, 15, 'ชิ้น'),
(3, 'glass', 'ขวดแก้ว', 1.50, 8, 'ชิ้น'),
(4, 'paper', 'กระดาษ', 1.00, 5, 'กิโลกรัม');

--
-- Indexes for dumped tables
--

--
-- Indexes for table `bottle_entries`
--
ALTER TABLE `bottle_entries`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `login_logs`
--
ALTER TABLE `login_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `password_resets`
--
ALTER TABLE `password_resets`
  ADD PRIMARY KEY (`id`),
  ADD KEY `email` (`email`,`token`);

--
-- Indexes for table `price_logs`
--
ALTER TABLE `price_logs`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `redemptions`
--
ALTER TABLE `redemptions`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `system_settings`
--
ALTER TABLE `system_settings`
  ADD PRIMARY KEY (`setting_key`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `username` (`username`),
  ADD UNIQUE KEY `google_id` (`google_id`);

--
-- Indexes for table `waste_types`
--
ALTER TABLE `waste_types`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `bottle_entries`
--
ALTER TABLE `bottle_entries`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `login_logs`
--
ALTER TABLE `login_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `password_resets`
--
ALTER TABLE `password_resets`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `price_logs`
--
ALTER TABLE `price_logs`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `redemptions`
--
ALTER TABLE `redemptions`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `waste_types`
--
ALTER TABLE `waste_types`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
