<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

session_start();

// Database configuration - UPDATE THESE VALUES
$host = 'localhost';
$dbname = 'edushare_db';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed: ' . $e->getMessage()]);
    exit();
}

function sendResponse($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data);
    exit();
}

$action = isset($_GET['action']) ? $_GET['action'] : '';

// ========== REGISTER ==========
if ($action === 'register') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendResponse(['error' => 'Invalid input'], 400);
    }
    
    $name = trim($input['name'] ?? '');
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    $role = in_array($input['role'] ?? '', ['student', 'lecturer', 'admin']) ? $input['role'] : 'student';
    
    if (empty($name) || empty($email) || empty($password)) {
        sendResponse(['error' => 'All fields are required'], 400);
    }
    
    // Check if email exists
    $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        sendResponse(['error' => 'Email already registered'], 409);
    }
    
    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, ?)");
    
    if ($stmt->execute([$name, $email, $hashedPassword, $role])) {
        sendResponse(['success' => true, 'message' => 'Registration successful']);
    } else {
        sendResponse(['error' => 'Registration failed'], 500);
    }
}

// ========== LOGIN ==========
if ($action === 'login') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (!$input) {
        sendResponse(['error' => 'Invalid input'], 400);
    }
    
    $email = trim($input['email'] ?? '');
    $password = $input['password'] ?? '';
    
    $stmt = $pdo->prepare("SELECT id, name, email, role, password FROM users WHERE email = ?");
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    
    if ($user && password_verify($password, $user['password'])) {
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        
        sendResponse([
            'success' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'role' => $user['role']
            ]
        ]);
    } else {
        sendResponse(['error' => 'Invalid email or password'], 401);
    }
}

// ========== LOGOUT ==========
if ($action === 'logout') {
    session_destroy();
    sendResponse(['success' => true]);
}

// ========== CHECK AUTH ==========
if ($action === 'checkAuth') {
    if (isset($_SESSION['user_id'])) {
        sendResponse([
            'loggedIn' => true,
            'user' => [
                'id' => $_SESSION['user_id'],
                'name' => $_SESSION['user_name'],
                'email' => $_SESSION['user_email'],
                'role' => $_SESSION['user_role']
            ]
        ]);
    } else {
        sendResponse(['loggedIn' => false]);
    }
}

// ========== GET MATERIALS ==========
if ($action === 'getMaterials') {
    $stmt = $pdo->query("
        SELECT m.*, u.name as uploader_name 
        FROM materials m 
        JOIN users u ON m.uploader_id = u.id 
        ORDER BY m.upload_date DESC
    ");
    $materials = $stmt->fetchAll();
    
    foreach ($materials as &$material) {
        $material['upload_date'] = date('Y-m-d', strtotime($material['upload_date']));
    }
    
    sendResponse(['materials' => $materials]);
}

// ========== UPLOAD MATERIAL ==========
if ($action === 'upload') {
    if (!isset($_SESSION['user_id'])) {
        sendResponse(['error' => 'Unauthorized'], 401);
    }
    
    if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
        sendResponse(['error' => 'No file uploaded or upload error'], 400);
    }
    
    $course_code = trim($_POST['course_code'] ?? '');
    $course_title = trim($_POST['course_title'] ?? '');
    $department = trim($_POST['department'] ?? '');
    
    if (empty($course_code) || empty($course_title) || empty($department)) {
        sendResponse(['error' => 'Course code, title, and department are required'], 400);
    }
    
    $file = $_FILES['pdf_file'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($extension !== 'pdf') {
        sendResponse(['error' => 'Only PDF files are allowed'], 400);
    }
    
    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/uploads/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
    }
    
    // Generate unique filename
    $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', $file['name']);
    $destination = $uploadDir . $safeName;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        $filePath = 'uploads/' . $safeName;
        $fileSize = $file['size'];
        $uploaderId = $_SESSION['user_id'];
        $uploaderName = $_SESSION['user_name'];
        
        $stmt = $pdo->prepare("
            INSERT INTO materials (course_code, course_title, department, file_name, file_path, file_size, uploader_id, uploader_name) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        if ($stmt->execute([$course_code, $course_title, $department, $file['name'], $filePath, $fileSize, $uploaderId, $uploaderName])) {
            sendResponse(['success' => true, 'message' => 'File uploaded successfully']);
        } else {
            // Delete the uploaded file if DB insert fails
            unlink($destination);
            sendResponse(['error' => 'Database insert failed'], 500);
        }
    } else {
        sendResponse(['error' => 'Failed to save file'], 500);
    }
}

// ========== DELETE MATERIAL ==========
if ($action === 'delete') {
    if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
        sendResponse(['error' => 'Admin access required'], 403);
    }
    
    $id = isset($_GET['id']) ? intval($_GET['id']) : 0;
    if ($id <= 0) {
        sendResponse(['error' => 'Invalid material ID'], 400);
    }
    
    // Get file path before deleting from database
    $stmt = $pdo->prepare("SELECT file_path FROM materials WHERE id = ?");
    $stmt->execute([$id]);
    $material = $stmt->fetch();
    
    if (!$material) {
        sendResponse(['error' => 'Material not found'], 404);
    }
    
    // Delete the file from server
    $filePath = __DIR__ . '/' . $material['file_path'];
    if (file_exists($filePath)) {
        unlink($filePath);
    }
    
    // Delete from database
    $stmt = $pdo->prepare("DELETE FROM materials WHERE id = ?");
    if ($stmt->execute([$id])) {
        sendResponse(['success' => true, 'message' => 'Material deleted']);
    } else {
        sendResponse(['error' => 'Failed to delete from database'], 500);
    }
}

// If no action matched
sendResponse(['error' => 'Invalid action'], 400);
?>