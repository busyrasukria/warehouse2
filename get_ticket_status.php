<?php
// Filename: get_ticket_status.php
require_once 'db.php';
header('Content-Type: application/json');

$unique_no = $_GET['unique_no'] ?? null;
$erp_code_fg = $_GET['erp_code_fg'] ?? null; // <-- THE MISSING LINE

if (empty($unique_no) || empty($erp_code_fg)) { // <-- UPDATED CHECK
    echo json_encode(['success' => false, 'message' => 'Ticket ID and ERP Code are both required.']);
    exit;
}

try {
    // 1. Get the specific ticket details using both keys
    $stmt_ticket = $pdo->prepare("
        SELECT 
            tt.ticket_id, tt.unique_no, tt.part_name, tt.model, 
            tt.quantity, tt.released_by, tt.created_at,
            tt.part_no_FG, tt.erp_code_FG, m.part_no_B, m.erp_code_B
        FROM transfer_tickets tt 
        LEFT JOIN master m ON tt.erp_code_FG = m.erp_code_FG
        WHERE tt.unique_no = ? AND tt.erp_code_FG = ?
    "); // <-- UPDATED QUERY
    $stmt_ticket->execute([$unique_no, $erp_code_fg]); // <-- UPDATED EXECUTE
    $ticket = $stmt_ticket->fetch();

    if (!$ticket) {
        echo json_encode(['success' => false, 'message' => 'Ticket not found with that ID and ERP Code.']);
        exit;
    }

    // 2. Get the tracking history for this *specific* ticket_id
    // This part was already correct, but was querying the wrong ticket ID
    $stmt_log = $pdo->prepare(
        "SELECT status_message, status_timestamp, scanned_by 
         FROM ticket_status_log 
         WHERE ticket_id = ? 
         ORDER BY status_timestamp ASC"
    );
    $stmt_log->execute([$ticket['ticket_id']]); // This will now use the correct ID (e.g., 499)
    $history = $stmt_log->fetchAll();

    // 3. Return all data
    echo json_encode([
        'success' => true,
        'ticket_details' => $ticket,
        'tracking_history' => $history
    ]);

} catch (PDOException $e) {
    error_log("Get Ticket Status Error: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'A database error occurred.']);
}
?>