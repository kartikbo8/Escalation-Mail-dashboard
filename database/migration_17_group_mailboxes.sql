-- Apply after migration_16_secure_account_activation.sql on existing installations.
-- Fresh installations already contain this table in schema.sql.
-- A mail sent to a group / distribution list (e.g. northdesign@, orderlogin@)
-- lands in every member's mailbox. These rows decide who owns such a ticket:
-- it is assigned to the owner, and Level 1 reminders go to the owner and manager.
CREATE TABLE IF NOT EXISTS group_mailboxes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL,
    owner_user_id INT NOT NULL,
    manager_user_id INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_group_mailboxes_email (email),
    FOREIGN KEY (owner_user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (manager_user_id) REFERENCES users(id) ON DELETE SET NULL
);
