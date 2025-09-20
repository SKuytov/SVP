<?php
// SupplierVault Pro - Production Database Configuration
// Database: skuytove_supplierVault_db
// Target Market: $57 Billion Global Compliance Management Software Market

class Database {
    private $host = 'localhost';
    private $db_name = 'skuytove_supplierVault_db';
    private $username = 'skuytove_sal';
    private $password = '410010Kuyto-';
    private $port = 3306;
    private $conn;

    /**
     * Get PDO database connection
     * @return PDO
     * @throws Exception
     */
    public function getConnection() {
        if ($this->conn === null) {
            try {
                $dsn = "mysql:host={$this->host};port={$this->port};dbname={$this->db_name};charset=utf8mb4";
                
                $this->conn = new PDO(
                    $dsn,
                    $this->username,
                    $this->password,
                    [
                        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES => false,
                        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4"
                    ]
                );
            } catch (PDOException $e) {
                error_log("Database Connection Error: " . $e->getMessage());
                throw new Exception("Failed to connect to database: " . $e->getMessage());
            }
        }
        return $this->conn;
    }

    /**
     * Close database connection
     */
    public function closeConnection() {
        $this->conn = null;
    }
}

// Security and Utility Classes
class Security {
    public static function sanitizeInput($data) {
        return htmlspecialchars(strip_tags(trim($data)), ENT_QUOTES, 'UTF-8');
    }
    
    public static function validateEmail($email) {
        return filter_var($email, FILTER_VALIDATE_EMAIL);
    }
    
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
}

class FileUploader {
    private $uploadPath;
    private $allowedTypes = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'image/jpeg',
        'image/png',
        'image/gif'
    ];
    
    public function __construct($uploadPath = 'uploads/') {
        $this->uploadPath = rtrim($uploadPath, '/') . '/';
        
        if (!is_dir($this->uploadPath)) {
            mkdir($this->uploadPath, 0755, true);
        }
    }
    
    public function uploadFile($file, $subPath = '', $customName = null) {
        if (!isset($file['error']) || is_array($file['error'])) {
            throw new RuntimeException('Invalid file parameters.');
        }
        
        switch ($file['error']) {
            case UPLOAD_ERR_OK:
                break;
            case UPLOAD_ERR_NO_FILE:
                throw new RuntimeException('No file sent.');
            case UPLOAD_ERR_INI_SIZE:
            case UPLOAD_ERR_FORM_SIZE:
                throw new RuntimeException('File size exceeds limit.');
            default:
                throw new RuntimeException('File upload error.');
        }
        
        if ($file['size'] > 10485760) { // 10MB
            throw new RuntimeException('File size exceeds 10MB limit.');
        }
        
        $uploadDir = $this->uploadPath . ltrim($subPath, '/');
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $fileExtension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $fileName = $customName ? $customName . '.' . $fileExtension : 
                   uniqid() . '_' . basename($file['name']);
        
        $targetPath = $uploadDir . '/' . $fileName;
        
        if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
            throw new RuntimeException('Failed to move uploaded file.');
        }
        
        return [
            'filename' => $fileName,
            'path' => $targetPath,
            'size' => $file['size'],
            'type' => $file['type'],
            'checksum' => hash_file('sha256', $targetPath)
        ];
    }
}

class ActivityLogger {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    public function log($userId, $action, $resource, $resourceId = null, $oldValues = null, $newValues = null) {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO activity_log 
                (user_id, action, resource, resource_id, old_values, new_values, ip_address, user_agent) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            
            $stmt->execute([
                $userId,
                $action,
                $resource,
                $resourceId,
                $oldValues ? json_encode($oldValues) : null,
                $newValues ? json_encode($newValues) : null,
                $_SERVER['REMOTE_ADDR'] ?? null,
                $_SERVER['HTTP_USER_AGENT'] ?? null
            ]);
        } catch (Exception $e) {
            error_log("Activity logging failed: " . $e->getMessage());
        }
    }
}

class ApiResponse {
    public static function success($data = null, $message = null, $code = 200) {
        http_response_code($code);
        self::setHeaders();
        
        echo json_encode([
            'success' => true,
            'data' => $data,
            'message' => $message,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    public static function error($message, $code = 400, $errors = null) {
        http_response_code($code);
        self::setHeaders();
        
        echo json_encode([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'timestamp' => date('c')
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    private static function setHeaders() {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
    }
}

class SessionManager {
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
    
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    public static function get($key, $default = null) {
        self::start();
        return $_SESSION[$key] ?? $default;
    }
    
    public static function destroy() {
        self::start();
        session_destroy();
    }
    
    public static function isLoggedIn() {
        return self::get('user_id') !== null;
    }
}

// Configuration Constants
define('UPLOAD_MAX_SIZE', 10 * 1024 * 1024); // 10MB
define('UPLOAD_PATH', __DIR__ . '/../uploads/');
define('BASE_URL', 'http://skuytov.eu/SVP/');
define('UPLOAD_URL', BASE_URL . 'uploads/');

// Error Handling (Disable in production)
ini_set('display_errors', 1);
error_reporting(E_ALL);

// Timezone
date_default_timezone_set('Europe/Sofia');

// Handle CORS preflight requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization');
    exit(0);
}

// Test Connection Function (for debugging)
function testConnection() {
    try {
        $db = (new Database())->getConnection();
        echo "✅ Database connection successful!<br>";
        echo "🏢 Connected to: skuytove_supplierVault_db<br>";
        echo "👤 User: skuytove_sal<br>";
        
        // Test suppliers table
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM suppliers");
        $stmt->execute();
        $result = $stmt->fetch();
        echo "📊 Found " . $result['count'] . " suppliers in database<br>";
        
        return true;
    } catch (Exception $e) {
        echo "❌ Connection failed: " . $e->getMessage() . "<br>";
        return false;
    }
}

// Uncomment to test connection
// testConnection();
?>