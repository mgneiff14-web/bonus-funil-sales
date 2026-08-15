<?php
header('Content-Type: application/json');

// Habilita o log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Recebe o payload do webhook
$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// Log do payload recebido
error_log("[Webhook] 🔄 Iniciando processamento do webhook");
error_log("[Webhook] 📦 Payload recebido: " . $payload);

// Verifica se o payload é válido - Formato AllowPay v2
if (!$event || !isset($event['id']) || !isset($event['type']) || !isset($event['data'])) {
    error_log("[Webhook] ❌ Payload inválido recebido. Campos necessários não encontrados");
    error_log("[Webhook] 🔍 Campos disponíveis: " . print_r(array_keys($event ?? []), true));
    http_response_code(200);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

function getUpsellTitle($valor) {
    // Mapeamento de valores para nomes de upsell
    switch($valor) {
        case 4790:
            return 'Taxa de verificação';
        case 2890:
            return 'Taxa TENF';
        case 4569:
            return 'Taxa IOF';
        case 8500:
            return 'Taxa de Regularização';
        case 1825:
            return 'Validação Bancaria';
        case 3990:
            return 'Taxa de Validação';
        case 5573:
            return 'Front'; // Valor original do checkout
        case 2490:
            return 'Indenização Adicional'; // Valor padrão do checkoutup
        default:
            return 'Produto ' . ($valor/100); // Para outros valores não mapeados
    }
}

try {
    // Extrair os dados relevantes do novo formato de webhook
    $transaction = $event['data'];
    $transactionId = $transaction['id'] ?? null;
    $status = $transaction['status'] ?? null;
    
    error_log("[Webhook] ℹ️ Processando pagamento ID: " . $transactionId . " com status: " . $status);
    
    // Conecta ao SQLite
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("[Webhook] ✅ Conexão com banco de dados estabelecida");

    // Atualiza o status do pagamento no banco de dados
    $stmt = $db->prepare("UPDATE pedidos SET status = :status, updated_at = :updated_at WHERE transaction_id = :transaction_id");
    
    $novoStatus = strtolower($status) === 'paid' || strtolower($status) === 'approved' ? 'paid' : strtolower($status);
    error_log("[Webhook] 🔄 Atualizando status para: " . $novoStatus);
    
    $result = $stmt->execute([
        'status' => $novoStatus,
        'updated_at' => date('c'),
        'transaction_id' => $transactionId
    ]);

    if ($stmt->rowCount() === 0) {
        error_log("[Webhook] ⚠️ Nenhum pedido encontrado com o ID: " . $transactionId);
        error_log("[Webhook] 🔍 Verificando se o pedido existe no banco...");
        
        // Verifica se o pedido existe
        $checkStmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $checkStmt->execute(['transaction_id' => $transactionId]);
        $pedidoExiste = $checkStmt->fetch();
        
        if ($pedidoExiste) {
            error_log("[Webhook] ℹ️ Pedido encontrado mas status não foi alterado. Status atual: " . $pedidoExiste['status']);
        } else {
            error_log("[Webhook] ❌ Pedido não existe no banco de dados");
        }
        
        http_response_code(200);
        echo json_encode(['error' => 'Pedido não encontrado']);
        exit;
    }

    error_log("[Webhook] ✅ Status atualizado com sucesso no banco de dados");

    // Responde imediatamente ao webhook
    http_response_code(200);
    echo json_encode(['success' => true]);
    
    // Fecha a conexão com o cliente
    if (function_exists('fastcgi_finish_request')) {
        error_log("[Webhook] 📤 Fechando conexão com o cliente via fastcgi_finish_request");
        fastcgi_finish_request();
    } else {
        error_log("[Webhook] ⚠️ fastcgi_finish_request não disponível");
    }
    
    // Continua o processamento em background
    if (strtolower($status) === 'paid' || strtolower($status) === 'approved') {
        error_log("[Webhook] ✅ Pagamento aprovado, iniciando processamento em background");

        // Busca os dados do pedido
        $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $stmt->execute(['transaction_id' => $transactionId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido) {
            error_log("[Webhook] ✅ Dados do pedido recuperados do banco");
            error_log("[Webhook] 📊 Dados do pedido: " . print_r($pedido, true));

            // Decodifica os parâmetros UTM do banco
            $utmParams = json_decode($pedido['utm_params'], true);
            error_log("[Webhook] 📊 UTM Params brutos do banco: " . print_r($utmParams, true));
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log("[Webhook] ⚠️ Erro ao decodificar UTM params: " . json_last_error_msg());
            }

            // Extrai os parâmetros UTM
            $trackingParameters = [
                'src' => $utmParams['utm_source'] ?? null,
                'sck' => $utmParams['sck'] ?? null,
                'utm_source' => $utmParams['utm_source'] ?? null,
                'utm_campaign' => $utmParams['utm_campaign'] ?? null,
                'utm_medium' => $utmParams['utm_medium'] ?? null,
                'utm_content' => $utmParams['utm_content'] ?? null,
                'utm_term' => $utmParams['utm_term'] ?? null,
                'fbclid' => $utmParams['fbclid'] ?? null,
                'gclid' => $utmParams['gclid'] ?? null,
                'ttclid' => $utmParams['ttclid'] ?? null,
                'xcod' => $utmParams['xcod'] ?? null
            ];

            // Remove valores null
            $trackingParameters = array_filter($trackingParameters);

            // Extrai dados do cliente do novo formato AllowPay v2
            $customer = $transaction['customer'] ?? [];
            $customerDocument = $customer['document'] ?? '';
            $fee = $transaction['fee'] ?? [];
            $items = $transaction['items'] ?? [];
            $paidAt = $transaction['paidAt'] ?? $transaction['createdAt'] ?? date('Y-m-d H:i:s');
            $amount = $transaction['amount'] ?? $pedido['valor'];
            
            error_log("[Webhook] 📊 Dados extraídos - Customer: " . json_encode($customer));
            error_log("[Webhook] 📊 Dados extraídos - Document: " . $customerDocument);
            error_log("[Webhook] 📊 Dados extraídos - Fee: " . json_encode($fee));
            error_log("[Webhook] 📊 Dados extraídos - Amount: " . $amount);
            error_log("[Webhook] 📊 Dados extraídos - PaidAt: " . $paidAt);

            // Manter a estrutura do payload para o utmify conforme estava
            $utmifyData = [
                'orderId' => $transactionId,
                'platform' => 'novaera',
                'paymentMethod' => 'pix',
                'status' => 'paid',
                'createdAt' => $transaction['createdAt'] ?? $pedido['created_at'],
                'approvedDate' => $paidAt,
                'paidAt' => $paidAt,
                'refundedAt' => $transaction['refundedAt'] ?? null,
                'customer' => [
                    'name' => $customer['name'] ?? $pedido['nome'],
                    'email' => $customer['email'] ?? $pedido['email'],
                    'phone' => $customer['phone'] ?? null,
                    'document' => [
                        'number' => $customerDocument ?? $pedido['cpf'],
                        'type' => 'CPF'
                    ],
                    'country' => 'BR',
                    'ip' => $transaction['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null
                ],
                'items' => [
                    [
                        'id' => isset($items[0]) ? ($items[0]['id'] ?? uniqid('PROD_')) : uniqid('PROD_'),
                        'title' => isset($items[0]) ? ($items[0]['title'] ?? getUpsellTitle($amount)) : getUpsellTitle($amount),
                        'quantity' => 1,
                        'unitPrice' => $amount
                    ]
                ],
                'amount' => $amount,
                'fee' => [
                    'fixedAmount' => $fee['fixedAmount'] ?? 0,
                    'netAmount' => $fee['netAmount'] ?? $amount
                ],
                'trackingParameters' => $trackingParameters,
                'isTest' => false
            ];

            error_log("[Webhook] 📦 Payload completo para utmify: " . json_encode($utmifyData));

            // Envia para utmify.php
              // Construir URL do utmify.php de forma robusta com fallbacks
            $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
            
            // Método 1: Usar DOCUMENT_ROOT (mais comum)
            $scriptDir = str_replace($_SERVER['DOCUMENT_ROOT'], '', __DIR__);
            $utmifyUrl = $serverUrl . $scriptDir . "/utmify.php";
            
            // Método 2: Fallback usando SCRIPT_NAME se o método 1 falhar
            if (empty($scriptDir) || $scriptDir === __DIR__) {
                $scriptDir = dirname($_SERVER['SCRIPT_NAME']);
                $utmifyUrl = $serverUrl . $scriptDir . "/utmify.php";
                error_log("[Webhook] ⚠️ Usando fallback SCRIPT_NAME para construir URL");
            }

            $ch = curl_init($utmifyUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($utmifyData),
                CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => false,
                CURLOPT_TIMEOUT => 30
            ]);

            $utmifyResponse = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            
            error_log("[Webhook] 📤 Resposta do utmify (HTTP $httpCode): " . $utmifyResponse);
            if ($curlError) {
                error_log("[Webhook] ❌ Erro ao enviar para utmify: " . $curlError);
            } else {
                error_log("[Webhook] 📊 Resposta decodificada: " . print_r(json_decode($utmifyResponse, true), true));
            }
            
            curl_close($ch);
            
            
            // ===============================
            
            $metaLogFile = __DIR__ . '/meta-log.txt';

function logMeta($msg) {
    global $metaLogFile;
    $time = date('Y-m-d H:i:s');
    file_put_contents($metaLogFile, "[$time] $msg\n", FILE_APPEND);}
    
// 🔥 ENVIO PARA META (CAPI) CORRIGIDO
// ===============================

$metaPixels = [
    ['id' => '1724722095388096', 'token' => 'EAAV8IpEF2JQBR6eq9ZCTcE0ytE1dbtdQxwGGTxR7nLQ447K6YfDiKUC5ZCOSoFrMZAdOQddpn1w6fk2RXUB8kh26pCR7eZBNPUA1drZCLZAtuhkeb4KQZC6n4tRmbaq6WcgZBZAlyzZBwW2cCabdpr4MwNjfH7r8ZApRHbffBGwl6jFlwBnFL2bw1mOw6J9XF7ZCmAZDZD'],
    ['id' => '1019639280613677', 'token' => 'EAAV8IpEF2JQBR8W1xspsXB3qwiMbN5NChjQiJnAEShJzU2ARXXrFcnNU9ZBQeUmB5KHXqENfWGKJSxgQo2PFVPNwsd9cwpS8VTeI7rKcYjmAvFi0fcjaZAzZA4mDNpUhNeXK2pFQANoZBOBZBV12hzI8fbjjNy7UXotDEziLBaLNcHj2UAzAvrZCZCQPPmH6wZDZD'],
];

// 🔥 Corrige valor (centavos → reais)
$valorMeta = $amount;

if ($valorMeta > 1000) {
    $valorMeta = $valorMeta / 100;
}

$valorMeta = round($valorMeta, 2);

logMeta("VALOR FINAL META: " . $valorMeta);

// ===============================
// 🔥 TRATAMENTO DE DADOS
// ===============================

// CPF (corrigido)
if (is_array($customer['document'])) {
    $cpf = $customer['document']['number'] ?? null;
} else {
    $cpf = $customer['document'] ?? $pedido['cpf'] ?? null;
}

// Telefone
$phone = $customer['phone'] ?? $pedido['telefone'] ?? null;

// Email
$email = $customer['email'] ?? $pedido['email'] ?? null;

if (
    !$email ||
    $email === 'cliente@exemplo.com' ||
    strpos($email, 'example.com') !== false
) {
    $email = null;
}

// IP e UserAgent
$ip = $transaction['ip'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
$userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;

// ===============================
// 🔥 HASH PADRÃO META
// ===============================

function hashData($data) {
    if (!$data) return null;
    $data = trim(strtolower($data));
    return hash('sha256', $data);
}

// ===============================
// 🔥 MELHORIA DE DADOS (MATCH QUALITY)
// ===============================

// CPF limpo
$cpf = preg_replace('/\D+/', '', $cpf);

// Nome separado
$nomeCompleto = $customer['name'] ?? $pedido['nome'] ?? null;

$firstName = null;
$lastName = null;

if ($nomeCompleto) {
    $partes = explode(' ', trim($nomeCompleto));
    $firstName = $partes[0] ?? null;
    $lastName = end($partes) ?? null;
}

// FBP (cookie do navegador)
$fbp = $_COOKIE['_fbp'] ?? null;

// ===============================
// 🔥 USER DATA FINAL
// ===============================

$userData = array_filter([
    'em' => hashData($email),
    'external_id' => hashData($cpf),
    'fn' => hashData($firstName),
    'ln' => hashData($lastName),
    'client_ip_address' => $ip,
    'client_user_agent' => $userAgent
]);

// adiciona fbp se existir
if ($fbp) {
    $userData['fbp'] = $fbp;
}

// ===============================
// 🔥 FBC (CORRIGIDO)
// ===============================

if (!empty($utmParams['fbclid'])) {
    $userData['fbc'] = 'fb.1.' . $_SERVER['REQUEST_TIME'] . '.' . $utmParams['fbclid'];
}

// ===============================
// 🔥 EVENTO
// ===============================

$eventData = [
    'event_name' => 'Purchase',
    'event_time' => time(),
    'event_id' => $transactionId,
    'action_source' => 'website',
    'event_source_url' => 'https://' . $_SERVER['HTTP_HOST'],
    'user_data' => $userData,
    'custom_data' => [
        'currency' => 'BRL',
        'value' => $valorMeta
    ]
];

// ===============================
// 🔥 ENVIO
// ===============================

$payloadMeta = [
    'data' => [$eventData]
];

foreach ($metaPixels as $pixel) {
    $url = "https://graph.facebook.com/v19.0/{$pixel['id']}/events?access_token={$pixel['token']}";
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payloadMeta),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json']
    ]);
    $responseMeta = curl_exec($ch);
    $httpCodeMeta = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    logMeta("RESPOSTA META pixel {$pixel['id']} ($httpCodeMeta): " . $responseMeta);
    curl_close($ch);
}
            
            
            
            
            error_log("[Webhook] ✅ Processamento em background concluído");
        } else {
            error_log("[Webhook] ❌ Não foi possível recuperar os dados do pedido do banco");
        }
    } else {
        error_log("[Webhook] ℹ️ Status não é APPROVED ou PAID, pulando processamento em background");
    }

} catch (Exception $e) {
    error_log("[Webhook] ❌ Erro: " . $e->getMessage());
    error_log("[Webhook] 🔍 Stack trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode(['error' => 'Erro interno do servidor']);
}