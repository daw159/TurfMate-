-- TurfMate Database Migration
-- Remove unused operating_hours table
-- Booking availability is managed without this table.

DROP TABLE IF EXISTS operating_hours;
