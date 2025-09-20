<?php
// SupplierVault Pro - Interactive Analytics & Statistics API
// File: api/analytics.php
// Advanced statistics with real-time interactive capabilities

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
                // Get available analytics endpoints
                $endpoints = [
                    'compliance-trends' => 'Historical compliance performance trends',
                    'risk-analysis' => 'Risk distribution and heat map analysis',
                    'supplier-performance' => 'Individual supplier performance metrics',
                    'certificate-analytics' => 'Certificate and document analytics',
                    'assessment-insights' => 'Assessment results and patterns',
                    'spend-analysis' => 'Supplier spend analysis by category',
                    'predictive' => 'Predictive analytics and forecasting',
                    'benchmarking' => 'Industry benchmarking data',
                    'realtime' => 'Real-time dashboard metrics'
                ];
                
                ApiResponse::success($endpoints);
                
            } else {
                $analyticsType = $pathParts[0];
                $data = generateAnalytics($db, $analyticsType, $_GET);
                ApiResponse::success($data);
            }
            break;
            
        case 'POST':
            // Save custom analytics dashboard
            $input = json_decode(file_get_contents('php://input'), true);
            
            if (!$input) {
                ApiResponse::error('Invalid JSON data');
            }
            
            $stmt = $db->prepare("
                INSERT INTO analytics_dashboards 
                (name, description, widgets, layout, filters, created_by) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $result = $stmt->execute([
                Security::sanitizeInput($input['name']),
                Security::sanitizeInput($input['description']),
                json_encode($input['widgets'] ?? []),
                json_encode($input['layout'] ?? []),
                json_encode($input['filters'] ?? []),
                $userId
            ]);
            
            if ($result) {
                ApiResponse::success(['id' => $db->lastInsertId()], 'Analytics dashboard saved', 201);
            } else {
                ApiResponse::error('Failed to save analytics dashboard');
            }
            break;
            
        default:
            ApiResponse::error('Method not allowed', 405);
    }
    
} catch (Exception $e) {
    error_log('Analytics API Error: ' . $e->getMessage());
    ApiResponse::error('Internal server error: ' . $e->getMessage(), 500);
}

// ==================== ANALYTICS GENERATION FUNCTIONS ====================

function generateAnalytics($db, $type, $params) {
    switch($type) {
        case 'compliance-trends':
            return getComplianceTrends($db, $params);
        case 'risk-analysis':
            return getRiskAnalysis($db, $params);
        case 'supplier-performance':
            return getSupplierPerformance($db, $params);
        case 'certificate-analytics':
            return getCertificateAnalytics($db, $params);
        case 'assessment-insights':
            return getAssessmentInsights($db, $params);
        case 'spend-analysis':
            return getSpendAnalysis($db, $params);
        case 'predictive':
            return getPredictiveAnalytics($db, $params);
        case 'benchmarking':
            return getBenchmarkingData($db, $params);
        case 'realtime':
            return getRealtimeMetrics($db, $params);
        default:
            throw new Exception('Unknown analytics type');
    }
}

