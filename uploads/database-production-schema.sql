-- SupplierVault Pro - Complete Database Schema for Production
-- Database: skuytove_supplierVault_db
-- Target: $57B Global Compliance Management Market

CREATE DATABASE IF NOT EXISTS `skuytove_supplierVault_db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `skuytove_supplierVault_db`;

-- =============================================
-- CORE TABLES
-- =============================================

-- Users and Authentication
CREATE TABLE `users` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `username` VARCHAR(50) UNIQUE NOT NULL,
    `email` VARCHAR(100) UNIQUE NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `first_name` VARCHAR(100) NOT NULL,
    `last_name` VARCHAR(100) NOT NULL,
    `role` ENUM('admin', 'compliance_manager', 'auditor', 'viewer') DEFAULT 'viewer',
    `organization` VARCHAR(200) DEFAULT 'Septona Bulgaria JSC',
    `department` VARCHAR(100),
    `is_active` BOOLEAN DEFAULT TRUE,
    `last_login` TIMESTAMP NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Suppliers (Your Real Data + ISO Compliance Scoring)
CREATE TABLE `suppliers` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `legal_name` VARCHAR(255),
    `registration_number` VARCHAR(100),
    `tax_id` VARCHAR(100),
    `website` VARCHAR(255),
    `supplier_type` ENUM('Direct', 'Indirect', 'Service', 'Raw_Material', 'Critical') DEFAULT 'Direct',
    `product_categories` JSON,
    `business_sector` VARCHAR(100),
    
    -- Address Information
    `address_street` VARCHAR(255),
    `address_city` VARCHAR(100) DEFAULT 'Ruse',
    `address_postal_code` VARCHAR(20),
    `address_country` VARCHAR(100) DEFAULT 'Bulgaria',
    
    -- ISO Risk Assessment (Based on Your Analysis)
    `risk_category` ENUM('A', 'B', 'C') NOT NULL COMMENT 'A=High Risk, B=Medium Risk, C=Low Risk',
    `iso_compliance_score` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'ISO-compliant scoring 0-100%',
    `overall_compliance_score` DECIMAL(5,2) DEFAULT 0.00 COMMENT 'Overall compliance percentage',
    
    -- Operational Scores (From Your Excel)
    `delivery_score` INT DEFAULT 0,
    `price_score` INT DEFAULT 0,
    `communication_score` INT DEFAULT 0,
    `quality_score` INT DEFAULT 0,
    `reliability_score` INT DEFAULT 0,
    `technical_knowledge_score` INT DEFAULT 0,
    `equipment_efficiency_score` INT DEFAULT 0,
    `equipment_compatibility_score` INT DEFAULT 0,
    
    -- Business Information
    `annual_spend_eur` DECIMAL(15,2) DEFAULT 0.00,
    `business_critical` BOOLEAN DEFAULT FALSE,
    `preferred_supplier` BOOLEAN DEFAULT FALSE,
    
    -- Audit Information
    `last_audit_date` DATE NULL,
    `next_audit_due` DATE NOT NULL,
    `audit_frequency_months` INT DEFAULT 12,
    
    -- Status and Flags
    `status` ENUM('Active', 'Inactive', 'Suspended', 'Under_Review', 'Terminated') DEFAULT 'Active',
    `onboarding_status` ENUM('Not_Started', 'In_Progress', 'Completed', 'Failed') DEFAULT 'Not_Started',
    `data_protection_compliant` BOOLEAN DEFAULT FALSE,
    
    -- Metadata
    `created_by` INT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`created_by`) REFERENCES `users`(`id`),
    INDEX `idx_risk_category` (`risk_category`),
    INDEX `idx_compliance_score` (`iso_compliance_score`),
    INDEX `idx_status` (`status`),
    INDEX `idx_next_audit` (`next_audit_due`)
) ENGINE=InnoDB;

-- Contact Persons (From Your Supplier List)
CREATE TABLE `contacts` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` INT NOT NULL,
    `name` VARCHAR(200) NOT NULL,
    `position` VARCHAR(100),
    `email` VARCHAR(100),
    `phone` VARCHAR(50),
    `mobile` VARCHAR(50),
    `department` VARCHAR(100),
    `is_primary` BOOLEAN DEFAULT FALSE,
    `language` VARCHAR(10) DEFAULT 'bg',
    `notes` TEXT,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE,
    INDEX `idx_supplier_contact` (`supplier_id`, `is_primary`)
) ENGINE=InnoDB;

