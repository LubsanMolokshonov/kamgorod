CREATE TABLE IF NOT EXISTS course_consultations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NULL,
    course_title VARCHAR(500) NULL,
    phone VARCHAR(20) NOT NULL,
    status ENUM('new','processed','closed') DEFAULT 'new',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
