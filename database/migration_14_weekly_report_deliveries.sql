-- Track weekly report delivery per recipient and reporting week so reruns
-- do not send duplicate reports and failed sends remain retryable.
CREATE TABLE IF NOT EXISTS weekly_report_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    report_key VARCHAR(320) NOT NULL UNIQUE,
    week_start DATE NOT NULL,
    week_end DATE NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    report_scope ENUM('department', 'full') NOT NULL,
    department_id INT DEFAULT NULL,
    delivery_status ENUM('sent', 'failed') NOT NULL,
    attempted_at DATETIME NOT NULL,
    sent_at DATETIME DEFAULT NULL,
    last_error VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_weekly_report_period (week_start, week_end)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