-- =============================================
-- STANDARDS & COMPLIANCE FRAMEWORK
-- =============================================

-- Global Standards Library (All Major Standards)
CREATE TABLE `standards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `name` VARCHAR(100) NOT NULL,
    `version` VARCHAR(20),
    `standard_type` ENUM(
        'ISO_9001', 'ISO_14001', 'ISO_45001', 'ISO_13485', 'ISO_27001',
        'IATF_16949', 'AS9100', 'AS9110', 'AS9120',
        'BRC', 'IFS', 'FSSC_22000', 'SQF', 'HACCP',
        'FDA_QSR', 'MDR', 'IVDR', 'GMP',
        'API', 'NORSOK', 'VDA', 'AIAG',
        'Other'
    ) NOT NULL,
    `industry_sector` VARCHAR(100),
    `description` TEXT,
    `regulatory_body` VARCHAR(255),
    `mandatory_for_sectors` JSON,
    `certification_required` BOOLEAN DEFAULT TRUE,
    `audit_frequency_months` INT DEFAULT 12,
    `weightage` DECIMAL(3,2) DEFAULT 1.00,
    `is_active` BOOLEAN DEFAULT TRUE,
    `requirements_checklist` JSON,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Supplier-Standard Relationships (Certification Status)
CREATE TABLE `supplier_standards` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` INT NOT NULL,
    `standard_id` INT NOT NULL,
    
    -- Certification Status
    `certification_status` ENUM('Certified', 'Not_Certified', 'In_Progress', 'Expired', 'Suspended') DEFAULT 'Not_Certified',
    `certificate_number` VARCHAR(100),
    `issuing_body` VARCHAR(255),
    `accreditation_body` VARCHAR(255),
    
    -- Validity Dates
    `valid_from` DATE,
    `valid_to` DATE,
    `renewal_due` DATE,
    
    -- Assessment Scores
    `last_assessment_score` DECIMAL(5,2) DEFAULT 0.00,
    `compliance_percentage` DECIMAL(5,2) DEFAULT 0.00,
    
    -- Non-Conformities
    `critical_non_compliances` INT DEFAULT 0,
    `major_non_compliances` INT DEFAULT 0,
    `minor_non_compliances` INT DEFAULT 0,
    `observations` INT DEFAULT 0,
    
    -- Next Assessment
    `next_assessment_due` DATE,
    `assessment_type` ENUM('Initial', 'Surveillance', 'Re_certification', 'Special') DEFAULT 'Initial',
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`),
    UNIQUE KEY `unique_supplier_standard` (`supplier_id`, `standard_id`),
    INDEX `idx_cert_status` (`certification_status`),
    INDEX `idx_expiry` (`valid_to`)
) ENGINE=InnoDB;

-- =============================================
-- DOCUMENT MANAGEMENT SYSTEM
-- =============================================

-- Documents (Certificates, Policies, Reports)
CREATE TABLE `documents` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` INT NOT NULL,
    `standard_id` INT NULL,
    `name` VARCHAR(500) NOT NULL,
    `document_number` VARCHAR(100),
    
    -- Document Classification
    `type` ENUM('Certificate', 'Policy', 'Procedure', 'Record', 'MSDS', 'Test_Report', 'Audit_Report', 'CAP', 'Other') NOT NULL,
    `category` ENUM('Quality', 'Environmental', 'Food_Safety', 'Health_Safety', 'Regulatory', 'Financial', 'Legal') DEFAULT 'Quality',
    `confidentiality_level` ENUM('Public', 'Internal', 'Confidential', 'Restricted') DEFAULT 'Internal',
    
    -- File Information
    `version` VARCHAR(20) DEFAULT '1.0',
    `file_path` VARCHAR(1000) NOT NULL,
    `file_size` BIGINT,
    `file_type` VARCHAR(50),
    `mime_type` VARCHAR(100),
    `checksum` VARCHAR(64),
    
    -- Validity and Status
    `issue_date` DATE,
    `expiry_date` DATE NULL,
    `status` ENUM('Valid', 'Expired', 'Pending_Review', 'Rejected', 'Superseded') DEFAULT 'Valid',
    `review_due_date` DATE,
    
    -- Metadata
    `uploaded_by` INT,
    `reviewed_by` INT NULL,
    `approved_by` INT NULL,
    `upload_date` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `review_date` TIMESTAMP NULL,
    `approval_date` TIMESTAMP NULL,
    
    -- Tags and Search
    `tags` JSON,
    `language` VARCHAR(10) DEFAULT 'en',
    `searchable_content` TEXT,
    
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`),
    FOREIGN KEY (`uploaded_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`reviewed_by`) REFERENCES `users`(`id`),
    FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`),
    
    INDEX `idx_supplier_docs` (`supplier_id`),
    INDEX `idx_expiry_date` (`expiry_date`),
    INDEX `idx_document_status` (`status`),
    FULLTEXT KEY `ft_document_search` (`name`, `searchable_content`)
) ENGINE=InnoDB;

-- =============================================
-- ASSESSMENT & AUDIT MANAGEMENT
-- =============================================

-- Assessments/Audits
CREATE TABLE `assessments` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `supplier_id` INT NOT NULL,
    `standard_id` INT NOT NULL,
    `assessor_id` INT NOT NULL,
    
    -- Assessment Details
    `assessment_type` ENUM('Initial', 'Surveillance', 'Re_certification', 'Special', 'Follow_up') DEFAULT 'Initial',
    `assessment_method` ENUM('On_site', 'Remote', 'Hybrid', 'Desktop_Review') DEFAULT 'On_site',
    `planned_duration_days` INT DEFAULT 1,
    `actual_duration_hours` DECIMAL(4,2),
    
    -- Scheduling
    `scheduled_date` DATE NOT NULL,
    `start_time` DATETIME,
    `end_time` DATETIME,
    `completed_date` DATE NULL,
    
    -- Status and Progress
    `status` ENUM('Planned', 'Scheduled', 'In_Progress', 'Completed', 'Cancelled', 'Postponed') DEFAULT 'Planned',
    `progress_percentage` INT DEFAULT 0,
    
    -- Scoring
    `max_possible_score` DECIMAL(5,2) DEFAULT 100.00,
    `actual_score` DECIMAL(5,2) DEFAULT 0.00,
    `compliance_percentage` DECIMAL(5,2) DEFAULT 0.00,
    `pass_threshold` DECIMAL(5,2) DEFAULT 70.00,
    `assessment_result` ENUM('Pass', 'Conditional_Pass', 'Fail', 'Pending') DEFAULT 'Pending',
    
    -- Scope and Objectives
    `scope` TEXT,
    `objectives` JSON,
    `assessment_criteria` JSON,
    `methodology` TEXT,
    `sampling_rationale` TEXT,
    
    -- Next Steps
    `next_assessment_type` VARCHAR(50),
    `next_assessment_due` DATE,
    `certificate_recommendation` ENUM('Grant', 'Maintain', 'Suspend', 'Withdraw', 'None') DEFAULT 'None',
    
    -- Metadata
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE,
    FOREIGN KEY (`standard_id`) REFERENCES `standards`(`id`),
    FOREIGN KEY (`assessor_id`) REFERENCES `users`(`id`),
    
    INDEX `idx_scheduled_date` (`scheduled_date`),
    INDEX `idx_status` (`status`),
    INDEX `idx_supplier_assessments` (`supplier_id`)
) ENGINE=InnoDB;

-- Assessment Findings (Non-Conformities, Observations)
CREATE TABLE `findings` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `assessment_id` INT NOT NULL,
    `finding_number` VARCHAR(20),
    
    -- Classification
    `type` ENUM('Non_Conformity', 'Observation', 'Best_Practice', 'Opportunity') DEFAULT 'Non_Conformity',
    `severity` ENUM('Critical', 'Major', 'Minor') NOT NULL,
    `category` ENUM('System', 'Process', 'Product', 'Documentation', 'Resource', 'Other') DEFAULT 'System',
    
    -- Standard Reference
    `clause_reference` VARCHAR(50) NOT NULL,
    `requirement_text` TEXT,
    
    -- Finding Details
    `description` TEXT NOT NULL,
    `objective_evidence` TEXT,
    `root_cause_analysis` TEXT,
    `risk_assessment` TEXT,
    `immediate_action_required` TEXT,
    
    -- Risk and Impact
    `risk_level` ENUM('High', 'Medium', 'Low') DEFAULT 'Medium',
    `business_impact` TEXT,
    `regulatory_impact` BOOLEAN DEFAULT FALSE,
    
    -- Status Tracking
    `status` ENUM('Open', 'In_Progress', 'Awaiting_Verification', 'Closed', 'Overdue') DEFAULT 'Open',
    `target_close_date` DATE,
    `actual_close_date` DATE,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`assessment_id`) REFERENCES `assessments`(`id`) ON DELETE CASCADE,
    INDEX `idx_severity` (`severity`),
    INDEX `idx_status` (`status`)
) ENGINE=InnoDB;

-- Corrective and Preventive Actions (CAPA)
CREATE TABLE `corrective_actions` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `finding_id` INT NULL,
    `assessment_id` INT NULL,
    `supplier_id` INT NOT NULL,
    
    -- CAPA Details
    `capa_number` VARCHAR(50) UNIQUE,
    `title` VARCHAR(255) NOT NULL,
    `description` TEXT NOT NULL,
    `root_cause` TEXT,
    `corrective_action` TEXT,
    `preventive_action` TEXT,
    
    -- Assignment and Responsibility
    `assigned_to` VARCHAR(255),
    `assigned_contact` VARCHAR(255),
    `responsible_person` VARCHAR(255),
    
    -- Timeline
    `due_date` DATE NOT NULL,
    `planned_completion_date` DATE,
    `actual_completion_date` DATE NULL,
    `verification_date` DATE,
    
    -- Priority and Status
    `priority` ENUM('Critical', 'High', 'Medium', 'Low') DEFAULT 'Medium',
    `status` ENUM('Assigned', 'In_Progress', 'Completed', 'Verified', 'Overdue', 'Cancelled') DEFAULT 'Assigned',
    
    -- Effectiveness
    `effectiveness_review` TEXT,
    `verification_method` TEXT,
    `verification_evidence` TEXT,
    `effectiveness_confirmed` BOOLEAN DEFAULT FALSE,
    
    -- Cost and Resources
    `estimated_cost` DECIMAL(10,2),
    `actual_cost` DECIMAL(10,2),
    `resources_required` TEXT,
    
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`finding_id`) REFERENCES `findings`(`id`),
    FOREIGN KEY (`assessment_id`) REFERENCES `assessments`(`id`),
    FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE CASCADE,
    
    INDEX `idx_due_date` (`due_date`),
    INDEX `idx_status` (`status`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB;

-- =============================================
-- NOTIFICATIONS & WORKFLOW
-- =============================================

-- Notifications and Alerts
CREATE TABLE `notifications` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `type` ENUM('Certificate_Expiry', 'Assessment_Due', 'CAPA_Overdue', 'Document_Review', 'System_Alert') NOT NULL,
    `priority` ENUM('Critical', 'High', 'Medium', 'Low') DEFAULT 'Medium',
    `title` VARCHAR(255) NOT NULL,
    `message` TEXT NOT NULL,
    `data` JSON,
    `is_read` BOOLEAN DEFAULT FALSE,
    `action_required` BOOLEAN DEFAULT FALSE,
    `action_url` VARCHAR(500),
    `expires_at` DATETIME,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
    INDEX `idx_user_notifications` (`user_id`, `is_read`),
    INDEX `idx_priority` (`priority`)
) ENGINE=InnoDB;

-- Activity Logging
CREATE TABLE `activity_log` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT,
    `action` VARCHAR(100) NOT NULL,
    `resource` VARCHAR(100) NOT NULL,
    `resource_id` INT,
    `description` TEXT,
    `old_values` JSON,
    `new_values` JSON,
    `ip_address` VARCHAR(45),
    `user_agent` TEXT,
    `session_id` VARCHAR(100),
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`),
    INDEX `idx_created_at` (`created_at`),
    INDEX `idx_user_activity` (`user_id`),
    INDEX `idx_resource` (`resource`, `resource_id`)
) ENGINE=InnoDB;

