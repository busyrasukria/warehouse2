<?php
require_once 'db.php';
header('Content-Type: application/json');

// Helper to handle query params
$search = $_GET['search'] ?? '';

try {
    // 1. Aggregate Receiving Data (Union of all 3 supplier tables)
    $sql_rec = "
        SELECT erp_code, SUM(qty) as total_rec 
        FROM (
            SELECT erp_code, qty FROM receiving_log_ytec
            UNION ALL
            SELECT erp_code, qty FROM receiving_log_mazda
            UNION ALL
            SELECT erp_code, qty FROM receiving_log_marz
        ) as combined_rec 
        GROUP BY erp_code
    ";

    // 2. Aggregate Racking In
    $sql_rack = "SELECT ERP_CODE, SUM(RACK_IN) as total_rack FROM racking_in GROUP BY ERP_CODE";

    // 3. Aggregate Unboxing (Racking Out)
    $sql_out = "SELECT ERP_CODE, SUM(RACK_OUT) as total_out FROM unboxing_in GROUP BY ERP_CODE";

    // 4. Master Query - LEFT JOIN everything to Master Incoming
    $sql = "
        SELECT 
            m.erp_code, 
            m.stock_desc, 
            m.part_no, 
            m.supplier, 
            m.std_packing, 
            m.seq_number,
            COALESCE(r.total_rec, 0) as receiving_in,
            COALESCE(rk.total_rack, 0) as racking_in,
            COALESCE(u.total_out, 0) as racking_out
        FROM master_incoming m
        LEFT JOIN ($sql_rec) r ON m.erp_code = r.erp_code
        LEFT JOIN ($sql_rack) rk ON m.erp_code = rk.ERP_CODE
        LEFT JOIN ($sql_out) u ON m.erp_code = u.ERP_CODE
        WHERE 1=1
    ";

    // 5. Apply Search
    if (!empty($search)) {
        $term = "%$search%";
        $sql .= " AND (m.erp_code LIKE '$term' OR m.stock_desc LIKE '$term' OR m.part_no LIKE '$term' OR m.seq_number LIKE '$term')";
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 6. Calculate OS Logic & Grand Totals
    $final_data = [];
    
    // Initialize Grand Totals
    $summary = [
        'total_receiving_in' => 0,
        'total_racking_in'   => 0,
        'total_racking_out'  => 0,
        'total_os_receiving' => 0,
        'total_os_ranking'   => 0,
        'total_os_overall'   => 0
    ];

    foreach ($raw_data as $row) {
        $rec_in   = (int)$row['receiving_in'];
        $rack_in  = (int)$row['racking_in'];
        $rack_out = (int)$row['racking_out'];

        // Row Formulas
        $os_receiving = $rec_in - $rack_in; // Pending to rack
        $os_ranking   = $rack_in - $rack_out; // Currently in rack
        $os_total     = $os_receiving + $os_ranking; // Total Liability

        // Add to Row Data
        $row['os_receiving'] = $os_receiving;
        $row['os_ranking']   = $os_ranking;
        $row['os_total']     = $os_total;

        // Add to Grand Totals
        $summary['total_receiving_in'] += $rec_in;
        $summary['total_racking_in']   += $rack_in;
        $summary['total_racking_out']  += $rack_out;
        $summary['total_os_receiving'] += $os_receiving;
        $summary['total_os_ranking']   += $os_ranking;
        $summary['total_os_overall']   += $os_total;

        $final_data[] = $row;
    }

    // 7. Sort by OS Total (Highest first as requested)
    usort($final_data, function($a, $b) {
        return $b['os_total'] <=> $a['os_total'];
    });

    // Return both the data list AND the summary totals
    echo json_encode([
        'success' => true, 
        'summary' => $summary, 
        'data' => $final_data
    ]);

} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>