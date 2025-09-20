<?php
// SupplierVault Pro - Advanced Reports & Export API
// File: api/reports.php
// Supports Excel, PDF, CSV exports with interactive statistics

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
    
    SessionManager::start();
    $userId = SessionManager::get('user_id', 1);
    
    $method = $_SERVER['REQUEST_METHOD'];
    $path = $_SERVER['PATH_INFO'] ?? '';
    $pathParts = explode('/', trim($path, '/'));
    
    switch($method) {
        case 'GET':
            if (empty($pathParts[0])) {
                // Get available reports list
                $reports = [
                    'suppliers' => [
                        'name' => 'Supplier Compliance Report',
                        'description' => 'Complete supplier database with compliance scores',
                        'formats' => ['excel', 'pdf', 'csv'],
                        'parameters' => ['risk_category', 'status', 'date_range']
                    ],
                    'compliance' => [
                        'name' => 'Compliance Analytics Report',
                        'description' => 'Detailed compliance analysis and trends',
                        'formats' => ['excel', 'pdf'],
                        'parameters' => ['standard_type', 'date_range', 'include_trends']
                    ],
                    'certificates' => [
                        'name' => 'Certificate Expiry Report',
                        'description' => 'Certificates expiring within specified period',
                        'formats' => ['excel', 'pdf', 'csv'],
                        'parameters' => ['days_ahead', 'document_type', 'supplier_id']
                    ],
                    'assessments' => [
                        'name' => 'Assessment Summary Report',
                        'description' => 'Assessment results and findings analysis',
                        'formats' => ['excel', 'pdf'],
                        'parameters' => ['date_range', 'assessment_type', 'include_findings']
                    ],
                    'risk' => [
                        'name' => 'Risk Analysis Report',
                        'description' => 'Comprehensive risk assessment by category',
                        'formats' => ['excel', 'pdf'],
                        'parameters' => ['include_predictions', 'include_mitigation']
                    ],
                    'executive' => [
                        'name' => 'Executive Dashboard Report',
                        'description' => 'High-level KPIs and executive summary',
                        'formats' => ['pdf', 'excel'],
                        'parameters' => ['date_range', 'include_charts']
                    ]
                ];
                
                ApiResponse::success($reports);
                
            } elseif ($pathParts[0] === 'generate') {
                // Generate specific report
                $reportType = $_GET['type'] ?? '';
                $format = $_GET['format'] ?? 'pdf';
                
                if (!$reportType) {
                    ApiResponse::error('Report type is required');
                }
                
                $reportData = generateReport($db, $reportType, $_GET);
                
                switch($format) {
                    case 'csv':
                        exportCSV($reportData, $reportType);
                        break;
                    case 'excel':
                        exportExcel($reportData, $reportType);
                        break;
                    case 'pdf':
                        exportPDF($reportData, $reportType);
                        break;
                    default:
                        ApiResponse::error('Unsupported format');
                }
                
            } elseif ($pathParts[0] === 'statistics') {
                // Get interactive statistics
                $statsType = $_GET['type'] ?? 'overview';
                $stats = getInteractiveStatistics($db, $statsType, $_GET);
                ApiResponse::success($stats);
                
            } elseif ($pathParts[0] === 'analytics') {
                // Get advanced analytics data
                $analyticsType = $_GET['type'] ?? 'compliance';
                $analytics = getAdvancedAnalytics($db, $analyticsType, $_GET);
                ApiResponse::success($analytics);
                
            } else {
                ApiResponse::error('Invalid endpoint');
            }
            break;
            
        case 'POST':
            // Create custom report template
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                ApiResponse::error('Invalid JSON data');
            }
            
            $stmt = $db->prepare("
                INSERT INTO report_templates 
                (name, description, report_type, parameters, filters, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                Security::sanitizeInput($input['name']),
                Security::sanitizeInput($input['description']),
                $input['report_type'],
                json_encode($input['parameters'] ?? []),
                json_encode($input['filters'] ?? []),
                $userId
            ]);
            
            if ($result) {
                ApiResponse::success(['id' => $db->lastInsertId()], 'Report template created successfully', 201);
            } else {
                ApiResponse::error('Failed to create report template');
            }
            break;
            
        default:
            ApiResponse::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log('Reports API Error: ' . $e->getMessage());
    ApiResponse::error('Internal server error: ' . $e->getMessage(), 500);
}

