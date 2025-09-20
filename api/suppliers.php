<?php
// SupplierVault Pro - Suppliers API
// Targeting $57B Global Compliance Management Market
// File: api/suppliers.php

require_once '../config/database.php';

// Handle CORS and preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit(0);
}

try {
    $database = new Database();
    $db = $database->getConnection();
    $logger = new ActivityLogger($db);
    
    SessionManager::start();
    $userId = SessionManager::get('user_id', 1); // Default to admin
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['PATH_INFO'] ?? '';
    $pathParts = explode('/', trim($path, '/'));
    
    switch($method) {
        case 'GET':
            if (empty($pathParts[0])) {
                // List all suppliers with advanced filtering
                $query = "SELECT s.*, 
                         COUNT(DISTINCT d.id) as document_count,
                         COUNT(DISTINCT ca.id) as open_capa_count,
                         DATEDIFF(s.next_audit_due, CURDATE()) as days_until_audit
                         FROM suppliers s 
                         LEFT JOIN documents d ON s.id = d.supplier_id AND d.status = 'Valid'
                         LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id AND ca.status IN ('Assigned', 'In_Progress')
                         WHERE 1=1";
                
                $params = [];
                
                // Apply search filters
                if (isset($_GET['search']) && !empty($_GET['search'])) {
                    $search = '%' . $_GET['search'] . '%';
                    $query .= " AND (s.name LIKE ? OR s.product_categories LIKE ? OR s.business_sector LIKE ?)";
                    $params[] = $search;
                    $params[] = $search;
                    $params[] = $search;
                }
                
                if (isset($_GET['risk_category']) && $_GET['risk_category'] !== 'all') {
                    $query .= " AND s.risk_category = ?";
                    $params[] = $_GET['risk_category'];
                }
                
                if (isset($_GET['status']) && $_GET['status'] !== 'all') {
                    $query .= " AND s.status = ?";
                    $params[] = $_GET['status'];
                }
                
                if (isset($_GET['compliance_min'])) {
                    $query .= " AND s.iso_compliance_score >= ?";
                    $params[] = intval($_GET['compliance_min']);
                }
                
                // Audit due filter
                if (isset($_GET['audit_overdue']) && $_GET['audit_overdue'] === 'true') {
                    $query .= " AND s.next_audit_due < CURDATE()";
                }
                
                $query .= " GROUP BY s.id";
                
                // Sorting
                $sortColumn = $_GET['sort'] ?? 'name';
                $sortOrder = $_GET['order'] ?? 'ASC';
                
                $allowedSortColumns = ['name', 'risk_category', 'iso_compliance_score', 'next_audit_due', 'status'];
                if (in_array($sortColumn, $allowedSortColumns)) {
                    $query .= " ORDER BY s.{$sortColumn} {$sortOrder}";
                } else {
                    $query .= " ORDER BY s.name ASC";
                }
                
                // Pagination
                $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
                $offset = isset($_GET['offset']) ? intval($_GET['offset']) : 0;
                $query .= " LIMIT {$limit} OFFSET {$offset}";
                
                $stmt = $db->prepare($query);
                $stmt->execute($params);
                $suppliers = $stmt->fetchAll();
                
                // Enhanced supplier data processing
                foreach ($suppliers as &$supplier) {
                    $supplier['risk_level'] = calculateRiskLevel($supplier);
                    $supplier['compliance_status'] = getComplianceStatus($supplier['iso_compliance_score']);
                    $supplier['audit_status'] = getAuditStatus($supplier['days_until_audit']);
                    $supplier['product_categories'] = json_decode($supplier['product_categories'] ?? '[]', true);
                    $supplier['is_business_critical'] = (bool)$supplier['business_critical'];
                    $supplier['is_preferred_supplier'] = (bool)$supplier['preferred_supplier'];
                }
                
                ApiResponse::success($suppliers);
                
            } else {
                // Get specific supplier with detailed information
                $supplierId = intval($pathParts[0]);
                
                $stmt = $db->prepare("
                    SELECT s.*, 
                           COUNT(DISTINCT d.id) as document_count,
                           COUNT(DISTINCT a.id) as assessment_count,
                           COUNT(DISTINCT ca.id) as capa_count,
                           COUNT(DISTINCT ss.id) as certification_count
                    FROM suppliers s 
                    LEFT JOIN documents d ON s.id = d.supplier_id 
                    LEFT JOIN assessments a ON s.id = a.supplier_id
                    LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id
                    LEFT JOIN supplier_standards ss ON s.id = ss.supplier_id
                    WHERE s.id = ?
                    GROUP BY s.id
                ");
                
                $stmt->execute([$supplierId]);
                $supplier = $stmt->fetch();
                
                if (!$supplier) {
                    ApiResponse::error('Supplier not found', 404);
                }
                
                // Get supplier contacts
                $stmt = $db->prepare("
                    SELECT * FROM contacts 
                    WHERE supplier_id = ? 
                    ORDER BY is_primary DESC, name ASC
                ");
                $stmt->execute([$supplierId]);
                $supplier['contacts'] = $stmt->fetchAll();
                
                // Get supplier standards/certifications
                $stmt = $db->prepare("
                    SELECT ss.*, st.name as standard_name, st.standard_type, st.description
                    FROM supplier_standards ss
                    JOIN standards st ON ss.standard_id = st.id  
                    WHERE ss.supplier_id = ?
                    ORDER BY ss.certification_status DESC, st.name ASC
                ");
                $stmt->execute([$supplierId]);
                $supplier['certifications'] = $stmt->fetchAll();
                
                // Get recent documents
                $stmt = $db->prepare("
                    SELECT * FROM documents 
                    WHERE supplier_id = ? 
                    ORDER BY upload_date DESC 
                    LIMIT 10
                ");
                $stmt->execute([$supplierId]);
                $supplier['recent_documents'] = $stmt->fetchAll();
                
                // Get recent assessments
                $stmt = $db->prepare("
                    SELECT a.*, st.name as standard_name, u.first_name as assessor_name
                    FROM assessments a
                    JOIN standards st ON a.standard_id = st.id
                    LEFT JOIN users u ON a.assessor_id = u.id
                    WHERE a.supplier_id = ?
                    ORDER BY a.scheduled_date DESC
                    LIMIT 5
                ");
                $stmt->execute([$supplierId]);
                $supplier['recent_assessments'] = $stmt->fetchAll();
                
                // Get active CAPAs
                $stmt = $db->prepare("
                    SELECT * FROM corrective_actions
                    WHERE supplier_id = ? AND status IN ('Assigned', 'In_Progress')
                    ORDER BY priority DESC, due_date ASC
                    LIMIT 10
                ");
                $stmt->execute([$supplierId]);
                $supplier['active_capas'] = $stmt->fetchAll();
                
                // Process data
                $supplier['product_categories'] = json_decode($supplier['product_categories'] ?? '[]', true);
                $supplier['risk_level'] = calculateRiskLevel($supplier);
                $supplier['compliance_status'] = getComplianceStatus($supplier['iso_compliance_score']);
                
                ApiResponse::success($supplier);
            }
            break;
            
        case 'POST':
            // Create new supplier
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                ApiResponse::error('Invalid JSON data');
            }
            
            // Validate required fields
            $required = ['name', 'risk_category'];
            foreach ($required as $field) {
                if (empty($input[$field])) {
                    ApiResponse::error("Field '$field' is required");
                }
            }
            
            // Validate risk category
            if (!in_array($input['risk_category'], ['A', 'B', 'C'])) {
                ApiResponse::error('Risk category must be A, B, or C');
            }
            
            // Calculate next audit date based on risk
            $auditFrequency = [
                'A' => 6,  // High risk - 6 months
                'B' => 12, // Medium risk - 12 months  
                'C' => 24  // Low risk - 24 months
            ];
            
            $nextAudit = date('Y-m-d', strtotime('+' . $auditFrequency[$input['risk_category']] . ' months'));
            
            $stmt = $db->prepare("
                INSERT INTO suppliers 
                (name, legal_name, supplier_type, product_categories, business_sector,
                 risk_category, iso_compliance_score, delivery_score, price_score, 
                 quality_score, reliability_score, next_audit_due, audit_frequency_months,
                 annual_spend_eur, business_critical, preferred_supplier, status, created_by,
                 address_street, address_city, address_postal_code, address_country) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                        ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                Security::sanitizeInput($input['name']),
                Security::sanitizeInput($input['legal_name'] ?? ''),
                $input['supplier_type'] ?? 'Direct',
                json_encode($input['product_categories'] ?? []),
                Security::sanitizeInput($input['business_sector'] ?? ''),
                $input['risk_category'],
                floatval($input['iso_compliance_score'] ?? 0),
                intval($input['delivery_score'] ?? 0),
                intval($input['price_score'] ?? 0),
                intval($input['quality_score'] ?? 0),
                intval($input['reliability_score'] ?? 0),
                $nextAudit,
                $auditFrequency[$input['risk_category']],
                floatval($input['annual_spend_eur'] ?? 0),
                (bool)($input['business_critical'] ?? false),
                (bool)($input['preferred_supplier'] ?? false),
                'Active',
                $userId,
                Security::sanitizeInput($input['address_street'] ?? ''),
                Security::sanitizeInput($input['address_city'] ?? 'Ruse'),
                Security::sanitizeInput($input['address_postal_code'] ?? ''),
                Security::sanitizeInput($input['address_country'] ?? 'Bulgaria')
            ]);
            
            if ($result) {
                $supplierId = $db->lastInsertId();
                
                // Create primary contact if provided
                if (!empty($input['contact_name'])) {
                    $stmt = $db->prepare("
                        INSERT INTO contacts (supplier_id, name, email, phone, is_primary)
                        VALUES (?, ?, ?, ?, TRUE)
                    ");
                    $stmt->execute([
                        $supplierId,
                        Security::sanitizeInput($input['contact_name']),
                        Security::sanitizeInput($input['contact_email'] ?? ''),
                        Security::sanitizeInput($input['contact_phone'] ?? '')
                    ]);
                }
                
                // Create upload directories
                $uploadPath = '../uploads/suppliers/' . $supplierId;
                if (!is_dir($uploadPath)) {
                    mkdir($uploadPath . '/certificates', 0755, true);
                    mkdir($uploadPath . '/documents', 0755, true);
                    mkdir($uploadPath . '/assessments', 0755, true);
                }
                
                // Log activity
                $logger->log($userId, 'CREATE', 'supplier', $supplierId, null, $input);
                
                ApiResponse::success(['id' => $supplierId], 'Supplier created successfully', 201);
            } else {
                ApiResponse::error('Failed to create supplier');
            }
            break;
            
        case 'PUT':
            // Update supplier
            $supplierId = intval($pathParts[0]);
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                ApiResponse::error('Invalid JSON data');
            }
            
            // Get current supplier data for logging
            $stmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$supplierId]);
            $oldData = $stmt->fetch();
            
            if (!$oldData) {
                ApiResponse::error('Supplier not found', 404);
            }
            
            // Build update query dynamically
            $updateFields = [];
            $params = [];
            
            $allowedFields = [
                'name', 'legal_name', 'supplier_type', 'product_categories', 'business_sector',
                'risk_category', 'iso_compliance_score', 'delivery_score', 'price_score',
                'quality_score', 'reliability_score', 'annual_spend_eur', 'business_critical',
                'preferred_supplier', 'status', 'address_street', 'address_city',
                'address_postal_code', 'address_country', 'website', 'registration_number', 'tax_id'
            ];
            
            foreach ($allowedFields as $field) {
                if (isset($input[$field])) {
                    $updateFields[] = "$field = ?";
                    $value = $input[$field];
                    
                    if ($field === 'product_categories' && is_array($value)) {
                        $value = json_encode($value);
                    } else if (in_array($field, ['name', 'legal_name', 'business_sector', 'address_street', 'address_city', 'address_postal_code', 'address_country', 'website', 'registration_number', 'tax_id'])) {
                        $value = Security::sanitizeInput($value);
                    } else if (in_array($field, ['business_critical', 'preferred_supplier'])) {
                        $value = (bool)$value;
                    }
                    
                    $params[] = $value;
                }
            }
            
            if (empty($updateFields)) {
                ApiResponse::error('No fields to update');
            }
            
            $params[] = $supplierId;
            
            $query = "UPDATE suppliers SET " . implode(', ', $updateFields) . " WHERE id = ?";
            $stmt = $db->prepare($query);
            $result = $stmt->execute($params);
            
            if ($result) {
                // Log activity
                $logger->log($userId, 'UPDATE', 'supplier', $supplierId, $oldData, $input);
                
                ApiResponse::success(null, 'Supplier updated successfully');
            } else {
                ApiResponse::error('Failed to update supplier');
            }
            break;
            
        case 'DELETE':
            // Delete supplier (soft delete by setting status to 'Terminated')
            $supplierId = intval($pathParts[0]);
            
            // Get supplier data for logging
            $stmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
            $stmt->execute([$supplierId]);
            $supplier = $stmt->fetch();
            
            if (!$supplier) {
                ApiResponse::error('Supplier not found', 404);
            }
            
            // Soft delete - set status to Terminated
            $stmt = $db->prepare("UPDATE suppliers SET status = 'Terminated', updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $result = $stmt->execute([$supplierId]);
            
            if ($result) {
                // Log activity
                $logger->log($userId, 'DELETE', 'supplier', $supplierId, $supplier, null);
                
                ApiResponse::success(null, 'Supplier deactivated successfully');
            } else {
                ApiResponse::error('Failed to deactivate supplier');
            }
            break;
            
        default:
            ApiResponse::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log('Suppliers API Error: ' . $e->getMessage());
    ApiResponse::error('Internal server error: ' . $e->getMessage(), 500);
}

// Helper Functions for Business Logic
function calculateRiskLevel($supplier) {
    $score = floatval($supplier['iso_compliance_score']);
    $category = $supplier['risk_category'];
    $auditOverdue = $supplier['days_until_audit'] < 0;
    
    if ($category === 'A' || $score < 60 || $auditOverdue) {
        return 'High';
    } elseif ($category === 'B' || $score < 80) {
        return 'Medium';
    } else {
        return 'Low';
    }
}

function getComplianceStatus($score) {
    $score = floatval($score);
    if ($score >= 95) return 'Excellent';
    if ($score >= 85) return 'Good';
    if ($score >= 75) return 'Acceptable';
    if ($score >= 60) return 'Needs Improvement';
    return 'Critical';
}

function getAuditStatus($daysUntilAudit) {
    if ($daysUntilAudit < 0) return 'Overdue';
    if ($daysUntilAudit <= 30) return 'Due Soon';
    if ($daysUntilAudit <= 90) return 'Upcoming';
    return 'Scheduled';
}

function calculateSupplierScore($supplier) {
    // Weighted scoring algorithm based on multiple factors
    $scores = [
        'delivery' => floatval($supplier['delivery_score']) * 0.15,
        'price' => floatval($supplier['price_score']) * 0.10,
        'quality' => floatval($supplier['quality_score']) * 0.25,
        'reliability' => floatval($supplier['reliability_score']) * 0.20,
        'technical' => floatval($supplier['technical_knowledge_score']) * 0.15,
        'efficiency' => floatval($supplier['equipment_efficiency_score']) * 0.10,
        'compatibility' => floatval($supplier['equipment_compatibility_score']) * 0.05
    ];
    
    $totalScore = array_sum($scores);
    
    // Apply risk category modifier
    $riskMultiplier = [
        'A' => 0.85, // High risk gets penalty
        'B' => 0.95, // Medium risk gets small penalty
        'C' => 1.00  // Low risk no penalty
    ];
    
    $category = $supplier['risk_category'];
    $finalScore = $totalScore * ($riskMultiplier[$category] ?? 1.00);
    
    return round($finalScore, 2);
}

function updateSupplierCompliance($db, $supplierId) {
    // Recalculate overall compliance based on all certifications
    $stmt = $db->prepare("
        SELECT AVG(compliance_percentage) as avg_compliance
        FROM supplier_standards
        WHERE supplier_id = ? AND certification_status IN ('Certified', 'In_Progress')
    ");
    $stmt->execute([$supplierId]);
    $result = $stmt->fetch();
    
    if ($result && $result['avg_compliance'] !== null) {
        $compliance = round($result['avg_compliance'], 2);
        
        $stmt = $db->prepare("
            UPDATE suppliers 
            SET overall_compliance_score = ?, iso_compliance_score = ?
            WHERE id = ?
        ");
        $stmt->execute([$compliance, $compliance, $supplierId]);
    }
}
?>