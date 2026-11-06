-- Run once after backing up the database.
-- Staff accounts already use users.role = 'staff'.
CREATE TABLE IF NOT EXISTS staff_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    staff_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_staff_facility (staff_id, facility_id),
    INDEX idx_staff_assignments_facility (facility_id),
    CONSTRAINT fk_sa_staff FOREIGN KEY (staff_id) REFERENCES users(id),
    CONSTRAINT fk_sa_facility FOREIGN KEY (facility_id) REFERENCES facilities(id),
    CONSTRAINT fk_sa_owner FOREIGN KEY (assigned_by) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