// ==================== REPORT GENERATION FUNCTIONS ====================

function generateReport($db, $reportType, $params) {
    switch($reportType) {
        case 'suppliers':
            return generateSuppliersReport($db, $params);
        case 'compliance':
            return generateComplianceReport($db, $params);
        case 'certificates':
            return generateCertificatesReport($db, $params);
        case 'assessments':
            return generateAssessmentsReport($db, $params);
        case 'risk':
            return generateRiskReport($db, $params);
        case 'executive':
            return generateExecutiveReport($db, $params);
        default:
            throw new Exception('Unknown report type');
    }
}

function generateSuppliersReport($db, $params) {
    $query = "
        SELECT 
            s.id,
            s.name,
            s.legal_name,
            s.supplier_type,
            s.risk_category,
            s.iso_compliance_score,
            s.overall_compliance_score,
            s.status,
            s.annual_spend_eur,
            s.business_critical,
            s.preferred_supplier,
            s.next_audit_due,
            s.address_city,
            s.address_country,
            s.created_at,
            COUNT(DISTINCT d.id) as document_count,
            COUNT(DISTINCT ss.id) as certification_count,
            COUNT(DISTINCT ca.id) as open_capa_count,
            GROUP_CONCAT(DISTINCT c.name) as contacts,
            GROUP_CONCAT(DISTINCT c.email) as contact_emails
        FROM suppliers s
        LEFT JOIN documents d ON s.id = d.supplier_id AND d.status = 'Valid'
        LEFT JOIN supplier_standards ss ON s.id = ss.supplier_id
        LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id AND ca.status IN ('Assigned', 'In_Progress')
        LEFT JOIN contacts c ON s.id = c.supplier_id AND c.is_primary = TRUE
        WHERE 1=1
    ";
    
    $queryParams = [];
    
    // Apply filters
    if (!empty($params['risk_category']) && $params['risk_category'] !== 'all') {
        $query .= " AND s.risk_category = ?";
        $queryParams[] = $params['risk_category'];
    }
    
    if (!empty($params['status']) && $params['status'] !== 'all') {
        $query .= " AND s.status = ?";
        $queryParams[] = $params['status'];
    }
    
    if (!empty($params['date_from'])) {
        $query .= " AND s.created_at >= ?";
        $queryParams[] = $params['date_from'];
    }
    
    if (!empty($params['date_to'])) {
        $query .= " AND s.created_at <= ?";
        $queryParams[] = $params['date_to'];
    }
    
    $query .= " GROUP BY s.id ORDER BY s.iso_compliance_score DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($queryParams);
    $suppliers = $stmt->fetchAll();
    
    // Add calculated fields
    foreach ($suppliers as &$supplier) {
        $supplier['compliance_status'] = getComplianceStatus($supplier['iso_compliance_score']);
        $supplier['risk_level'] = calculateRiskLevel($supplier);
        $supplier['days_until_audit'] = calculateDaysUntilDate($supplier['next_audit_due']);
        $supplier['product_categories'] = json_decode($supplier['product_categories'] ?? '[]', true);
    }
    
    return [
        'title' => 'Supplier Compliance Report',
        'generated_at' => date('Y-m-d H:i:s'),
        'parameters' => $params,
        'data' => $suppliers,
        'summary' => calculateSupplierSummary($suppliers)
    ];
}

