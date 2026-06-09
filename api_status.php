<?php
header('Content-Type: application/json');

// Get POST data from fetch
$data = json_decode(file_get_contents('php://input'), true);

if (isset($data['start_date']) && isset($data['end_date'])) {
    $start = escapeshellarg($data['start_date']);
    $end = escapeshellarg($data['end_date']);
    
    // Execute python script
    $python_command = "python data_processor.py --check-status $start $end";
    $output = shell_exec($python_command);
    
    echo $output;
} else {
    echo json_encode(['error' => 'Missing parameters']);
}
?>
