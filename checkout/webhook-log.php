<?php
// webhook-log.php — diagnóstico: registra tudo que chega sem processar nada
// Use temporariamente como URL de webhook no Paradise para testar conectividade

$logDir  = __DIR__ . '/logs';
$logFile = $logDir . '/webhook-diagnostico-' . date('Y-m-d') . '.log';

if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

$entrada = json_encode([
    'hora'    => date('Y-m-d H:i:s'),
    'method'  => $_SERVER['REQUEST_METHOD'] ?? '',
    'ip'      => $_SERVER['REMOTE_ADDR'] ?? '',
    'body'    => file_get_contents('php://input'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

file_put_contents($logFile, $entrada . "\n" . str_repeat('=', 60) . "\n", FILE_APPEND | LOCK_EX);

http_response_code(200);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'registrado' => date('Y-m-d H:i:s')]);
