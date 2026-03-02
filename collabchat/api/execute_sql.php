<?php
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit();
}

require_once '/var/www/secure_config/braintrust_config.php';

$data = json_decode(file_get_contents('php://input'), true);
$query = trim($data['query'] ?? '');
$database = $data['database'] ?? 'braintrust';
$confirmed = $data['confirmed'] ?? false;

if (empty($query)) {
    echo json_encode(['success' => false, 'error' => 'No query provided']);
    exit();
}

// Check if query contains dangerous keywords
$dangerousKeywords = ['DROP', 'DELETE', 'TRUNCATE', 'INSERT', 'UPDATE', 'ALTER', 'CREATE', 'GRANT', 'REVOKE'];
$isDangerous = false;
$detectedKeyword = '';

foreach ($dangerousKeywords as $keyword) {
    // Use word boundaries to match only complete words
    if (preg_match('/\b' . preg_quote($keyword, '/') . '\b/i', $query)) {
        $isDangerous = true;
        $detectedKeyword = $keyword;
        break;
    }
}

// If dangerous and not confirmed, require confirmation
if ($isDangerous && !$confirmed) {
    echo json_encode([
        'success' => false,
        'requiresConfirmation' => true,
        'keyword' => $detectedKeyword,
        'message' => "⚠️ This query contains a dangerous operation: {$detectedKeyword}"
    ]);
    exit();
}

try {
    $startTime = microtime(true);
    
    // Execute query
    $result = $conn->query($query);
    
    $executionTime = round((microtime(true) - $startTime) * 1000, 2);
    
    if ($result === false) {
        echo json_encode([
            'success' => false, 
            'error' => $conn->error,
            'executionTime' => $executionTime
        ]);
        exit();
    }
    
    // Fetch results
    $rows = [];
    if ($result instanceof mysqli_result && $result->num_rows > 0) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $rows,
        'rowCount' => count($rows),
        'executionTime' => $executionTime
    ]);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage()
    ]);
}

$conn->close();
?>