<?php
/**
 * Simple health check endpoint for ALB/ECS
 * Returns a 200 OK response if the application is running
 */
header('Content-Type: application/json');
echo json_encode(['status' => 'healthy', 'timestamp' => date('c')]);