function generateComplianceReport($db, $params) {
    // Compliance trends over time
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            AVG(iso_compliance_score) as avg_compliance,
            COUNT(*) as supplier_count,
            COUNT(CASE WHEN iso_compliance_score >= 80 THEN 1 END) as compliant_count
        FROM suppliers 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month
    ");
    $stmt->execute();
    $trends = $stmt->fetchAll();
    
    // Compliance by standard
    $stmt = $db->prepare("
        SELECT 
            st.name as standard_name,
            st.standard_type,
            COUNT(ss.id) as total_suppliers,
            COUNT(CASE WHEN ss.certification_status = 'Certified' THEN 1 END) as certified_count,
            COUNT(CASE WHEN ss.certification_status = 'Expired' THEN 1 END) as expired_count,
            AVG(CASE WHEN ss.certification_status = 'Certified' THEN ss.compliance_percentage END) as avg_compliance
        FROM standards st
        LEFT JOIN supplier_standards ss ON st.id = ss.standard_id
        WHERE st.is_active = TRUE
        GROUP BY st.id, st.name, st.standard_type
        ORDER BY certified_count DESC
    ");
    $stmt->execute();
    $standards = $stmt->fetchAll();
    
    // Non-conformities analysis
    $stmt = $db->prepare("
        SELECT 
            f.severity,
            f.category,
            COUNT(*) as count,
            COUNT(CASE WHEN f.status = 'Open' THEN 1 END) as open_count
        FROM findings f
        JOIN assessments a ON f.assessment_id = a.id
        WHERE a.completed_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY f.severity, f.category
        ORDER BY f.severity, count DESC
    ");
    $stmt->execute();
    $findings = $stmt->fetchAll();
    
    return [
        'title' => 'Compliance Analytics Report',
        'generated_at' => date('Y-m-d H:i:s'),
        'parameters' => $params,
        'trends' => $trends,
        'standards_compliance' => $standards,
        'findings_analysis' => $findings,
        'summary' => calculateComplianceSummary($trends, $standards, $findings)
    ];
}

function generateCertificatesReport($db, $params) {
    $daysAhead = intval($params['days_ahead'] ?? 90);
    
    $query = "
        SELECT 
            d.id,
            d.name as document_name,
            d.type,
            d.issue_date,
            d.expiry_date,
            d.status,
            s.name as supplier_name,
            s.risk_category,
            st.name as standard_name,
            DATEDIFF(d.expiry_date, CURDATE()) as days_until_expiry,
            CASE 
                WHEN d.expiry_date < CURDATE() THEN 'Expired'
                WHEN DATEDIFF(d.expiry_date, CURDATE()) <= 30 THEN 'Critical'
                WHEN DATEDIFF(d.expiry_date, CURDATE()) <= 90 THEN 'Warning'
                ELSE 'OK'
            END as expiry_status
        FROM documents d
        JOIN suppliers s ON d.supplier_id = s.id
        LEFT JOIN standards st ON d.standard_id = st.id
        WHERE d.expiry_date IS NOT NULL
        AND d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
        AND s.status = 'Active'
    ";
    
    $queryParams = [$daysAhead];
    
    if (!empty($params['document_type']) && $params['document_type'] !== 'all') {
        $query .= " AND d.type = ?";
        $queryParams[] = $params['document_type'];
    }
    
    if (!empty($params['supplier_id'])) {
        $query .= " AND s.id = ?";
        $queryParams[] = $params['supplier_id'];
    }
    
    $query .= " ORDER BY d.expiry_date ASC, s.risk_category DESC";
    
    $stmt = $db->prepare($query);
    $stmt->execute($queryParams);
    $certificates = $stmt->fetchAll();
    
    return [
        'title' => 'Certificate Expiry Report',
        'generated_at' => date('Y-m-d H:i:s'),
        'parameters' => $params,
        'data' => $certificates,
        'summary' => calculateCertificateSummary($certificates)
    ];
}

function generateRiskReport($db, $params) {
    // Risk distribution
    $stmt = $db->prepare("
        SELECT 
            risk_category,
            COUNT(*) as supplier_count,
            AVG(iso_compliance_score) as avg_compliance,
            AVG(annual_spend_eur) as avg_spend,
            COUNT(CASE WHEN business_critical = 1 THEN 1 END) as critical_suppliers
        FROM suppliers 
        WHERE status = 'Active'
        GROUP BY risk_category
        ORDER BY risk_category
    ");
    $stmt->execute();
    $riskDistribution = $stmt->fetchAll();
    
    // High-risk suppliers detailed analysis
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.name,
            s.risk_category,
            s.iso_compliance_score,
            s.annual_spend_eur,
            s.business_critical,
            s.next_audit_due,
            COUNT(DISTINCT ca.id) as open_capas,
            COUNT(DISTINCT CASE WHEN d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN d.id END) as expiring_docs,
            DATEDIFF(s.next_audit_due, CURDATE()) as days_until_audit
        FROM suppliers s
        LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id AND ca.status IN ('Assigned', 'In_Progress')
        LEFT JOIN documents d ON s.id = d.supplier_id AND d.status = 'Valid'
        WHERE s.status = 'Active'
        AND (s.risk_category = 'A' OR s.iso_compliance_score < 75 OR s.next_audit_due < DATE_ADD(CURDATE(), INTERVAL 30 DAY))
        GROUP BY s.id
        ORDER BY s.risk_category, s.iso_compliance_score ASC
    ");
    $stmt->execute();
    $highRiskSuppliers = $stmt->fetchAll();
    
    // Risk mitigation recommendations
    $recommendations = generateRiskRecommendations($highRiskSuppliers);
    
    return [
        'title' => 'Risk Analysis Report',
        'generated_at' => date('Y-m-d H:i:s'),
        'parameters' => $params,
        'risk_distribution' => $riskDistribution,
        'high_risk_suppliers' => $highRiskSuppliers,
        'recommendations' => $recommendations,
        'summary' => calculateRiskSummary($riskDistribution, $highRiskSuppliers)
    ];
}

// ==================== EXPORT FUNCTIONS ====================

function exportCSV($reportData, $reportType) {
    $filename = $reportType . '_report_' . date('Y-m-d_H-i-s') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Expires: 0');
    header('Access-Control-Allow-Origin: *');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for proper UTF-8 handling in Excel
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    if (!empty($reportData['data'])) {
        $data = $reportData['data'];
        
        // Write headers
        if (!empty($data[0])) {
            fputcsv($output, array_keys($data[0]));
        }
        
        // Write data
        foreach ($data as $row) {
            // Convert arrays to strings
            foreach ($row as $key => $value) {
                if (is_array($value)) {
                    $row[$key] = implode(', ', $value);
                }
            }
            fputcsv($output, $row);
        }
    }
    
    fclose($output);
    exit;
}

function exportExcel($reportData, $reportType) {
    // Use PhpSpreadsheet for Excel export
    require_once '../vendor/autoload.php'; // Composer autoload
    
    use PhpOffice\PhpSpreadsheet\Spreadsheet;
    use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
    use PhpOffice\PhpSpreadsheet\Style\Color;
    use PhpOffice\PhpSpreadsheet\Style\Fill;
    
    $spreadsheet = new Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    
    // Set title
    $sheet->setTitle($reportData['title']);
    $sheet->setCellValue('A1', $reportData['title']);
    $sheet->setCellValue('A2', 'Generated: ' . $reportData['generated_at']);
    $sheet->setCellValue('A3', 'SupplierVault Pro - Enterprise Compliance Management');
    
    // Style header
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);
    $sheet->getStyle('A1:A3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('4472C4');
    $sheet->getStyle('A1:A3')->getFont()->getColor()->setRGB('FFFFFF');
    
    $currentRow = 5;
    
    if (!empty($reportData['data'])) {
        $data = $reportData['data'];
        
        if (!empty($data[0])) {
            $headers = array_keys($data[0]);
            $col = 'A';
            
            // Write headers
            foreach ($headers as $header) {
                $sheet->setCellValue($col . $currentRow, ucwords(str_replace('_', ' ', $header)));
                $sheet->getStyle($col . $currentRow)->getFont()->setBold(true);
                $sheet->getStyle($col . $currentRow)->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('D9E2F3');
                $col++;
            }
            
            $currentRow++;
            
            // Write data
            foreach ($data as $row) {
                $col = 'A';
                foreach ($row as $value) {
                    if (is_array($value)) {
                        $value = implode(', ', $value);
                    }
                    $sheet->setCellValue($col . $currentRow, $value);
                    $col++;
                }
                $currentRow++;
            }
            
            // Auto-size columns
            foreach (range('A', $col) as $columnID) {
                $sheet->getColumnDimension($columnID)->setAutoSize(true);
            }
        }
    }
    
    // Add summary sheet if available
    if (!empty($reportData['summary'])) {
        $summarySheet = $spreadsheet->createSheet();
        $summarySheet->setTitle('Summary');
        
        $row = 1;
        foreach ($reportData['summary'] as $key => $value) {
            $summarySheet->setCellValue('A' . $row, ucwords(str_replace('_', ' ', $key)));
            $summarySheet->setCellValue('B' . $row, is_array($value) ? json_encode($value) : $value);
            $row++;
        }
        
        $summarySheet->getColumnDimension('A')->setAutoSize(true);
        $summarySheet->getColumnDimension('B')->setAutoSize(true);
    }
    
    $filename = $reportType . '_report_' . date('Y-m-d_H-i-s') . '.xlsx';
    
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    header('Access-Control-Allow-Origin: *');
    
    $writer = new Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

function exportPDF($reportData, $reportType) {
    // Use TCPDF for PDF generation
    require_once '../vendor/tcpdf/tcpdf.php';
    
    $pdf = new TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true, 'UTF-8', false);
    
    // Set document information
    $pdf->SetCreator('SupplierVault Pro');
    $pdf->SetAuthor('Septona Bulgaria JSC');
    $pdf->SetTitle($reportData['title']);
    $pdf->SetSubject('Supplier Compliance Report');
    
    // Set header and footer
    $pdf->SetHeaderData('', 0, 'SupplierVault Pro', $reportData['title'] . ' - Generated: ' . $reportData['generated_at']);
    $pdf->setFooterData([0,64,0], [0,64,128]);
    
    // Set margins
    $pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);
    $pdf->SetHeaderMargin(PDF_MARGIN_HEADER);
    $pdf->SetFooterMargin(PDF_MARGIN_FOOTER);
    
    // Set auto page breaks
    $pdf->SetAutoPageBreak(TRUE, PDF_MARGIN_BOTTOM);
    
    // Add a page
    $pdf->AddPage();
    
    // Set font
    $pdf->SetFont('helvetica', '', 12);
    
    // Generate content based on report type
    $html = generatePDFContent($reportData, $reportType);
    
    // Write HTML content
    $pdf->writeHTML($html, true, false, true, false, '');
    
    $filename = $reportType . '_report_' . date('Y-m-d_H-i-s') . '.pdf';
    
    header('Access-Control-Allow-Origin: *');
    $pdf->Output($filename, 'D');
    exit;
}

// ==================== STATISTICS FUNCTIONS ====================

function getInteractiveStatistics($db, $type, $params) {
    switch($type) {
        case 'overview':
            return getOverviewStatistics($db, $params);
        case 'compliance':
            return getComplianceStatistics($db, $params);
        case 'risk':
            return getRiskStatistics($db, $params);
        case 'performance':
            return getPerformanceStatistics($db, $params);
        case 'trends':
            return getTrendStatistics($db, $params);
        default:
            return [];
    }
}

function getOverviewStatistics($db, $params) {
    // Get comprehensive overview statistics
    $stats = [];
    
    // Supplier metrics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_suppliers,
            COUNT(CASE WHEN status = 'Active' THEN 1 END) as active_suppliers,
            COUNT(CASE WHEN risk_category = 'A' THEN 1 END) as high_risk,
            COUNT(CASE WHEN risk_category = 'B' THEN 1 END) as medium_risk,
            COUNT(CASE WHEN risk_category = 'C' THEN 1 END) as low_risk,
            COUNT(CASE WHEN business_critical = 1 THEN 1 END) as critical_suppliers,
            COUNT(CASE WHEN preferred_supplier = 1 THEN 1 END) as preferred_suppliers,
            AVG(iso_compliance_score) as avg_compliance,
            SUM(annual_spend_eur) as total_spend
        FROM suppliers
    ");
    $stmt->execute();
    $supplierStats = $stmt->fetch();
    
    // Document metrics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_documents,
            COUNT(CASE WHEN status = 'Valid' THEN 1 END) as valid_documents,
            COUNT(CASE WHEN type = 'Certificate' THEN 1 END) as certificates,
            COUNT(CASE WHEN expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND status = 'Valid' THEN 1 END) as expiring_soon
        FROM documents
    ");
    $stmt->execute();
    $documentStats = $stmt->fetch();
    
    // Assessment metrics
    $stmt = $db->prepare("
        SELECT 
            COUNT(*) as total_assessments,
            COUNT(CASE WHEN status = 'Completed' THEN 1 END) as completed,
            COUNT(CASE WHEN status = 'Scheduled' THEN 1 END) as scheduled,
            AVG(CASE WHEN status = 'Completed' THEN compliance_percentage END) as avg_score
        FROM assessments
    ");
    $stmt->execute();
    $assessmentStats = $stmt->fetch();
    
    return [
        'suppliers' => $supplierStats,
        'documents' => $documentStats,
        'assessments' => $assessmentStats,
        'kpis' => [
            'compliance_rate' => round(($supplierStats['avg_compliance'] ?? 0), 1),
            'risk_exposure' => round((($supplierStats['high_risk'] ?? 0) / max($supplierStats['total_suppliers'], 1)) * 100, 1),
            'document_health' => round((($documentStats['valid_documents'] ?? 0) / max($documentStats['total_documents'], 1)) * 100, 1),
            'assessment_completion' => round((($assessmentStats['completed'] ?? 0) / max($assessmentStats['total_assessments'], 1)) * 100, 1)
        ]
    ];
}

// ==================== HELPER FUNCTIONS ====================

function calculateDaysUntilDate($date) {
    if (!$date) return null;
    $diff = strtotime($date) - strtotime(date('Y-m-d'));
    return intval($diff / (60 * 60 * 24));
}

function getComplianceStatus($score) {
    if ($score >= 90) return 'Excellent';
    if ($score >= 80) return 'Good';
    if ($score >= 70) return 'Acceptable';
    if ($score >= 50) return 'Needs Improvement';
    return 'Critical';
}

function calculateRiskLevel($supplier) {
    $score = floatval($supplier['iso_compliance_score']);
    $category = $supplier['risk_category'];
    
    if ($category === 'A' || $score < 60) return 'High';
    if ($category === 'B' || $score < 80) return 'Medium';
    return 'Low';
}

function generatePDFContent($reportData, $reportType) {
    $html = '<h1>' . $reportData['title'] . '</h1>';
    $html .= '<p><strong>Generated:</strong> ' . $reportData['generated_at'] . '</p>';
    
    if (!empty($reportData['summary'])) {
        $html .= '<h2>Executive Summary</h2>';
        foreach ($reportData['summary'] as $key => $value) {
            $html .= '<p><strong>' . ucwords(str_replace('_', ' ', $key)) . ':</strong> ' . 
                     (is_array($value) ? implode(', ', $value) : $value) . '</p>';
        }
    }
    
    if (!empty($reportData['data'])) {
        $html .= '<h2>Detailed Data</h2>';
        $html .= '<table border="1" cellspacing="0" cellpadding="4">';
        
        // Headers
        $data = $reportData['data'];
        if (!empty($data[0])) {
            $html .= '<thead><tr>';
            foreach (array_keys($data[0]) as $header) {
                $html .= '<th>' . ucwords(str_replace('_', ' ', $header)) . '</th>';
            }
            $html .= '</tr></thead>';
        }
        
        // Data rows
        $html .= '<tbody>';
        foreach ($data as $row) {
            $html .= '<tr>';
            foreach ($row as $value) {
                if (is_array($value)) {
                    $value = implode(', ', $value);
                }
                $html .= '<td>' . htmlspecialchars($value) . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table>';
    }
    
    return $html;
}
?>