-- =============================================
-- REPORTS & ANALYTICS
-- =============================================

-- Dashboard Widgets
CREATE TABLE `dashboard_widgets` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `user_id` INT NOT NULL,
    `widget_type` VARCHAR(50) NOT NULL,
    `title` VARCHAR(255) NOT NULL,
    `configuration` JSON,
    `position_x` INT DEFAULT 0,
    `position_y` INT DEFAULT 0,
    `width` INT DEFAULT 1,
    `height` INT DEFAULT 1,
    `is_visible` BOOLEAN DEFAULT TRUE,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    FOREIGN KEY (`user_id`) REFERENCES `users`(`id`)
) ENGINE=InnoDB;

-- =============================================
-- INSERT SAMPLE DATA
-- =============================================

-- Insert Admin User
INSERT INTO `users` (`username`, `email`, `password_hash`, `first_name`, `last_name`, `role`, `organization`) 
VALUES ('admin', 'salim@septona-bg.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Salim', 'Kuitov', 'admin', 'Septona Bulgaria JSC');

-- Insert Global Standards Library
INSERT INTO `standards` (`name`, `version`, `standard_type`, `industry_sector`, `description`) VALUES
('ISO 9001', '2015', 'ISO_9001', 'All Industries', 'Quality Management Systems - Requirements'),
('ISO 14001', '2015', 'ISO_14001', 'All Industries', 'Environmental Management Systems - Requirements with guidance for use'),
('ISO 45001', '2018', 'ISO_45001', 'All Industries', 'Occupational health and safety management systems - Requirements with guidance for use'),
('IATF 16949', '2016', 'IATF_16949', 'Automotive', 'Quality management system requirements for automotive production and relevant service parts organizations'),
('BRC Global Food', 'Issue 8', 'BRC', 'Food & Beverage', 'Global Standard for Food Safety'),
('IFS Food', 'Version 7', 'IFS', 'Food & Beverage', 'International Food Standard'),
('FSSC 22000', 'Version 5.1', 'FSSC_22000', 'Food & Beverage', 'Food Safety System Certification Scheme'),
('AS9100', 'Rev D', 'AS9100', 'Aerospace & Defense', 'Quality Management Systems - Requirements for Aviation, Space and Defense Organizations'),
('ISO 13485', '2016', 'ISO_13485', 'Medical Devices', 'Medical devices - Quality management systems - Requirements for regulatory purposes');

