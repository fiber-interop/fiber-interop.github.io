<?php usleep(300000); header('Content-Type: application/json'); echo json_encode(['path' => $_SERVER['REQUEST_URI']]);
