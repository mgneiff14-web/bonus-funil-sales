<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

if (!isset($_GET['id'])) {
    echo json_encode(['error' => 'ID não fornecido']);
    exit;
}

$transactionId = trim($_GET['id']);
$transactionId = preg_replace('/[^a-zA-Z0-9\-]/', '', $transactionId);

// Credenciais da API Pollar Gateway
$publicKey = 'pk_nT84kRbCVvCK1FSyouc0SxWgdYie6nWoNlfLgOZrDyE';
$secretKey = 'sk_32WG3_8TvCMAWk5PqH-gCNHjTDJxeJmhTdLGxqkdkTc';

// Codifica as credenciais em Base64 para autenticação Basic
$authHeader = base64_encode($publicKey . ':' . $secretKey);

try {
    // Consulta a transação na API da Pollar Gateway
    $url = "https://api.pollargatteway.com/v1/transactions/{$transactionId}";
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $authHeader,
        'Content-Type: application/json'
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    if (curl_errno($ch)) {
        throw new Exception('Erro na requisição: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    $apiResponse = json_decode($response, true);
    
    if ($httpCode !== 200 || !isset($apiResponse['data'])) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $apiResponse['message'] ?? 'Transação não encontrada'
        ]);
        exit;
    }
    
    $transaction = $apiResponse['data'];
    
    // Retorna os dados da transação
    echo json_encode([
        'success' => true,
        'status' => $transaction['status'],
        'transaction_id' => $transaction['id'],
        'data' => [
            'amount' => $transaction['amount'],
            'fee' => $transaction['fee'],
            'net_amount' => $transaction['net_amount'],
            'currency' => $transaction['currency'],
            'method' => $transaction['method'],
            'installments' => $transaction['installments'],
            'paid_at' => $transaction['paid_at'],
            'created_at' => $transaction['created_at'],
            'updated_at' => $transaction['updated_at'],
            'customer' => [
                'name' => $transaction['customer']['name'],
                'email' => $transaction['customer']['email'],
                'phone' => $transaction['customer']['phone'],
                'document' => $transaction['customer']['document'],
                'document_type' => $transaction['customer']['document_type']
            ],
            'items' => $transaction['items'],
            'pix' => $transaction['pix'] ?? null
        ]
    ]);

} catch (Exception $e) {
    error_log("[Verificar Pollar] ❌ Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => 'Erro ao verificar o status do pagamento'
    ]);
} 