function getComplianceTrends($db, $params) {
    $months = intval($params['months'] ?? 12);
    $granularity = $params['granularity'] ?? 'month'; // day, week, month, quarter
    
    // Historical compliance trends
    $dateFormat = match($granularity) {
        'day' => '%Y-%m-%d',
        'week' => '%Y-%u',
        'month' => '%Y-%m',
        'quarter' => '%Y-Q%q',
        default => '%Y-%m'
    };
    
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(updated_at, ?) as period,
            AVG(iso_compliance_score) as avg_compliance,
            COUNT(*) as supplier_count,
            COUNT(CASE WHEN iso_compliance_score >= 80 THEN 1 END) as compliant_count,
            MIN(iso_compliance_score) as min_compliance,
            MAX(iso_compliance_score) as max_compliance,
            STDDEV(iso_compliance_score) as compliance_std
        FROM suppliers 
        WHERE updated_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        AND status = 'Active'
        GROUP BY DATE_FORMAT(updated_at, ?)
        ORDER BY period
    ");
    $stmt->execute([$dateFormat, $months, $dateFormat]);
    $trends = $stmt->fetchAll();
    
    // Compliance by risk category trends
    $stmt = $db->prepare("
        SELECT 
            DATE_FORMAT(updated_at, ?) as period,
            risk_category,
            AVG(iso_compliance_score) as avg_compliance,
            COUNT(*) as count
        FROM suppliers 
        WHERE updated_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        AND status = 'Active'
        GROUP BY DATE_FORMAT(updated_at, ?), risk_category
        ORDER BY period, risk_category
    ");
    $stmt->execute([$dateFormat, $months, $dateFormat]);
    $categoryTrends = $stmt->fetchAll();
    
    // Compliance improvement/decline analysis
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.name,
            s.risk_category,
            s.iso_compliance_score as current_score,
            LAG(s.iso_compliance_score) OVER (PARTITION BY s.id ORDER BY s.updated_at) as previous_score,
            s.iso_compliance_score - LAG(s.iso_compliance_score) OVER (PARTITION BY s.id ORDER BY s.updated_at) as score_change
        FROM suppliers s
        WHERE s.updated_at >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
        AND s.status = 'Active'
        ORDER BY score_change DESC
    ");
    $stmt->execute([$months]);
    $scoreChanges = $stmt->fetchAll();
    
    return [
        'trends' => $trends,
        'category_trends' => $categoryTrends,
        'score_changes' => $scoreChanges,
        'statistics' => [
            'overall_trend' => calculateTrendDirection($trends),
            'volatility' => calculateVolatility($trends),
            'seasonal_patterns' => detectSeasonalPatterns($trends)
        ]
    ];
}

function getRiskAnalysis($db, $params) {
    // Risk heat map data
    $stmt = $db->prepare("
        SELECT 
            s.risk_category,
            s.supplier_type,
            COUNT(*) as supplier_count,
            AVG(s.iso_compliance_score) as avg_compliance,
            AVG(s.annual_spend_eur) as avg_spend,
            SUM(s.annual_spend_eur) as total_spend,
            COUNT(CASE WHEN s.business_critical = 1 THEN 1 END) as critical_count,
            COUNT(CASE WHEN s.next_audit_due < CURDATE() THEN 1 END) as overdue_audits
        FROM suppliers s
        WHERE s.status = 'Active'
        GROUP BY s.risk_category, s.supplier_type
        ORDER BY s.risk_category, s.supplier_type
    ");
    $stmt->execute();
    $riskMatrix = $stmt->fetchAll();
    
    // Risk factors correlation analysis
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.name,
            s.risk_category,
            s.iso_compliance_score,
            s.annual_spend_eur,
            s.business_critical,
            DATEDIFF(s.next_audit_due, CURDATE()) as days_until_audit,
            COUNT(DISTINCT ca.id) as open_capas,
            COUNT(DISTINCT d.id) as expired_docs,
            COUNT(DISTINCT f.id) as critical_findings
        FROM suppliers s
        LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id AND ca.status IN ('Assigned', 'In_Progress')
        LEFT JOIN documents d ON s.id = d.supplier_id AND d.status = 'Expired'
        LEFT JOIN assessments a ON s.id = a.supplier_id
        LEFT JOIN findings f ON a.id = f.assessment_id AND f.severity = 'Critical' AND f.status = 'Open'
        WHERE s.status = 'Active'
        GROUP BY s.id
        ORDER BY s.risk_category, s.iso_compliance_score
    ");
    $stmt->execute();
    $riskFactors = $stmt->fetchAll();
    
    // Geographic risk distribution
    $stmt = $db->prepare("
        SELECT 
            s.address_country,
            s.address_city,
            COUNT(*) as supplier_count,
            AVG(s.iso_compliance_score) as avg_compliance,
            SUM(s.annual_spend_eur) as total_spend
        FROM suppliers s
        WHERE s.status = 'Active'
        GROUP BY s.address_country, s.address_city
        ORDER BY total_spend DESC
    ");
    $stmt->execute();
    $geoRisk = $stmt->fetchAll();
    
    return [
        'risk_matrix' => $riskMatrix,
        'risk_factors' => $riskFactors,
        'geographic_risk' => $geoRisk,
        'risk_scores' => calculateRiskScores($riskFactors),
        'mitigation_priorities' => identifyMitigationPriorities($riskFactors)
    ];
}

function getSupplierPerformance($db, $params) {
    $supplierId = $params['supplier_id'] ?? null;
    $timeframe = $params['timeframe'] ?? '12'; // months
    
    if ($supplierId) {
        // Individual supplier performance
        $stmt = $db->prepare("
            SELECT 
                s.*,
                COUNT(DISTINCT d.id) as document_count,
                COUNT(DISTINCT a.id) as assessment_count,
                COUNT(DISTINCT ca.id) as capa_count,
                AVG(CASE WHEN a.status = 'Completed' THEN a.compliance_percentage END) as avg_assessment_score,
                COUNT(CASE WHEN d.status = 'Expired' THEN 1 END) as expired_documents,
                COUNT(CASE WHEN ca.status = 'Overdue' THEN 1 END) as overdue_capas
            FROM suppliers s
            LEFT JOIN documents d ON s.id = d.supplier_id
            LEFT JOIN assessments a ON s.id = a.supplier_id
            LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id
            WHERE s.id = ?
            GROUP BY s.id
        ");
        $stmt->execute([$supplierId]);
        $supplier = $stmt->fetch();
        
        // Performance history
        $stmt = $db->prepare("
            SELECT 
                DATE_FORMAT(a.completed_date, '%Y-%m') as month,
                AVG(a.compliance_percentage) as avg_score,
                COUNT(*) as assessment_count
            FROM assessments a
            WHERE a.supplier_id = ?
            AND a.completed_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            GROUP BY DATE_FORMAT(a.completed_date, '%Y-%m')
            ORDER BY month
        ");
        $stmt->execute([$supplierId, $timeframe]);
        $performanceHistory = $stmt->fetchAll();
        
        return [
            'supplier' => $supplier,
            'performance_history' => $performanceHistory,
            'benchmarks' => getSupplierBenchmarks($db, $supplier),
            'recommendations' => generatePerformanceRecommendations($supplier, $performanceHistory)
        ];
    } else {
        // All suppliers performance comparison
        $stmt = $db->prepare("
            SELECT 
                s.id,
                s.name,
                s.risk_category,
                s.supplier_type,
                s.iso_compliance_score,
                s.annual_spend_eur,
                COUNT(DISTINCT a.id) as assessment_count,
                AVG(CASE WHEN a.status = 'Completed' THEN a.compliance_percentage END) as avg_assessment_score,
                COUNT(CASE WHEN ca.status IN ('Assigned', 'In_Progress') THEN 1 END) as open_capas,
                DATEDIFF(s.next_audit_due, CURDATE()) as days_until_audit,
                (s.iso_compliance_score * 0.4) + 
                (COALESCE(AVG(CASE WHEN a.status = 'Completed' THEN a.compliance_percentage END), 0) * 0.3) +
                (CASE WHEN COUNT(CASE WHEN ca.status IN ('Assigned', 'In_Progress') THEN 1 END) = 0 THEN 30 ELSE 0 END) as performance_index
            FROM suppliers s
            LEFT JOIN assessments a ON s.id = a.supplier_id AND a.completed_date >= DATE_SUB(CURDATE(), INTERVAL ? MONTH)
            LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id
            WHERE s.status = 'Active'
            GROUP BY s.id
            ORDER BY performance_index DESC
        ");
        $stmt->execute([$timeframe]);
        $supplierPerformance = $stmt->fetchAll();
        
        return [
            'supplier_rankings' => $supplierPerformance,
            'performance_distribution' => calculatePerformanceDistribution($supplierPerformance),
            'top_performers' => array_slice($supplierPerformance, 0, 10),
            'underperformers' => array_slice(array_reverse($supplierPerformance), 0, 10)
        ];
    }
}

function getCertificateAnalytics($db, $params) {
    // Certificate expiry analysis
    $stmt = $db->prepare("
        SELECT 
            d.type as document_type,
            COUNT(*) as total_count,
            COUNT(CASE WHEN d.status = 'Valid' THEN 1 END) as valid_count,
            COUNT(CASE WHEN d.status = 'Expired' THEN 1 END) as expired_count,
            COUNT(CASE WHEN d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 30 DAY) AND d.status = 'Valid' THEN 1 END) as expiring_30_days,
            COUNT(CASE WHEN d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) AND d.status = 'Valid' THEN 1 END) as expiring_90_days,
            AVG(DATEDIFF(d.expiry_date, d.issue_date)) as avg_validity_period
        FROM documents d
        JOIN suppliers s ON d.supplier_id = s.id
        WHERE s.status = 'Active'
        GROUP BY d.type
        ORDER BY total_count DESC
    ");
    $stmt->execute();
    $certificateStats = $stmt->fetchAll();
    
    // Certificate compliance by standard
    $stmt = $db->prepare("
        SELECT 
            st.name as standard_name,
            st.standard_type,
            COUNT(DISTINCT s.id) as total_suppliers,
            COUNT(DISTINCT CASE WHEN ss.certification_status = 'Certified' THEN s.id END) as certified_suppliers,
            COUNT(DISTINCT d.id) as certificate_count,
            COUNT(DISTINCT CASE WHEN d.status = 'Valid' THEN d.id END) as valid_certificates,
            AVG(ss.compliance_percentage) as avg_compliance
        FROM standards st
        LEFT JOIN supplier_standards ss ON st.id = ss.standard_id
        LEFT JOIN suppliers s ON ss.supplier_id = s.id AND s.status = 'Active'
        LEFT JOIN documents d ON s.id = d.supplier_id AND d.type = 'Certificate' AND d.standard_id = st.id
        WHERE st.is_active = TRUE
        GROUP BY st.id, st.name, st.standard_type
        ORDER BY certified_suppliers DESC
    ");
    $stmt->execute();
    $standardCompliance = $stmt->fetchAll();
    
    // Certificate renewal patterns
    $stmt = $db->prepare("
        SELECT 
            MONTH(d.issue_date) as month,
            COUNT(*) as renewals,
            AVG(DATEDIFF(d.issue_date, prev_d.expiry_date)) as avg_renewal_gap
        FROM documents d
        LEFT JOIN documents prev_d ON d.supplier_id = prev_d.supplier_id 
                                    AND d.type = prev_d.type 
                                    AND d.standard_id = prev_d.standard_id
                                    AND d.issue_date > prev_d.issue_date
                                    AND prev_d.id = (
                                        SELECT MAX(id) FROM documents 
                                        WHERE supplier_id = d.supplier_id 
                                        AND type = d.type 
                                        AND standard_id = d.standard_id
                                        AND issue_date < d.issue_date
                                    )
        WHERE d.issue_date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
        GROUP BY MONTH(d.issue_date)
        ORDER BY month
    ");
    $stmt->execute();
    $renewalPatterns = $stmt->fetchAll();
    
    return [
        'certificate_stats' => $certificateStats,
        'standard_compliance' => $standardCompliance,
        'renewal_patterns' => $renewalPatterns,
        'expiry_forecast' => generateExpiryForecast($certificateStats),
        'compliance_gaps' => identifyComplianceGaps($standardCompliance)
    ];
}

function getAssessmentInsights($db, $params) {
    // Assessment performance analysis
    $stmt = $db->prepare("
        SELECT 
            a.assessment_type,
            a.assessment_method,
            COUNT(*) as total_assessments,
            AVG(a.compliance_percentage) as avg_score,
            MIN(a.compliance_percentage) as min_score,
            MAX(a.compliance_percentage) as max_score,
            AVG(a.actual_duration_hours) as avg_duration,
            COUNT(CASE WHEN a.assessment_result = 'Pass' THEN 1 END) as passed_count,
            COUNT(CASE WHEN a.status = 'Completed' THEN 1 END) as completed_count
        FROM assessments a
        JOIN suppliers s ON a.supplier_id = s.id
        WHERE a.scheduled_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        AND s.status = 'Active'
        GROUP BY a.assessment_type, a.assessment_method
        ORDER BY avg_score DESC
    ");
    $stmt->execute();
    $assessmentPerformance = $stmt->fetchAll();
    
    // Finding patterns analysis
    $stmt = $db->prepare("
        SELECT 
            f.category,
            f.severity,
            COUNT(*) as finding_count,
            COUNT(CASE WHEN f.status = 'Open' THEN 1 END) as open_count,
            COUNT(CASE WHEN f.status = 'Closed' THEN 1 END) as closed_count,
            AVG(DATEDIFF(COALESCE(f.actual_close_date, CURDATE()), a.completed_date)) as avg_resolution_days
        FROM findings f
        JOIN assessments a ON f.assessment_id = a.id
        WHERE a.completed_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY f.category, f.severity
        ORDER BY finding_count DESC
    ");
    $stmt->execute();
    $findingPatterns = $stmt->fetchAll();
    
    // Assessor performance
    $stmt = $db->prepare("
        SELECT 
            u.first_name,
            u.last_name,
            COUNT(a.id) as assessments_conducted,
            AVG(a.compliance_percentage) as avg_score_assigned,
            AVG(a.actual_duration_hours) as avg_duration,
            COUNT(CASE WHEN a.assessment_result = 'Pass' THEN 1 END) as pass_rate
        FROM assessments a
        JOIN users u ON a.assessor_id = u.id
        WHERE a.completed_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY a.assessor_id, u.first_name, u.last_name
        HAVING assessments_conducted >= 3
        ORDER BY avg_score_assigned DESC
    ");
    $stmt->execute();
    $assessorPerformance = $stmt->fetchAll();
    
    return [
        'assessment_performance' => $assessmentPerformance,
        'finding_patterns' => $findingPatterns,
        'assessor_performance' => $assessorPerformance,
        'effectiveness_metrics' => calculateAssessmentEffectiveness($assessmentPerformance, $findingPatterns),
        'improvement_opportunities' => identifyAssessmentImprovements($assessmentPerformance, $findingPatterns)
    ];
}

function getSpendAnalysis($db, $params) {
    // Spend by category and risk
    $stmt = $db->prepare("
        SELECT 
            s.risk_category,
            s.supplier_type,
            JSON_UNQUOTE(JSON_EXTRACT(s.product_categories, '$[0]')) as primary_category,
            COUNT(*) as supplier_count,
            SUM(s.annual_spend_eur) as total_spend,
            AVG(s.annual_spend_eur) as avg_spend,
            SUM(CASE WHEN s.business_critical = 1 THEN s.annual_spend_eur ELSE 0 END) as critical_spend,
            AVG(s.iso_compliance_score) as avg_compliance
        FROM suppliers s
        WHERE s.status = 'Active' 
        AND s.annual_spend_eur > 0
        GROUP BY s.risk_category, s.supplier_type, JSON_UNQUOTE(JSON_EXTRACT(s.product_categories, '$[0]'))
        ORDER BY total_spend DESC
    ");
    $stmt->execute();
    $spendAnalysis = $stmt->fetchAll();
    
    // Spend efficiency analysis
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.name,
            s.annual_spend_eur,
            s.iso_compliance_score,
            s.risk_category,
            (s.iso_compliance_score / NULLIF(s.annual_spend_eur, 0) * 1000000) as compliance_per_million_eur,
            COUNT(DISTINCT ca.id) as issue_count,
            COUNT(DISTINCT d.id) as document_count
        FROM suppliers s
        LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id AND ca.status IN ('Assigned', 'In_Progress')
        LEFT JOIN documents d ON s.id = d.supplier_id AND d.status = 'Valid'
        WHERE s.status = 'Active' 
        AND s.annual_spend_eur > 0
        GROUP BY s.id
        ORDER BY compliance_per_million_eur DESC
    ");
    $stmt->execute();
    $spendEfficiency = $stmt->fetchAll();
    
    return [
        'spend_by_category' => $spendAnalysis,
        'spend_efficiency' => $spendEfficiency,
        'spend_risk_matrix' => generateSpendRiskMatrix($spendAnalysis),
        'optimization_opportunities' => identifySpendOptimization($spendEfficiency)
    ];
}

function getPredictiveAnalytics($db, $params) {
    // Compliance score prediction
    $stmt = $db->prepare("
        SELECT 
            s.id,
            s.name,
            s.iso_compliance_score as current_score,
            s.risk_category,
            COUNT(DISTINCT ca.id) as open_capas,
            COUNT(DISTINCT CASE WHEN d.expiry_date <= DATE_ADD(CURDATE(), INTERVAL 90 DAY) THEN d.id END) as expiring_docs,
            DATEDIFF(s.next_audit_due, CURDATE()) as days_until_audit,
            AVG(CASE WHEN a.completed_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) THEN a.compliance_percentage END) as recent_avg_score
        FROM suppliers s
        LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id AND ca.status IN ('Assigned', 'In_Progress')
        LEFT JOIN documents d ON s.id = d.supplier_id AND d.status = 'Valid'
        LEFT JOIN assessments a ON s.id = a.supplier_id AND a.status = 'Completed'
        WHERE s.status = 'Active'
        GROUP BY s.id
    ");
    $stmt->execute();
    $supplierData = $stmt->fetchAll();
    
    $predictions = [];
    foreach ($supplierData as $supplier) {
        $predictions[] = [
            'supplier_id' => $supplier['id'],
            'supplier_name' => $supplier['name'],
            'current_score' => $supplier['current_score'],
            'predicted_score_3m' => predictComplianceScore($supplier, 3),
            'predicted_score_6m' => predictComplianceScore($supplier, 6),
            'risk_trend' => calculateRiskTrend($supplier),
            'intervention_needed' => shouldIntervene($supplier),
            'confidence_level' => calculatePredictionConfidence($supplier)
        ];
    }
    
    // Identify suppliers at risk of non-compliance
    $atRiskSuppliers = array_filter($predictions, function($p) {
        return $p['predicted_score_6m'] < 70 || $p['intervention_needed'];
    });
    
    return [
        'predictions' => $predictions,
        'at_risk_suppliers' => $atRiskSuppliers,
        'prediction_accuracy' => calculatePredictionAccuracy($db),
        'recommended_actions' => generateRecommendedActions($atRiskSuppliers)
    ];
}

function getRealtimeMetrics($db, $params) {
    // Real-time dashboard metrics
    $metrics = [];
    
    // Current system health
    $stmt = $db->prepare("SELECT NOW() as current_time");
    $stmt->execute();
    $systemTime = $stmt->fetch();
    
    // Live activity count (last 5 minutes)
    $stmt = $db->prepare("
        SELECT COUNT(*) as recent_activities
        FROM activity_log 
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 5 MINUTE)
    ");
    $stmt->execute();
    $recentActivity = $stmt->fetch();
    
    // Critical alerts count
    $stmt = $db->prepare("
        SELECT 
            COUNT(CASE WHEN d.expiry_date <= CURDATE() THEN 1 END) as expired_certificates,
            COUNT(CASE WHEN s.next_audit_due <= CURDATE() THEN 1 END) as overdue_audits,
            COUNT(CASE WHEN ca.due_date <= CURDATE() AND ca.status IN ('Assigned', 'In_Progress') THEN 1 END) as overdue_capas,
            COUNT(CASE WHEN s.iso_compliance_score < 60 THEN 1 END) as critical_compliance
        FROM suppliers s
        LEFT JOIN documents d ON s.id = d.supplier_id AND d.type = 'Certificate' AND d.status = 'Valid'
        LEFT JOIN corrective_actions ca ON s.id = ca.supplier_id
        WHERE s.status = 'Active'
    ");
    $stmt->execute();
    $alerts = $stmt->fetch();
    
    // Performance indicators
    $stmt = $db->prepare("
        SELECT 
            AVG(iso_compliance_score) as avg_compliance_today,
            COUNT(*) as total_active_suppliers,
            COUNT(CASE WHEN business_critical = 1 THEN 1 END) as critical_suppliers
        FROM suppliers 
        WHERE status = 'Active'
    ");
    $stmt->execute();
    $performance = $stmt->fetch();
    
    return [
        'system_status' => 'online',
        'last_updated' => $systemTime['current_time'],
        'recent_activity_count' => $recentActivity['recent_activities'],
        'critical_alerts' => $alerts,
        'performance_indicators' => $performance,
        'data_freshness' => 'real-time',
        'api_response_time' => round((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2) . 'ms'
    ];
}

// ==================== HELPER FUNCTIONS ====================

function calculateTrendDirection($trends) {
    if (count($trends) < 2) return 'insufficient_data';
    
    $first = reset($trends)['avg_compliance'];
    $last = end($trends)['avg_compliance'];
    
    $change = $last - $first;
    
    if (abs($change) < 1) return 'stable';
    return $change > 0 ? 'improving' : 'declining';
}

function calculateVolatility($trends) {
    $scores = array_column($trends, 'avg_compliance');
    if (count($scores) < 2) return 0;
    
    $mean = array_sum($scores) / count($scores);
    $variance = array_sum(array_map(function($x) use ($mean) { 
        return pow($x - $mean, 2); 
    }, $scores)) / count($scores);
    
    return sqrt($variance);
}

function predictComplianceScore($supplier, $months) {
    $currentScore = floatval($supplier['current_score']);
    $recentAvg = floatval($supplier['recent_avg_score'] ?? $currentScore);
    $openCapas = intval($supplier['open_capas']);
    $expiringDocs = intval($supplier['expiring_docs']);
    $daysUntilAudit = intval($supplier['days_until_audit']);
    
    // Simple predictive model
    $baseScore = ($currentScore + $recentAvg) / 2;
    
    // Apply risk factors
    $capaPenalty = $openCapas * 2; // 2 points per open CAPA
    $docPenalty = $expiringDocs * 1.5; // 1.5 points per expiring doc
    $auditBonus = $daysUntilAudit > 0 ? 0 : -5; // -5 if audit is overdue
    
    // Time decay factor
    $timeDecay = $months * 0.5; // 0.5 point decline per month without intervention
    
    $predictedScore = $baseScore - $capaPenalty - $docPenalty + $auditBonus - $timeDecay;
    
    return max(0, min(100, round($predictedScore, 1)));
}

function shouldIntervene($supplier) {
    $score = floatval($supplier['current_score']);
    $openCapas = intval($supplier['open_capas']);
    $expiringDocs = intval($supplier['expiring_docs']);
    $daysUntilAudit = intval($supplier['days_until_audit']);
    $riskCategory = $supplier['risk_category'];
    
    // Intervention criteria
    if ($score < 70) return true; // Low compliance score
    if ($openCapas > 3) return true; // Too many open CAPAs
    if ($expiringDocs > 2) return true; // Too many expiring documents
    if ($daysUntilAudit < 0) return true; // Overdue audit
    if ($riskCategory === 'A' && $score < 85) return true; // High risk with moderate score
    
    return false;
}

function calculatePredictionConfidence($supplier) {
    $factors = 0;
    $confidence = 100;
    
    // Reduce confidence based on data availability
    if (empty($supplier['recent_avg_score'])) {
        $confidence -= 20;
    }
    
    if (intval($supplier['open_capas']) > 5) {
        $confidence -= 15; // High uncertainty with many issues
    }
    
    if ($supplier['risk_category'] === 'A') {
        $confidence -= 10; // High risk suppliers are more volatile
    }
    
    return max(30, min(100, $confidence)); // Confidence between 30-100%
}
?>