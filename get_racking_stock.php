<?php
require_once 'db.php';
header('Content-Type: application/json');

// Validate Request
$type = $_GET['type'] ?? ''; // 'location' or 'part'
$query = trim($_GET['query'] ?? '');

if (empty($type) || empty($query)) {
    echo json_encode(['success' => false, 'message' => 'Invalid search parameters.']);
    exit;
}

try {
    // === FIX APPLIED ===
    // 1. DATE(RECEIVING_DATE): Forces the database to ignore the Time (Hours/Minutes) and group by Day only.
    // 2. TRIM(): Removes hidden spaces from Location/Part No so they match perfectly.
    
    $sql = "SELECT 
                TRIM(RACKING_LOCATION) as RACKING_LOCATION, 
                DATE(RECEIVING_DATE) as R_DATE, 
                TRIM(PART_NO) as PART_NO, 
                TRIM(ERP_CODE) as ERP_CODE, 
                TRIM(SEQ_NO) as SEQ_NO, 
                PART_NAME, 
                SUM(RACK_IN) as total_qty, 
                COUNT(*) as box_count,
                DATEDIFF(NOW(), DATE(RECEIVING_DATE)) as days_in_stock
            FROM racking_in ";

    $params = [];

    if ($type === 'location') {
        $sql .= "WHERE RACKING_LOCATION LIKE ? ";
        $params[] = "%$query%";
    } elseif ($type === 'part') {
        // Search by ERP OR Seq No OR Part No
        $sql .= "WHERE ERP_CODE LIKE ? OR SEQ_NO LIKE ? OR PART_NO LIKE ? ";
        $params[] = "%$query%";
        $params[] = "%$query%";
        $params[] = "%$query%";
    }

    // === THE CRITICAL GROUPING FIX ===
    // We group by DATE(RECEIVING_DATE) so 10:00am and 2:00pm become the same group.
    $sql .= "GROUP BY TRIM(RACKING_LOCATION), TRIM(PART_NO), TRIM(ERP_CODE), TRIM(SEQ_NO), DATE(RECEIVING_DATE) 
             ORDER BY DATE(RECEIVING_DATE) ASC, RACKING_LOCATION ASC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll();

    // Format data for the frontend
    foreach ($data as &$row) {
        // Use the alias R_DATE created in the query
        $row['date_fmt'] = date('d/m/Y', strtotime($row['R_DATE']));
        
        // FIFO Alert Logic
        if ($row['days_in_stock'] > 60) {
            $row['fifo_status'] = 'critical';
        } elseif ($row['days_in_stock'] > 30) {
            $row['fifo_status'] = 'warning';
        } else {
            $row['fifo_status'] = 'fresh';
        }
    }

    echo json_encode(['success' => true, 'data' => $data]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
?>