-- Insert Your Real Suppliers with ISO-Compliant Scoring
INSERT INTO `suppliers` (
    `name`, `supplier_type`, `product_categories`, `risk_category`, `iso_compliance_score`, 
    `delivery_score`, `price_score`, `quality_score`, `reliability_score`, 
    `technical_knowledge_score`, `equipment_efficiency_score`, `equipment_compatibility_score`,
    `status`, `next_audit_due`, `address_city`, `address_country`
) VALUES
('SICK AG', 'Direct', '["Електроника", "Автоматизация", "Sensors"]', 'A', 85.00, 8, 8, 9, 10, 10, 9, 9, 'Active', '2025-12-31', 'Ruse', 'Bulgaria'),
('FESTO', 'Direct', '["Пневматика", "Автоматизация", "Industrial Controls"]', 'A', 67.50, 7, 8, 8, 8, 8, 9, 10, 'Active', '2025-11-15', 'Ruse', 'Bulgaria'),
('SMC', 'Direct', '["Пневматика", "Автоматизация", "Actuators"]', 'A', 87.25, 8, 9, 9, 10, 10, 10, 9, 'Active', '2025-12-20', 'Ruse', 'Bulgaria'),
('kamatic', 'Direct', '["Пневматика", "Автоматизация"]', 'A', 85.00, 9, 9, 9, 9, 9, 9, 9, 'Active', '2025-11-30', 'Ruse', 'Bulgaria'),
('Industrial Parts', 'Direct', '["Индустриални компоненти"]', 'B', 82.00, 8, 7, 10, 10, 10, 9, 9, 'Active', '2026-01-15', 'Ruse', 'Bulgaria'),
('ТЕХНОМИКС ЕООД', 'Direct', '["Електронни компоненти", "Електрически компоненти"]', 'B', 84.25, 9, 9, 10, 9, 9, 9, 9, 'Active', '2026-02-01', 'Ruse', 'Bulgaria'),
('FILKAB', 'Direct', '["Електронни компоненти", "Електрически компоненти"]', 'B', 84.25, 9, 9, 9, 10, 10, 9, 9, 'Active', '2026-01-20', 'Ruse', 'Bulgaria'),
('ГЕКО', 'Direct', '["Механични части", "Консумативи"]', 'B', 87.50, 10, 8, 10, 10, 10, 9, 10, 'Active', '2025-12-10', 'Ruse', 'Bulgaria'),
('ТОМИС', 'Direct', '["Електронни компоненти"]', 'B', 87.50, 10, 9, 10, 9, 10, 9, 10, 'Active', '2025-12-25', 'Ruse', 'Bulgaria'),
('Bis Engineering Ltd', 'Direct', '["Механични части", "Консумативи"]', 'B', 86.75, 10, 6, 10, 10, 10, 9, 10, 'Active', '2025-11-25', 'Ruse', 'Bulgaria');

