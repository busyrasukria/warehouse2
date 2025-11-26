<?php
require_once 'db.php';
header('Content-Type: application/json');

// --- CONFIGURATION ---
$admin_password = "Admin404"; // Matches your other files
// ---------------------

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$log_id = $_POST['log_id'] ?? null;
$password = $_POST['password'] ?? null;

if (empty($log_id) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Missing ID or Password.']);
    exit;
}

if ($password !== $admin_password) {
    echo json_encode(['success' => false, 'message' => 'Incorrect Password.']);
    exit;
}

try {
    // 1. Get master_trip_id and trip column before deleting to decrement the count
    $stmt_get = $pdo->prepare("SELECT master_trip_id, trip FROM warehouse_out WHERE log_id = ?");
    $stmt_get->execute([$log_id]);
    $row = $stmt_get->fetch();

    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'Record not found.']);
        exit;
    }

    $pdo->beginTransaction();

    // 2. Decrement the actual count in master_trip
    $trip_col = $row['trip']; // e.g., 'TRIP_1'
    $master_id = $row['master_trip_id'];
    
    // Security check: Ensure column name is safe/allowed
    $allowed_trips = ['TRIP_1', 'TRIP_2', 'TRIP_3', 'TRIP_4', 'TRIP_5', 'TRIP_6'];
    if (in_array($trip_col, $allowed_trips)) {
        $actual_col = "ACTUAL_" . $trip_col;
        $sql_update = "UPDATE master_trip SET $actual_col = GREATEST($actual_col - 1, 0) WHERE id = ?";
        $stmt_update = $pdo->prepare($sql_update);
        $stmt_update->execute([$master_id]);
    }

    // 3. Delete the scan record
    $stmt_del = $pdo->prepare("DELETE FROM warehouse_out WHERE log_id = ?");
    $stmt_del->execute([$log_id]);
    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Record deleted and count updated.']);

} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Delete Warehouse Out Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error.']);
}
?>