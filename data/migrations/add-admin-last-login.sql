-- Migration: add last_login column to admin table
-- Apply once: mysql vimbadmin < add-admin-last-login.sql
ALTER TABLE `admin` ADD COLUMN `last_login` DATETIME NULL DEFAULT NULL AFTER `modified`;