-- Insert Contact Information for Key Suppliers
INSERT INTO `contacts` (`supplier_id`, `name`, `position`, `email`, `phone`, `is_primary`) VALUES
(1, 'Димитър Михов', 'Regional Manager', 'dimitar.mihov@sick.ro', '+359 888 123 456', TRUE),
(2, 'Калин Стаматов', 'Sales Manager', 'kalin.stamatov@festo.com', '00359 885 172 087', TRUE),
(3, 'Георги Вичев', 'Sales Manager', 'g.vichev@smc.bg', '00359 884 371 385', TRUE),
(4, 'Симеон Станков', 'Technical Manager', 's.stankov@kamatic.com', '00359 884 727 882', TRUE),
(5, 'Виктория Свещарова', 'Account Manager', 'v.svestarova@industrial-parts.com', '00359 888 673 322', TRUE);

-- Insert Sample Supplier-Standard Relationships
INSERT INTO `supplier_standards` (`supplier_id`, `standard_id`, `certification_status`, `certificate_number`, `issuing_body`, `valid_from`, `valid_to`, `compliance_percentage`) VALUES
(2, 1, 'Certified', '12/100/64241', 'TÜV SÜD Management Service GmbH', '2022-11-12', '2025-11-12', 95.00),
(2, 2, 'Certified', '12/104/64241', 'TÜV SÜD Management Service GmbH', '2022-11-12', '2025-11-12', 95.00);

