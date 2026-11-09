<?php
// health.php – simple health‑check endpoint for Render
header('Content-Type: application/json');
http_response_code(200);

echo json_encode([
    'status' => 'ok',
    'timestamp' => date('c')
]);
?>