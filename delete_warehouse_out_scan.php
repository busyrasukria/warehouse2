<?php
require_once 'db.php';
header('Content-Type: application/json');

// --- CONFIGURATION ---
$admin_password = "Admin404"; 
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
    $pdo->beginTransaction();

    // 1. Get details BEFORE deleting (Need unique_no & erp_code to find the Ticket ID)
    $stmt_get = $pdo->prepare("SELECT master_trip_id, trip, unique_no, erp_code FROM warehouse_out WHERE log_id = ?");
    $stmt_get->execute([$log_id]);
    $row = $stmt_get->fetch();

    if (!$row) {
        throw new Exception('Record not found.');
    }

    // 2. Find the Ticket ID (Required to delete the log)
    $ticket_id = null;
    if (!empty($row['unique_no']) && !empty($row['erp_code'])) {
        $stmt_ticket = $pdo->prepare("SELECT ticket_id FROM transfer_tickets WHERE unique_no = ? AND erp_code_FG = ?");
        $stmt_ticket->execute([$row['unique_no'], $row['erp_code']]);
        $ticket_id = $stmt_ticket->fetchColumn();
    }

    // 3. Decrement Trip Count
    $trip_col = $row['trip']; 
    $master_id = $row['master_trip_id'];
    $allowed_trips = ['TRIP_1', 'TRIP_2', 'TRIP_3', 'TRIP_4', 'TRIP_5', 'TRIP_6'];
    
    if (in_array($trip_col, $allowed_trips)) {
        $actual_col = "ACTUAL_" . $trip_col;
        $sql_update = "UPDATE master_trip SET $actual_col = GREATEST($actual_col - 1, 0) WHERE id = ?";
        $pdo->prepare($sql_update)->execute([$master_id]);
    }

    // 4. DELETE THE HISTORY LOG (*** THE FIX ***)
    if ($ticket_id) {
        $pdo->prepare("DELETE FROM ticket_status_log WHERE ticket_id = ? AND status_code = 'CUSTOMER_OUT'")
            ->execute([$ticket_id]);
    }

    // 5. Delete the physical scan record
    $stmt_del = $pdo->prepare("DELETE FROM warehouse_out WHERE log_id = ?");
    $stmt_del->execute([$log_id]);

    $pdo->commit();
    echo json_encode(['success' => true, 'message' => 'Record and History Log deleted successfully.']);

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log("Delete Warehouse Out Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>