-- Create Performance Indexes
CREATE INDEX `idx_suppliers_performance` ON `suppliers`(`iso_compliance_score`, `risk_category`);
CREATE INDEX `idx_documents_expiry_alert` ON `documents`(`expiry_date`, `status`);
CREATE INDEX `idx_assessments_calendar` ON `assessments`(`scheduled_date`, `status`);
CREATE INDEX `idx_capa_tracking` ON `corrective_actions`(`due_date`, `status`, `priority`);

-- Create Views for Common Queries
CREATE VIEW `supplier_dashboard_view` AS
SELECT 
    s.id,
    s.name,
    s.risk_category,
    s.iso_compliance_score,
    s.status,
    s.next_audit_due,
    COUNT(DISTINCT d.id) as document_count,
    COUNT(DISTINCT CASE WHEN d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN d.id END) as expiring_docs,
    COUNT(DISTINCT ca.id) as open_capas
FROM suppliers s
LEFT JOIN documents d ON s.id = d.supplier_id AND d.status = 'Valid'
LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id AND ca.status IN ('Assigned', 'In_Progress')
GROUP BY s.id;

-- =============================================
-- STORED PROCEDURES FOR BUSINESS LOGIC
-- =============================================

DELIMITER //

-- Calculate Overall Supplier Risk Score
CREATE PROCEDURE CalculateSupplierRiskScore(IN supplier_id INT)
BEGIN
    DECLARE compliance_score DECIMAL(5,2);
    DECLARE risk_multiplier DECIMAL(3,2);
    DECLARE audit_overdue_penalty DECIMAL(3,2) DEFAULT 0;
    DECLARE capa_overdue_penalty DECIMAL(3,2) DEFAULT 0;
    DECLARE final_risk_score DECIMAL(5,2);
    
    -- Get current compliance score and risk category
    SELECT iso_compliance_score, 
           CASE risk_category 
               WHEN 'A' THEN 1.5 
               WHEN 'B' THEN 1.2 
               ELSE 1.0 
           END
    INTO compliance_score, risk_multiplier
    FROM suppliers WHERE id = supplier_id;
    
    -- Check for overdue audits
    IF (SELECT next_audit_due FROM suppliers WHERE id = supplier_id) < CURDATE() THEN
        SET audit_overdue_penalty = 10.0;
    END IF;
    
    -- Check for overdue CAPAs
    SET capa_overdue_penalty = (
        SELECT COUNT(*) * 2.0 
        FROM corrective_actions 
        WHERE supplier_id = supplier_id AND due_date < CURDATE() AND status IN ('Assigned', 'In_Progress')
        LIMIT 10
    );
    
    -- Calculate final risk score
    SET final_risk_score = GREATEST(0, 
        100 - (compliance_score * risk_multiplier) - audit_overdue_penalty - capa_overdue_penalty
    );
    
    -- Update supplier record
    UPDATE suppliers 
    SET overall_compliance_score = final_risk_score 
    WHERE id = supplier_id;
    
END //

DELIMITER ;

-- Grant necessary permissions
-- GRANT SELECT, INSERT, UPDATE, DELETE ON skuytove_supplierVault_db.* TO 'skuytove_dbuser'@'localhost';

COMMIT;