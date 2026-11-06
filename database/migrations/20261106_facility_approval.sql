-- Apply once after backing up the turfmate database.
-- Existing facilities are deliberately pending until explicitly reviewed.
ALTER TABLE facilities
    ADD COLUMN approval_status ENUM('pending', 'approved', 'rejected', 'suspended') NOT NULL DEFAULT 'pending' AFTER status,
    ADD COLUMN reviewed_by BIGINT UNSIGNED NULL AFTER approval_status,
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by,
    ADD COLUMN rejection_reason TEXT NULL AFTER reviewed_at,
    ADD INDEX idx_facility_approval (approval_status),
    ADD CONSTRAINT fk_facility_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL;
