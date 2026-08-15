<?php
ob_start();
header('Content-Type: application/json');

// Habilita o log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define timezone para horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

// ✅ Rastreamento server-side do TikTok (Events API), equivalente ao antigo Meta CAPI
require_once __DIR__ . '/tiktok-capi.php';

// Recebe o payload do webhook
$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// Log do payload recebido
error_log("[Webhook] 🔄 Iniciando processamento do webhook");
error_log("[Webhook] 📦 Payload recebido: " . $payload);

// Verifica se o payload é válido - Formato Paradise Pags
if (!$event || !isset($event['transaction_id']) || !isset($event['status'])) {
    error_log("[Webhook] ❌ Payload inválido recebido. Campos necessários não encontrados");
    error_log("[Webhook] 🔍 Campos disponíveis: " . print_r(array_keys($event ?? []), true));
    http_response_code(200);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

function getUpsellTitle($valor) {
    switch($valor) {
        // ===== Funil TikTok Recompensas =====
        case 3737: return 'Taxa de saque';
        case 1920: return 'Taxa de IOF';
        case 1721: return 'Certificação Digital';
        case 1699: return 'Emissão de Comprovante';
        case 1499: return 'Upgrade Premium Vitalício';
        case 998:  return 'Conversão de Saldo USD';
        // ===== Demais produtos =====
        case 6792: return 'Liberação de Benefício';
        case 3890: return 'Taxa de Emissão de CCI';
        case 4780: return 'Taxa de Assinatura de Contrato';
        case 4790: return 'Taxa de verificação';
        case 2890: return 'Taxa TENF';
        case 4569: return 'Taxa IOF';
        case 8500: return 'Taxa de Regularização';
        case 1825: return 'Validação Bancaria';
        case 3990: return 'Taxa de Validação';
        case 5573: return 'Front';
        case 2490: return 'Indenização Adicional';
        default:   return 'Produto ' . ($valor / 100);
    }
}

// ✅ CORREÇÃO: Pega o IP real do cliente (IPv4 e IPv6, considerando proxies/CDN)
function getRealClientIp() {
    $headers = [
        'HTTP_CF_CONNECTING_IP',   // Cloudflare
        'HTTP_X_FORWARDED_FOR',    // Proxies / Load balancers
        'HTTP_X_REAL_IP',          // Nginx
        'HTTP_X_FORWARDED',
        'HTTP_FORWARDED_FOR',
        'HTTP_FORWARDED',
        'REMOTE_ADDR'
    ];
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            // X-Forwarded-For pode ter múltiplos IPs separados por vírgula; pega o primeiro
            $ip = trim(explode(',', $_SERVER[$header])[0]);
            // ✅ Aceita IPv4 e IPv6 (removido FILTER_FLAG_NO_PRIV_RANGE para não bloquear IPv6)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? null;
}

// ✅ CORREÇÃO: Pega o User Agent real do cliente
function getRealUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? null;
}

