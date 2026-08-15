<?php
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
date_default_timezone_set('America/Sao_Paulo');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'ID não fornecido']);
    exit;
}

$id = preg_replace('/[^a-zA-Z0-9\-]/', '', trim($_GET['id']));

try {
    $dbPath = __DIR__ . '/database.sqlite';
    $db     = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :tid");
    $stmt->execute(['tid' => $id]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Pedido não encontrado']);
        exit;
    }

    echo json_encode([
        'success'        => true,
        'status'         => $pedido['status'],
        'transaction_id' => $pedido['transaction_id'],
        'data' => [
            'amount'     => $pedido['valor'],
            'created_at' => $pedido['created_at'],
            'updated_at' => $pedido['updated_at'],
            'customer'   => [
                'name'     => $pedido['nome'],
                'email'    => $pedido['email'],
                'document' => $pedido['cpf'],
            ],
        ],
    ]);

} catch (Exception $e) {
    error_log("[Verificar FlevoPay] ❌ Erro: " . $e->getMessage());
    echo json_encode(['success' => false, 'status' => 'error', 'message' => 'Erro ao verificar pagamento']);
}
