<?php
ob_start();
header('Content-Type: application/json');
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

define('UTMIFY_TOKEN', 'XyT0mCfY6s688d00tGgzFLM0oQ0HuPB9pq7E');
define('UTMIFY_URL',   'https://api.utmify.com.br/api-credentials/orders');

// ✅ Rastreamento server-side do TikTok (Events API)
require_once __DIR__ . '/tiktok-capi.php';

$payload = file_get_contents('php://input');
$event   = json_decode($payload, true);

// Log raw payload imediatamente
$logDir  = __DIR__ . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$logFile = $logDir . '/webhook-flevopay-' . date('Y-m-d') . '.log';
file_put_contents($logFile,
    '[' . date('Y-m-d H:i:s') . '] ' . $payload . "\n" . str_repeat('-', 60) . "\n",
    FILE_APPEND | LOCK_EX
);
error_log("[FlevoPay Webhook] Payload: $payload");

if (!$event || !isset($event['transaction_id'], $event['status'])) {
    http_response_code(200);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

$transactionId = $event['transaction_id'];
$status        = strtolower($event['status']);
$amount        = (int)($event['amount'] ?? 0);
$customer      = $event['customer']  ?? [];
$tracking      = $event['tracking']  ?? [];
$timestamp     = $event['timestamp'] ?? date('Y-m-d H:i:s');

// ─── Nomes de produto: pagamento-pix (front) ─────────────────────────
function getProdutoFront(int $v): string {
    switch ($v) {
        // ===== Funil TikTok Recompensas =====
        case 3737: return 'Taxa de saque';
        case 1920: return 'Taxa de IOF';
        case 1721: return 'Certificação Digital';
        case 1699: return 'Emissão de Comprovante';
        case 1499: return 'Upgrade Premium Vitalício';
        case 998:  return 'Conversão de Saldo USD';
        // ===== Demais produtos =====
        case 13789: return 'Liberação de Benefício';
        case 6792:  return 'Liberação de Benefício';
        case 3890:  return 'Taxa de Emissão de CCI';
        case 4780:  return 'Taxa de Assinatura de Contrato';
        case 4790:  return 'Taxa de verificação';
        case 2890:  return 'Taxa TENF';
        case 4569:  return 'Taxa IOF';
        case 8500:  return 'Taxa de Regularização';
        case 1825:  return 'Validação Bancária';
        case 3990:  return 'Taxa de Validação';
        case 5573:  return 'Front';
        case 2490:  return 'Indenização Adicional';
        case 3995:  return 'SMS';
        case 1970:  return 'Upsell 2';
        case 3980:  return 'Upsell 5';
        case 1790:  return 'Upsell 3';
        case 1890:  return 'Upsell 6';
        default:    return 'Produto ' . number_format($v / 100, 2, ',', '.');
    }
}

function abrirDb(string $path): ?PDO {
    if (!file_exists($path)) return null;
    $db = new PDO("sqlite:$path");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return $db;
}

try {
    $dbPath = __DIR__ . '/database.sqlite';
    $db     = abrirDb($dbPath);

    if (!$db) {
        error_log("[FlevoPay Webhook] ⚠️ DB não encontrado: $dbPath");
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }

    // Busca o pedido no DB
    $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :tid");
    $stmt->execute(['tid' => $transactionId]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        // Este pagamento pertence a outro fluxo (ex: checkoutup) — não processar aqui
        error_log("[FlevoPay Webhook] ℹ️ Transação $transactionId não encontrada neste DB — ignorando (pertence a outro webhook)");
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }

    // Status não-aprovado: atualiza DB e sai
    if ($status !== 'approved') {
        $db->prepare("UPDATE pedidos SET status = :s, updated_at = :u WHERE transaction_id = :tid")
           ->execute(['s' => $status, 'u' => date('Y-m-d H:i:s'), 'tid' => $transactionId]);
        error_log("[FlevoPay Webhook] Status '$status' registrado para $transactionId");
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── APPROVED ─────────────────────────────────────────────────────

    // Atualiza status no DB
    $db->prepare("UPDATE pedidos SET status = 'paid', updated_at = :u WHERE transaction_id = :tid")
       ->execute(['u' => date('Y-m-d H:i:s'), 'tid' => $transactionId]);

    // DB é a fonte garantida (centavos); webhook é fallback
    $finalAmount = ((int)$pedido['valor'] > 0)
        ? (int)$pedido['valor']
        : ($amount > 0 ? $amount : 0);
    error_log("[FlevoPay Webhook] 💰 finalAmount=$finalAmount (DB={$pedido['valor']} webhook=$amount)");

    $utmParamsDb = json_decode($pedido['utm_params'] ?? '{}', true) ?: [];

    $trackingParams = [
        'utm_source'   => $tracking['utm_source']   ?? $utmParamsDb['utm_source']   ?? null,
        'utm_medium'   => $tracking['utm_medium']   ?? $utmParamsDb['utm_medium']   ?? null,
        'utm_campaign' => $tracking['utm_campaign'] ?? $utmParamsDb['utm_campaign'] ?? null,
        'utm_content'  => $tracking['utm_content']  ?? $utmParamsDb['utm_content']  ?? null,
        'utm_term'     => $tracking['utm_term']     ?? $utmParamsDb['utm_term']     ?? null,
        'src'          => $tracking['src']          ?? $utmParamsDb['src']          ?? null,
        'sck'          => $tracking['sck']          ?? $utmParamsDb['sck']          ?? null,
        'fbclid'       => $utmParamsDb['fbclid']    ?? null,
        'gclid'        => $utmParamsDb['gclid']     ?? null,
        'ttclid'       => $utmParamsDb['ttclid']    ?? null,
    ];

    $clientName  = $customer['name']     ?? $pedido['nome']     ?? '';
    $clientEmail = $customer['email']    ?? $pedido['email']    ?? '';
    $clientDoc   = $customer['document'] ?? $pedido['cpf']      ?? '';
    $clientIp    = $pedido['client_ip']  ?? '177.67.128.1';
    $fbp         = $pedido['fbp']        ?? null;
    $fbc         = $pedido['fbc']        ?? null;
    $userAgent   = $pedido['client_user_agent'] ?? null;

    $produtoTitulo = getProdutoFront($finalAmount);

    // ─── Payload UTMify ───────────────────────────────────────────────
    $utmifyData = [
        'orderId'       => $transactionId,
        'platform'      => 'PayHubr',
        'paymentMethod' => 'pix',
        'status'        => 'paid',
        'createdAt'     => gmdate('Y-m-d H:i:s', strtotime($pedido['created_at'])),
        'approvedDate'  => gmdate('Y-m-d H:i:s', strtotime($timestamp)),
        'refundedAt'    => null,
        'customer' => [
            'name'       => $clientName,
            'email'      => $clientEmail,
            'phone'      => null,
            'document'   => $clientDoc,
            'country'    => 'BR',
            'ip'         => $clientIp,
            'userAgent'  => $userAgent,
            'externalId' => $transactionId,
            'fbp'        => $fbp,
            'fbc'        => $fbc,
        ],
        'products' => [[
            'id'           => 'PROD-' . $finalAmount,
            'name'         => $produtoTitulo,
            'planId'       => null,
            'planName'     => null,
            'quantity'     => 1,
            'priceInCents' => $finalAmount,
        ]],
        'trackingParameters' => $trackingParams,
        'commission' => [
            'totalPriceInCents'     => $finalAmount,
            'gatewayFeeInCents'     => 0,
            'userCommissionInCents' => $finalAmount,
        ],
        'isTest' => false,
    ];

    $utmifyLogFile = $logDir . '/utmify-flevopay-' . date('Y-m-d') . '.log';
    $ts = date('Y-m-d H:i:s');
    file_put_contents($utmifyLogFile,
        "[$ts] Payload UTMify\n" . json_encode($utmifyData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n" . str_repeat('-', 60) . "\n",
        FILE_APPEND | LOCK_EX
    );

    // ─── Chama UTMify (síncrono — antes de responder) ────────────────
    $ch = curl_init(UTMIFY_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($utmifyData, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-token: ' . UTMIFY_TOKEN,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
    ]);
    $utmifyResp  = curl_exec($ch);
    $utmifyCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $utmifyError = curl_error($ch);
    curl_close($ch);

    file_put_contents($utmifyLogFile,
        "[$ts] UTMify [$utmifyCode]: $utmifyResp\n" . str_repeat('-', 60) . "\n",
        FILE_APPEND | LOCK_EX
    );

    if ($utmifyError) {
        error_log("[FlevoPay Webhook] ❌ UTMify error: $utmifyError");
    } else {
        error_log("[FlevoPay Webhook] ✅ UTMify [$utmifyCode]: $utmifyResp");
    }

    // ===== TikTok Events API (server-side) com deduplicação atômica =====
    // Só o PRIMEIRO webhook aprovado desta transação dispara (evita contar 2x em reenvio).
    try { $db->exec("ALTER TABLE pedidos ADD COLUMN tiktok_tracked INTEGER DEFAULT 0"); } catch (Exception $e) {}
    $claim = $db->prepare("UPDATE pedidos SET tiktok_tracked = 1 WHERE transaction_id = :tid AND (tiktok_tracked IS NULL OR tiktok_tracked = 0)");
    $claim->execute(['tid' => $transactionId]);
    if ($claim->rowCount() === 1) {
        enviarEventoTikTok([
            'event'        => 'CompletePayment',
            'event_id'     => (string) $transactionId,
            'event_time'   => strtotime($timestamp) ?: time(),
            'value'        => round($finalAmount / 100, 2),
            'currency'     => 'BRL',
            'content_id'   => 'PROD-' . $finalAmount,
            'content_name' => getProdutoFront((int) $finalAmount),
            'email'        => $clientEmail,
            'phone'        => $pedido['telefone'] ?? null,
            'ip'           => $clientIp,
            'user_agent'   => $userAgent,
            'ttclid'       => $utmParamsDb['ttclid'] ?? null,
            'ttp'          => $utmParamsDb['ttp'] ?? null,
        ]);
        error_log("[FlevoPay Webhook] ✅ TikTok CAPI enviado (1x) para $transactionId");
    } else {
        error_log("[FlevoPay Webhook] ⏭️ TikTok CAPI já enviado para $transactionId — pulando (dedup)");
    }

    error_log("[FlevoPay Webhook] ✅ Pagamento $transactionId processado");

} catch (Exception $e) {
    error_log("[FlevoPay Webhook] ❌ Erro: " . $e->getMessage());
}

// Responde à FlevoPay APÓS todo o processamento
http_response_code(200);
echo json_encode(['success' => true]);