try {
    // Extrair os dados relevantes do formato Paradise Pags
    $transactionId  = $event['transaction_id'];
    $status         = $event['status'];
    $customer       = $event['customer'] ?? [];
    $tracking       = $event['tracking'] ?? [];
    $amount         = $event['amount'] ?? 0;
    $paymentMethod  = $event['payment_method'] ?? 'pix';
    $timestamp      = $event['timestamp'] ?? date('Y-m-d H:i:s');

    error_log("[Webhook] ℹ️ Processando pagamento ID: " . $transactionId . " com status: " . $status);

    // Conecta ao SQLite
    $dbPath = __DIR__ . '/database.sqlite';
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    error_log("[Webhook] ✅ Conexão com banco de dados estabelecida");

    // Coluna de controle para deduplicar o envio do TikTok (garante 1 envio por transação)
    try { $db->exec("ALTER TABLE pedidos ADD COLUMN tiktok_tracked INTEGER DEFAULT 0"); } catch (Exception $e) { /* já existe */ }

    // Atualiza o status do pagamento no banco de dados
    $stmt = $db->prepare("UPDATE pedidos SET status = :status, updated_at = :updated_at WHERE transaction_id = :transaction_id");

    $statusMap = [
        'approved' => 'paid',
        'pending'  => 'pending',
        'failed'   => 'failed',
        'refunded' => 'refunded'
    ];

    $novoStatus = $statusMap[strtolower($status)] ?? strtolower($status);
    error_log("[Webhook] 🔄 Atualizando status para: " . $novoStatus);

    $result = $stmt->execute([
        'status'         => $novoStatus,
        'updated_at'     => date('c'),
        'transaction_id' => $transactionId
    ]);

    if ($stmt->rowCount() === 0) {
        error_log("[Webhook] ⚠️ Nenhum pedido encontrado com o ID: " . $transactionId);

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

    // Chama UTMify/Meta CAPI ANTES de responder ao Paradise
    // Hostinger não suporta background processing — a resposta é enviada no final
    if (strtolower($status) === 'approved') {
        error_log("[Webhook] ✅ Pagamento aprovado, processando UTMify...");

        $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :transaction_id");
        $stmt->execute(['transaction_id' => $transactionId]);
        $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($pedido) {
            error_log("[Webhook] ✅ Dados do pedido recuperados do banco");
            error_log("[Webhook] 📊 Dados do pedido: " . print_r($pedido, true));

            $utmParamsFromDb = json_decode($pedido['utm_params'], true);
            error_log("[Webhook] 📊 UTM Params do banco: " . print_r($utmParamsFromDb, true));
            error_log("[Webhook] 📊 Tracking do webhook: " . print_r($tracking, true));

            $trackingParameters = [
                'src'          => $tracking['src']          ?? $utmParamsFromDb['src']          ?? null,
                'sck'          => $tracking['sck']          ?? $utmParamsFromDb['sck']          ?? null,
                'utm_source'   => $tracking['utm_source']   ?? $utmParamsFromDb['utm_source']   ?? null,
                'utm_campaign' => $tracking['utm_campaign'] ?? $utmParamsFromDb['utm_campaign'] ?? null,
                'utm_medium'   => $tracking['utm_medium']   ?? $utmParamsFromDb['utm_medium']   ?? null,
                'utm_content'  => $tracking['utm_content']  ?? $utmParamsFromDb['utm_content']  ?? null,
                'utm_term'     => $tracking['utm_term']     ?? $utmParamsFromDb['utm_term']     ?? null,
                'fbclid'       => $utmParamsFromDb['fbclid'] ?? null,
                'gclid'        => $utmParamsFromDb['gclid']  ?? null,
                'ttclid'       => $utmParamsFromDb['ttclid'] ?? null,
                'xcod'         => $utmParamsFromDb['xcod']   ?? null,
            ];
            $trackingParameters = array_filter($trackingParameters);

            $customerName     = $customer['name']     ?? $pedido['nome'];
            $customerEmail    = $customer['email']    ?? $pedido['email'];
            $customerDocument = $customer['document'] ?? $pedido['cpf'];
            $customerPhone    = $customer['phone']    ?? $pedido['telefone'] ?? null;
            $finalAmount      = $amount > 0 ? $amount : $pedido['valor'];

            // ✅ IP e User Agent reais do cliente (salvos no banco no momento do checkout)
            // Prioriza dados salvos no banco (capturados no checkout), fallback para o request atual
            $clientIp        = $pedido['client_ip']         ?? getRealClientIp();
            $clientUserAgent = $pedido['client_user_agent'] ?? getRealUserAgent();

            // ✅ fbp e fbc salvos no banco no momento do checkout
            $fbp = $pedido['fbp'] ?? $utmParamsFromDb['fbp'] ?? null;
            $fbc = $pedido['fbc'] ?? $utmParamsFromDb['fbc'] ?? $utmParamsFromDb['fbclid'] ?? null;

            // ✅ External ID: usa o ID do pedido no seu sistema
            $externalId = $pedido['id'] ?? $transactionId;

            error_log("[Webhook] 📊 IP do cliente: " . $clientIp);
            error_log("[Webhook] 📊 User Agent: " . $clientUserAgent);
            error_log("[Webhook] 📊 fbp: " . $fbp);
            error_log("[Webhook] 📊 fbc: " . $fbc);

            $utmifyData = [
                'orderId'       => $transactionId,
                'platform'      => 'ParadisePags',
                'paymentMethod' => $paymentMethod,
                'status'        => 'paid',
                'createdAt'     => $pedido['created_at'],
                'approvedDate'  => $timestamp,
                'paidAt'        => $timestamp,
                'refundedAt'    => null,
                'customer' => [
                    'name'     => $customerName,
                    'email'    => $customerEmail,
                    'phone'    => $customerPhone,
                    'document' => [
                        'number' => $customerDocument,
                        'type'   => 'CPF'
                    ],
                    'country'         => 'BR',
                    'ip'              => $clientIp,           // ✅ IP real do cliente
                    'userAgent'       => $clientUserAgent,    // ✅ User Agent real
                    'externalId'      => (string) $externalId, // ✅ External ID
                    'fbp'             => $fbp,                // ✅ fbp do cookie _fbp
                    'fbc'             => $fbc,                // ✅ fbc do cookie _fbc
                ],
                'items' => [
                    [
                        'id'        => uniqid('PROD_'),
                        'title'     => getUpsellTitle($finalAmount),
                        'quantity'  => 1,
                        'unitPrice' => $finalAmount
                    ]
                ],
                'amount' => $finalAmount,
                'fee' => [
                    'fixedAmount' => 0,
                    'netAmount'   => $finalAmount
                ],
                'trackingParameters' => $trackingParameters,
                'isTest' => false
            ];

            error_log("[Webhook] 📦 Payload completo para utmify: " . json_encode($utmifyData));

            // Chama UTMify DIRETAMENTE (sem HTTP interno — compatível com Hostinger)
            $utmifyApiUrl  = 'https://api.utmify.com.br/api-credentials/orders';
            $utmifyToken   = 'XyT0mCfY6s688d00tGgzFLM0oQ0HuPB9pq7E';
            $utmifyLogFile = __DIR__ . '/logs/utmify-' . date('Y-m-d') . '.log';

            $utmifyPayload = [
                'orderId'       => $utmifyData['orderId'],
                'platform'      => 'PayHubr',
                'paymentMethod' => 'pix',
                'status'        => 'paid',
                'createdAt'     => gmdate('Y-m-d H:i:s', strtotime($utmifyData['createdAt'])),
                'approvedDate'  => gmdate('Y-m-d H:i:s', strtotime($utmifyData['approvedDate'])),
                'refundedAt'    => null,
                'customer'      => [
                    'name'     => $utmifyData['customer']['name'],
                    'email'    => $utmifyData['customer']['email'],
                    'phone'    => null,
                    'document' => $utmifyData['customer']['document']['number'],
                    'country'  => 'BR',
                    'ip'       => $utmifyData['customer']['ip'] ?? '177.67.128.1',
                ],
                'products' => [[
                    'id'           => $utmifyData['items'][0]['id'] ?? uniqid('PROD_'),
                    'name'         => $utmifyData['items'][0]['title'],
                    'planId'       => null,
                    'planName'     => null,
                    'quantity'     => $utmifyData['items'][0]['quantity'],
                    'priceInCents' => $utmifyData['items'][0]['unitPrice'],
                ]],
                'trackingParameters' => [
                    'src'          => $utmifyData['trackingParameters']['src']          ?? null,
                    'sck'          => $utmifyData['trackingParameters']['sck']          ?? null,
                    'utm_source'   => $utmifyData['trackingParameters']['utm_source']   ?? null,
                    'utm_campaign' => $utmifyData['trackingParameters']['utm_campaign'] ?? null,
                    'utm_medium'   => $utmifyData['trackingParameters']['utm_medium']   ?? null,
                    'utm_content'  => $utmifyData['trackingParameters']['utm_content']  ?? null,
                    'utm_term'     => $utmifyData['trackingParameters']['utm_term']     ?? null,
                    'xcod'         => $utmifyData['trackingParameters']['xcod']         ?? null,
                    'fbclid'       => $utmifyData['trackingParameters']['fbclid']       ?? null,
                    'gclid'        => $utmifyData['trackingParameters']['gclid']        ?? null,
                    'ttclid'       => $utmifyData['trackingParameters']['ttclid']       ?? null,
                ],
                'commission' => [
                    'totalPriceInCents'     => $utmifyData['amount'],
                    'gatewayFeeInCents'     => $utmifyData['fee']['fixedAmount'] ?? 0,
                    'userCommissionInCents' => $utmifyData['fee']['netAmount']   ?? $utmifyData['amount'],
                ],
                'isTest' => false,
            ];

            $ts = date('Y-m-d H:i:s');
            file_put_contents($utmifyLogFile,
                "[$ts] Dados formatados para Utmify\nDados: " . json_encode($utmifyPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('-', 40) . "\n",
                FILE_APPEND | LOCK_EX
            );

            $ch = curl_init($utmifyApiUrl);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($utmifyPayload, JSON_UNESCAPED_UNICODE),
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'x-api-token: ' . $utmifyToken,
                ],
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_TIMEOUT        => 30,
            ]);

            $utmifyResponse = curl_exec($ch);
            $httpCode       = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError      = curl_error($ch);
            curl_close($ch);

            file_put_contents($utmifyLogFile,
                "[$ts] Resposta da API Utmify\nDados: " . json_encode(['http_code' => $httpCode, 'response' => json_decode($utmifyResponse, true)], JSON_PRETTY_PRINT) . "\n" . str_repeat('-', 40) . "\n",
                FILE_APPEND | LOCK_EX
            );

            if ($curlError) {
                error_log("[Webhook] ❌ Erro cURL UTMify: " . $curlError);
            } else {
                error_log("[Webhook] 📤 Resposta UTMify (HTTP $httpCode): " . $utmifyResponse);
            }

            // ===== TikTok Events API (server-side) com deduplicação atômica =====
            // Só o PRIMEIRO webhook aprovado desta transação passa por aqui.
            // Se o Paradise reenviar o webhook, o rowCount será 0 e não dispara de novo.
            $claim = $db->prepare("UPDATE pedidos SET tiktok_tracked = 1 WHERE transaction_id = :tid AND (tiktok_tracked IS NULL OR tiktok_tracked = 0)");
            $claim->execute(['tid' => $transactionId]);

            if ($claim->rowCount() === 1) {
                $ttclid = $utmParamsFromDb['ttclid'] ?? null;
                $ttp    = $utmParamsFromDb['ttp']    ?? null;

                enviarEventoTikTok([
                    'event'        => 'CompletePayment',
                    'event_id'     => (string) $transactionId,          // dedup no TikTok
                    'event_time'   => strtotime($timestamp) ?: time(),
                    'value'        => round($finalAmount / 100, 2),      // centavos -> reais
                    'currency'     => 'BRL',
                    'content_id'   => 'PROD-' . $finalAmount,
                    'content_name' => getUpsellTitle((int) $finalAmount),
                    'email'        => $customerEmail,
                    'phone'        => $customerPhone,
                    'ip'           => $clientIp,
                    'user_agent'   => $clientUserAgent,
                    'ttclid'       => $ttclid,
                    'ttp'          => $ttp,
                ]);
                error_log("[Webhook] ✅ TikTok CAPI enviado (1x) para $transactionId");
            } else {
                error_log("[Webhook] ⏭️ TikTok CAPI já havia sido enviado para $transactionId — pulando (dedup)");
            }

            error_log("[Webhook] ✅ Processamento concluído");
        } else {
            error_log("[Webhook] ❌ Não foi possível recuperar os dados do pedido do banco");
        }
    } else {
        error_log("[Webhook] ℹ️ Status não é APPROVED, pulando processamento UTMify");
    }

    // Responde ao Paradise APÓS todo o processamento (garante que UTMify executou)
    http_response_code(200);
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log("[Webhook] ❌ Erro: " . $e->getMessage());
    error_log("[Webhook] 🔍 Stack trace: " . $e->getTraceAsString());
    http_response_code(200);
    echo json_encode(['error' => 'Erro interno do servidor']);
}
