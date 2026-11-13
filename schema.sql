CREATE TABLE IF NOT EXISTS ngos (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(190) NOT NULL UNIQUE,
 description VARCHAR(1000) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 full_name VARCHAR(140) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 organization VARCHAR(190) NOT NULL,
 job_title VARCHAR(120) DEFAULT NULL,
 phone VARCHAR(40) DEFAULT NULL,
 password_hash VARCHAR(255) NOT NULL,
 role ENUM('super_admin','manager','engineer','client') NOT NULL,
 ngo_id INT UNSIGNED NULL,
 is_active TINYINT(1) NOT NULL DEFAULT 1,
 deleted_at DATETIME DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS projects (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 manager_id INT UNSIGNED NOT NULL,
 ngo_id INT UNSIGNED NULL,
 project_code CHAR(6) NOT NULL UNIQUE,
 title VARCHAR(190) NOT NULL,
 description TEXT NOT NULL,
 location VARCHAR(190) NOT NULL,
 category VARCHAR(90) NOT NULL,
 start_date DATE DEFAULT NULL,
 end_date DATE DEFAULT NULL,
 status ENUM('on_track','delayed','completed','on_hold') NOT NULL DEFAULT 'on_track',
 budget_total DECIMAL(15,2) NOT NULL DEFAULT 0,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_projects_manager FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_project_manager (manager_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS project_clients (
 project_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (project_id,client_id),
 CONSTRAINT fk_pc_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_pc_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS activities (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 target_description VARCHAR(255) NOT NULL,
 progress_percent TINYINT UNSIGNED NOT NULL DEFAULT 0,
 weight_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
 duration_days INT UNSIGNED NOT NULL DEFAULT 1,
 start_date DATE DEFAULT NULL,
 end_date DATE DEFAULT NULL,
 approval_status ENUM('pending','submitted','approved','rejected') NOT NULL DEFAULT 'pending',
 submitted_by INT UNSIGNED DEFAULT NULL,
 submitted_at DATETIME DEFAULT NULL,
 confirmed_by INT UNSIGNED DEFAULT NULL,
 confirmed_at DATETIME DEFAULT NULL,
 engineer_note VARCHAR(1000) DEFAULT NULL,
 manager_note VARCHAR(1000) DEFAULT NULL,
 deleted_at DATETIME DEFAULT NULL,
 deleted_by INT UNSIGNED DEFAULT NULL,
 delete_reason VARCHAR(500) DEFAULT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_activity_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 INDEX idx_activity_project (project_id),
 INDEX idx_activity_active (project_id,deleted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS evidence (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 activity_id INT UNSIGNED NOT NULL,
 uploader_id INT UNSIGNED NOT NULL,
 image_file VARCHAR(100) NOT NULL,
 original_name VARCHAR(180) NOT NULL,
 mime_type VARCHAR(50) NOT NULL,
 evidence_date DATE NOT NULL,
 sha256 CHAR(64) NOT NULL,
 dhash CHAR(64) NOT NULL,
 max_similarity DECIMAL(5,2) NOT NULL DEFAULT 0,
 matched_evidence_id INT UNSIGNED DEFAULT NULL,
 review_status ENUM('unique_ready','pending_review','approved','rejected') NOT NULL,
 review_note VARCHAR(500) DEFAULT NULL,
 reviewed_by INT UNSIGNED DEFAULT NULL,
 reviewed_at DATETIME DEFAULT NULL,
 uploaded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_evidence_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_evidence_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
 CONSTRAINT fk_evidence_uploader FOREIGN KEY (uploader_id) REFERENCES users(id) ON DELETE CASCADE,
 INDEX idx_evidence_project (project_id),
 INDEX idx_evidence_review (review_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS audit_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 project_id INT UNSIGNED DEFAULT NULL,
 action VARCHAR(90) NOT NULL,
 details TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_audit_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL,
 INDEX idx_audit_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS contact_messages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 sender_name VARCHAR(140) NOT NULL,
 sender_email VARCHAR(190) NOT NULL,
 subject VARCHAR(190) NOT NULL,
 message TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_access_requests (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 note VARCHAR(1000) NOT NULL,
 status ENUM('pending','approved','declined') NOT NULL DEFAULT 'pending',
 reviewed_at DATETIME DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_access_request (project_id, client_id),
 INDEX idx_access_project_status (project_id,status),
 CONSTRAINT fk_access_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_access_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS project_messages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 sender_id INT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_msg_thread (project_id,client_id,id),
 CONSTRAINT fk_msg_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_msg_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


CREATE TABLE IF NOT EXISTS project_message_reads (
 project_id INT UNSIGNED NOT NULL,
 client_id INT UNSIGNED NOT NULL,
 reader_id INT UNSIGNED NOT NULL,
 last_read_message_id INT UNSIGNED NOT NULL DEFAULT 0,
 read_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY(project_id,client_id,reader_id),
 CONSTRAINT fk_reads_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_reads_client FOREIGN KEY (client_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_reads_reader FOREIGN KEY (reader_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS project_engineers (
 project_id INT UNSIGNED NOT NULL,
 engineer_id INT UNSIGNED NOT NULL,
 PRIMARY KEY (project_id,engineer_id),
 CONSTRAINT fk_pe_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_pe_engineer FOREIGN KEY(engineer_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS activity_events (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 activity_id INT UNSIGNED NOT NULL,
 project_id INT UNSIGNED NOT NULL,
 actor_id INT UNSIGNED NOT NULL,
 event_type VARCHAR(40) NOT NULL,
 note VARCHAR(1000) NOT NULL DEFAULT '',
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_ae_activity(activity_id),
 CONSTRAINT fk_ae_activity FOREIGN KEY(activity_id) REFERENCES activities(id) ON DELETE CASCADE,
 CONSTRAINT fk_ae_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_ae_user FOREIGN KEY(actor_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS project_finances (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 entered_by INT UNSIGNED NOT NULL,
 entry_type ENUM('funding','expense') NOT NULL,
 approval_status ENUM('submitted','approved','rejected') NOT NULL DEFAULT 'approved',
 reviewed_by INT UNSIGNED DEFAULT NULL,
 reviewed_at DATETIME DEFAULT NULL,
 manager_note VARCHAR(1000) DEFAULT NULL,
 amount DECIMAL(15,2) NOT NULL,
 entry_date DATE NOT NULL,
 description VARCHAR(255) NOT NULL,
 reference VARCHAR(100) DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_finances_project(project_id,entry_date),
 CONSTRAINT fk_finance_project FOREIGN KEY(project_id) REFERENCES projects(id) ON DELETE CASCADE,
 CONSTRAINT fk_finance_user FOREIGN KEY(entered_by) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS notifications (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 title VARCHAR(190) NOT NULL,
 body VARCHAR(500) NOT NULL,
 target VARCHAR(255) NOT NULL,
 read_at DATETIME DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_notification_user(user_id,read_at),
 CONSTRAINT fk_notif_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS engineer_messages (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 project_id INT UNSIGNED NOT NULL,
 engineer_id INT UNSIGNED NOT NULL,
 sender_id INT UNSIGNED NOT NULL,
 body TEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 INDEX idx_engineer_thread(project_id,engineer_id,id),
 FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
 FOREIGN KEY (engineer_id) REFERENCES users(id) ON DELETE CASCADE,
 FOREIGN KEY (sender_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS support_tickets (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 subject VARCHAR(190) NOT NULL,
 body TEXT NOT NULL,
 status ENUM('open','resolved') NOT NULL DEFAULT 'open',
 owner_reply TEXT NULL,
 resolved_by INT UNSIGNED DEFAULT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX idx_ticket_status(status,created_at),
 FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
