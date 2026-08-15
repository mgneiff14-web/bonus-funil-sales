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
$logFile = $logDir . '/webhook-mangofy-' . date('Y-m-d') . '.log';
file_put_contents($logFile,
    '[' . date('Y-m-d H:i:s') . '] ' . $payload . "\n" . str_repeat('-', 60) . "\n",
    FILE_APPEND | LOCK_EX
);
error_log("[Mangofy Webhook] Payload: $payload");

if (!$event || !isset($event['payment_code'], $event['payment_status'])) {
    http_response_code(200);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

$paymentCode   = $event['payment_code'];
$paymentStatus = strtolower($event['payment_status']);
$paymentAmount = (int)($event['payment_amount'] ?? 0);
$customer      = $event['customer']    ?? [];
$metadata      = $event['metadata']    ?? [];
$approvedAt    = $event['approved_at'] ?? null;
$createdAt     = $event['created_at']  ?? date('Y-m-d H:i:s');

function getProductTitleMangofyWh(int $valor): string {
    switch ($valor) {
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
        default:    return 'Produto ' . number_format($valor / 100, 2, ',', '.');
    }
}

try {
    $dbPath = __DIR__ . '/database.sqlite';
    $db     = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    if ($paymentStatus !== 'approved') {
        $stmt = $db->prepare("UPDATE pedidos SET status = :s, updated_at = :u WHERE transaction_id = :tid");
        $stmt->execute(['s' => $paymentStatus, 'u' => date('Y-m-d H:i:s'), 'tid' => $paymentCode]);
        error_log("[Mangofy Webhook] Status '$paymentStatus' registrado para $paymentCode");
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }

    // ─── APPROVED ────────────────────────────────────────────────────

    // Atualiza DB
    $stmt = $db->prepare("UPDATE pedidos SET status = 'paid', updated_at = :u WHERE transaction_id = :tid");
    $stmt->execute(['u' => date('Y-m-d H:i:s'), 'tid' => $paymentCode]);

    // Busca dados salvos no checkout
    $stmt = $db->prepare("SELECT * FROM pedidos WHERE transaction_id = :tid");
    $stmt->execute(['tid' => $paymentCode]);
    $pedido = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$pedido) {
        // Este pagamento pertence a outro fluxo (ex: checkoutup) — não processar aqui
        error_log("[Mangofy Webhook] ℹ️ Pedido $paymentCode não encontrado neste DB — ignorando (pertence a outro webhook)");
        http_response_code(200);
        echo json_encode(['success' => true]);
        exit;
    }

    $utmParamsDb = json_decode($pedido['utm_params'] ?? '{}', true) ?: [];
    // DB é a fonte garantida (salvo em centavos no checkout); webhook é fallback
    $finalAmount = ((int)$pedido['valor'] > 0)
        ? (int)$pedido['valor']
        : ($paymentAmount > 0 ? $paymentAmount : 0);
    error_log("[Mangofy Webhook] 💰 finalAmount=$finalAmount (DB={$pedido['valor']} webhook=$paymentAmount)");

    // Merge de tracking: metadata do webhook tem prioridade (passado no extra.metadata)
    $trackingParams = [
        'utm_source'   => $metadata['utm_source']   ?? $utmParamsDb['utm_source']   ?? null,
        'utm_medium'   => $metadata['utm_medium']   ?? $utmParamsDb['utm_medium']   ?? null,
        'utm_campaign' => $metadata['utm_campaign'] ?? $utmParamsDb['utm_campaign'] ?? null,
        'utm_content'  => $metadata['utm_content']  ?? $utmParamsDb['utm_content']  ?? null,
        'utm_term'     => $metadata['utm_term']     ?? $utmParamsDb['utm_term']     ?? null,
        'src'          => $metadata['src']          ?? $utmParamsDb['src']          ?? null,
        'sck'          => $metadata['sck']          ?? $utmParamsDb['sck']          ?? null,
        'xcod'         => $metadata['xcod']         ?? $utmParamsDb['xcod']         ?? null,
        'fbclid'       => $metadata['fbclid']       ?? $utmParamsDb['fbclid']       ?? null,
        'gclid'        => $utmParamsDb['gclid']     ?? null,
        'ttclid'       => $utmParamsDb['ttclid']    ?? null,
    ];

    $clientName  = $customer['name']     ?? $pedido['nome']          ?? '';
    $clientEmail = $customer['email']    ?? $pedido['email']         ?? '';
    $clientDoc   = $customer['document'] ?? $pedido['cpf']           ?? '';
    $clientIp    = $pedido['client_ip']  ?? '177.67.128.1';
    $fbp         = $metadata['fbp']      ?? $pedido['fbp']           ?? null;
    $fbc         = $metadata['fbc']      ?? $pedido['fbc']           ?? null;
    $userAgent   = $pedido['client_user_agent'] ?? null;

    $produtoTitulo = getProductTitleMangofyWh($finalAmount);

    // ─── Payload UTMify ──────────────────────────────────────────────
    $utmifyData = [
        'orderId'       => $paymentCode,
        'platform'      => 'PayHubr',
        'paymentMethod' => 'pix',
        'status'        => 'paid',
        'createdAt'     => gmdate('Y-m-d H:i:s', strtotime($createdAt)),
        'approvedDate'  => gmdate('Y-m-d H:i:s', strtotime($approvedAt ?? 'now')),
        'refundedAt'    => null,
        'customer' => [
            'name'       => $clientName,
            'email'      => $clientEmail,
            'phone'      => null,
            'document'   => $clientDoc,
            'country'    => 'BR',
            'ip'         => $clientIp,
            'userAgent'  => $userAgent,
            'externalId' => $paymentCode,
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

    $utmifyLogFile = $logDir . '/utmify-mangofy-' . date('Y-m-d') . '.log';
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
        error_log("[Mangofy Webhook] ❌ UTMify error: $utmifyError");
    } else {
        error_log("[Mangofy Webhook] ✅ UTMify [$utmifyCode]: $utmifyResp");
    }

    // ===== TikTok Events API (server-side) com deduplicação atômica =====
    // Só o PRIMEIRO webhook aprovado desta transação dispara (evita contar 2x em reenvio).
    try { $db->exec("ALTER TABLE pedidos ADD COLUMN tiktok_tracked INTEGER DEFAULT 0"); } catch (Exception $e) {}
    $claim = $db->prepare("UPDATE pedidos SET tiktok_tracked = 1 WHERE transaction_id = :tid AND (tiktok_tracked IS NULL OR tiktok_tracked = 0)");
    $claim->execute(['tid' => $paymentCode]);
    if ($claim->rowCount() === 1) {
        enviarEventoTikTok([
            'event'        => 'CompletePayment',
            'event_id'     => (string) $paymentCode,
            'event_time'   => strtotime($approvedAt ?? 'now') ?: time(),
            'value'        => round($finalAmount / 100, 2),
            'currency'     => 'BRL',
            'content_id'   => 'PROD-' . $finalAmount,
            'content_name' => getProductTitleMangofyWh((int) $finalAmount),
            'email'        => $clientEmail,
            'phone'        => $pedido['telefone'] ?? null,
            'ip'           => $clientIp,
            'user_agent'   => $userAgent,
            'ttclid'       => $utmParamsDb['ttclid'] ?? null,
            'ttp'          => $utmParamsDb['ttp'] ?? null,
        ]);
        error_log("[Mangofy Webhook] ✅ TikTok CAPI enviado (1x) para $paymentCode");
    } else {
        error_log("[Mangofy Webhook] ⏭️ TikTok CAPI já enviado para $paymentCode — pulando (dedup)");
    }

    error_log("[Mangofy Webhook] ✅ Pagamento $paymentCode processado");

} catch (Exception $e) {
    error_log("[Mangofy Webhook] ❌ Erro: " . $e->getMessage());
}

// Responde à Mangofy APÓS todo o processamento
http_response_code(200);
echo json_encode(['success' => true]);
