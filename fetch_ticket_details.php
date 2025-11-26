<?php
require_once 'db.php';
header('Content-Type: application/json');

// Get data from JSON POST
$rawData = file_get_contents('php://input');
$data = json_decode($rawData, true);

$unique_no = $data['unique_no'] ?? null;
$erp_code_fg = $data['erp_code_fg'] ?? null;

if (empty($unique_no) || empty($erp_code_fg)) {
    echo json_encode(['success' => false, 'message' => 'Ticket ID and ERP Code are required.']);
    exit;
}

try {
    // 1. Check if already scanned
    // THIS IS THE CORRECTED LINE: using 'warehouse_in'
    $stmt_check = $pdo->prepare("SELECT 1 FROM warehouse_in WHERE unique_no = ?");
    $stmt_check->execute([$unique_no]);
    if ($stmt_check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Error: This ticket (ID: ' . htmlspecialchars($unique_no) . ') has already been scanned.']);
        exit;
    }

    // 2. Fetch ticket and master data
    $stmt = $pdo->prepare("
        SELECT 
            tt.ticket_id, tt.unique_no, tt.created_at, tt.erp_code_FG, tt.part_no_FG, 
            tt.part_name, tt.model, tt.prod_area, tt.quantity, tt.released_by,
            m.part_no_B, m.erp_code_B
        FROM transfer_tickets tt
        LEFT JOIN master m ON tt.erp_code_FG = m.erp_code_FG
        WHERE tt.unique_no = ? AND tt.erp_code_FG = ?
    ");
    $stmt->execute([$unique_no, $erp_code_fg]);
    $ticket = $stmt->fetch();

    if (!$ticket) {
        echo json_encode(['success' => false, 'message' => 'No matching ticket found for that ID and ERP Code.']);
        exit;
    }
    
    // 3. Get manpower display name(s)
    $manpower_display = $ticket['released_by']; // Default to IDs
    $emp_ids = array_map('trim', explode(',', $ticket['released_by']));
    if (!empty($emp_ids)) {
        // Build placeholders for all possible IDs
        $placeholders = str_repeat('?,', count($emp_ids) - 1) . '?';
        
        // Prepare the query to check both emp_id and nickname
        $stmt_mp = $pdo->prepare("SELECT emp_id, name, nickname FROM manpower WHERE emp_id IN ($placeholders) OR nickname IN ($placeholders)");
        
        // Execute with the same set of IDs twice
        $stmt_mp->execute(array_merge($emp_ids, $emp_ids));
        
        $manpowerMap = [];
        // Map the results by both emp_id and nickname for easy lookup
        foreach ($stmt_mp->fetchAll() as $mp) {
            $displayName = $mp['nickname'] ?? (explode(' ', $mp['name'])[0]); // Use nickname or first name
            if (!empty($mp['emp_id'])) {
                $manpowerMap[$mp['emp_id']] = $displayName;
            }
            if (!empty($mp['nickname'])) {
                $manpowerMap[$mp['nickname']] = $displayName;
            }
        }

        $names = [];
        // Go through the original IDs from the ticket and find their display name
        foreach ($emp_ids as $id) {
            $names[] = $manpowerMap[$id] ?? $id; // Fallback to the ID itself if not found
        }
        $manpower_display = implode(' / ', $names);
    }


    // 4. Return all data
    echo json_encode([
        'success' => true,
        'data' => [
            // Sanitize all data before sending
            'unique_no' => htmlspecialchars($ticket['unique_no']),
            'prod_date' => date('d/m/Y', strtotime($ticket['created_at'])), // Format date
            'erp_code_FG' => htmlspecialchars($ticket['erp_code_FG']),
            'part_no_FG' => htmlspecialchars($ticket['part_no_FG']),
            'part_name' => htmlspecialchars($ticket['part_name']),
            'part_no_B' => htmlspecialchars($ticket['part_no_B'] ?? '-'),
            'erp_code_B' => htmlspecialchars($ticket['erp_code_B'] ?? '-'),
            'model' => htmlspecialchars($ticket['model']),
            'prod_area' => htmlspecialchars($ticket['prod_area']),
            'quantity' => (int)$ticket['quantity'],
            'released_by_ids' => htmlspecialchars($ticket['released_by']), // The raw IDs (e.g., 'SHS')
            'released_by_display' => htmlspecialchars($manpower_display)    // The display names (e.g., 'Syahir')
        ]
    ]);

} catch (PDOException $e) {
    error_log("Fetch Ticket Error: " . $e->getMessage());
    // Send back a generic error but log the specific one
    echo json_encode(['success' => false, 'message' => 'A database error occurred. Please check the logs.']);
}
?>

