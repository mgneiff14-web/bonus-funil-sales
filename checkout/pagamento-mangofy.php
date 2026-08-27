<?php
// Headers já definidos pelo roteador pagamento.php

ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);
date_default_timezone_set('America/Sao_Paulo');

// ===================================================================
// CREDENCIAIS MANGOFY — preencher após receber as chaves
// ===================================================================
define('MANGOFY_BASE_URL',   'https://checkout.mangofy.com.br');
define('MANGOFY_API_KEY',    '2117c6b06b47cfb76976f43f153cdc70dtegpg88igjplxz1mwdt7doy2unek8a');
define('MANGOFY_STORE_CODE', 'b90bd91dbd493f1b128f9ed6c53ee00d');
// URL que a Mangofy vai chamar quando o PIX for pago
define('MANGOFY_WEBHOOK_URL', 'https://' . ($_SERVER['HTTP_HOST'] ?? 'seusite.com') . '/checkout/webhook-mangofy.php');
// UTMify
define('MANGOFY_UTMIFY_TOKEN', 'XyT0mCfY6s688d00tGgzFLM0oQ0HuPB9pq7E');
// ===================================================================

function getClientIPMangofy() {
    foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_REAL_IP','HTTP_X_FORWARDED_FOR','HTTP_CLIENT_IP','REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim(explode(',', $_SERVER[$h])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
        }
    }
    return '177.67.128.1';
}

function getProductTitleMangofy(int $valor): string {
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

function sanitizeMgf(string $input, int $max = 255): ?string {
    $s = mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', trim($input)), 0, $max, 'UTF-8');
    return $s === '' ? null : $s;
}

function validateNomeMgf(?string $nome): ?string {
    $nome = sanitizeMgf($nome ?? '', 100);
    if (!$nome) return null;
    return preg_match('/^[\p{L}\s\'\-]+$/u', $nome) ? $nome : null;
}

function validateEmailMgf(?string $email): ?string {
    $email = sanitizeMgf($email ?? '', 255);
    return ($email && filter_var($email, FILTER_VALIDATE_EMAIL)) ? $email : null;
}

function validateCPFMgf(?string $cpf): ?string {
    $cpf = preg_replace('/\D/', '', $cpf ?? '');
    return strlen($cpf) === 11 ? $cpf : null;
}

function gerarNomeMgf(): string {
    static $nF = ['Maria','Ana','Julia','Sofia','Isabella','Helena','Valentina','Laura','Alice','Manuela','Beatriz','Clara'];
    static $nM = ['João','Pedro','Lucas','Miguel','Arthur','Gabriel','Bernardo','Rafael','Gustavo','Felipe','Daniel','Thiago'];
    static $sn = ['Silva','Santos','Oliveira','Souza','Rodrigues','Ferreira','Alves','Pereira','Lima','Gomes','Costa','Ribeiro'];
    $p = rand(0,1) ? $nM[array_rand($nM)] : $nF[array_rand($nF)];
    return "$p {$sn[array_rand($sn)]} {$sn[array_rand($sn)]}";
}

function gerarCPFMgf(): string {
    $d = '';
    for ($i = 0; $i < 9; $i++) $d .= rand(0, 9);
    $s = 0;
    for ($i = 0; $i < 9; $i++) $s += intval($d[$i]) * (10 - $i);
    $r = $s % 11; $d .= $r < 2 ? 0 : 11 - $r;
    $s = 0;
    for ($i = 0; $i < 10; $i++) $s += intval($d[$i]) * (11 - $i);
    $r = $s % 11; $d .= $r < 2 ? 0 : 11 - $r;
    return $d;
}

try {
    $client_ip = getClientIPMangofy();
    $logDir    = __DIR__ . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

    $rawInput       = file_get_contents('php://input');
    $input          = json_decode($rawInput, true) ?? [];
    $valor_centavos = max(1, (int)($input['valor'] ?? $_POST['valor'] ?? 6792));

    // O funil manda os parâmetros dentro de "tracking"; considera essa fonte também
    $tracking = (isset($input['tracking']) && is_array($input['tracking'])) ? $input['tracking'] : [];

    $utmParams = array_filter([
        'utm_source'   => $input['utm_source']   ?? $tracking['utm_source']   ?? $_POST['utm_source']   ?? null,
        'utm_medium'   => $input['utm_medium']   ?? $tracking['utm_medium']   ?? $_POST['utm_medium']   ?? null,
        'utm_campaign' => $input['utm_campaign'] ?? $tracking['utm_campaign'] ?? $_POST['utm_campaign'] ?? null,
        'utm_content'  => $input['utm_content']  ?? $tracking['utm_content']  ?? $_POST['utm_content']  ?? null,
        'utm_term'     => $input['utm_term']     ?? $tracking['utm_term']     ?? $_POST['utm_term']     ?? null,
        'xcod'         => $input['xcod']         ?? $tracking['xcod']         ?? $_POST['xcod']         ?? null,
        'sck'          => $input['sck']          ?? $tracking['sck']          ?? $_POST['sck']          ?? null,
        'src'          => $input['src']          ?? $tracking['src']          ?? $_POST['src']          ?? null,
        // ✅ Identificadores TikTok para atribuição server-side (Events API)
        'ttclid'       => $input['ttclid']       ?? $tracking['ttclid']       ?? $_POST['ttclid']       ?? null,
        'ttp'          => $input['ttp']          ?? $tracking['ttp']          ?? $_POST['ttp']          ?? null,
    ]);

    $fbp       = $input['fbp']       ?? $_POST['fbp']       ?? null;
    $fbc       = $input['fbc']       ?? $_POST['fbc']       ?? null;
    $userAgent = $input['userAgent'] ?? $_POST['userAgent'] ?? $_SERVER['HTTP_USER_AGENT'] ?? null;

    $nomeVal  = validateNomeMgf($input['nome']     ?? $_POST['nome']     ?? null);
    $emailVal = validateEmailMgf($input['email']   ?? $_POST['email']   ?? null);
    $cpfVal   = validateCPFMgf($input['cpf']       ?? $_POST['cpf']     ?? null);
    $telefone = preg_replace('/\D/', '', $input['telefone'] ?? $_POST['telefone'] ?? '') ?: '11999999999';

    if ($nomeVal && $cpfVal) {
        $nome_cliente = $nomeVal;
        $cpf          = $cpfVal;
        $email        = $emailVal ?? strtolower(str_replace([' ','+'], '.', $nome_cliente)) . '@email.com';
    } else {
        $nome_cliente = gerarNomeMgf();
        $cpf          = gerarCPFMgf();
        $email        = strtolower(str_replace(' ', '.', $nome_cliente)) . '@email.com';
        error_log("[Mangofy] ⚠️ Dados reais inválidos — usando fallback: $nome_cliente");
    }

    $produtoTitulo = getProductTitleMangofy($valor_centavos);
    $externalCode  = 'MGF-' . time() . '-' . rand(1000, 9999);

    error_log("[Mangofy] 💰 Valor: $valor_centavos | Produto: $produtoTitulo | Cliente: $nome_cliente");

    // ─── Gerar PIX via Mangofy ───────────────────────────────────────
    $mangofyBody = [
        'external_code'   => $externalCode,
        'payment_method'  => 'pix',
        'payment_format'  => 'regular',
        'installments'    => 1,
        'payment_amount'  => $valor_centavos,
        'shipping_amount' => 0,
        'postback_url'    => MANGOFY_WEBHOOK_URL,
        'items' => [[
            'code'         => 'PROD-' . $valor_centavos,
            'name'         => $produtoTitulo,
            'quantity'     => 1,
            'price'        => $valor_centavos,
            'description'  => $produtoTitulo,
            'digital_flag' => true,
        ]],
        'customer' => [
            'name'     => $nome_cliente,
            'email'    => $email,
            'document' => $cpf,
            'phone'    => $telefone,
            'ip'       => $client_ip,
        ],
        'pix' => ['expires_in_days' => 1],
        'extra' => [
            'metadata' => array_merge(
                $utmParams,
                array_filter(['fbp' => $fbp, 'fbc' => $fbc])
            )
        ],
    ];

    $ch = curl_init(MANGOFY_BASE_URL . '/api/v1/payment');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($mangofyBody, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: ' . MANGOFY_API_KEY,
            'Store-Code: ' . MANGOFY_STORE_CODE,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);
    $apiResp   = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    error_log("[Mangofy] API [$httpCode]: $apiResp");

    if ($curlError)  throw new Exception("cURL error: $curlError");
    if ($httpCode < 200 || $httpCode >= 300) throw new Exception("API HTTP $httpCode: $apiResp");

    $result = json_decode($apiResp, true);
    if (empty($result['payment_code'])) throw new Exception("payment_code ausente: $apiResp");

    $paymentCode = $result['payment_code'];
    $pixQrText   = $result['pix']['pix_qrcode_text']  ?? '';
    $pixQrImage  = $result['pix']['pix_qrcode_image'] ?? '';
    $expiresAt   = $result['pix']['pix_expires_at']   ?? '';

    // ─── Salvar no SQLite ────────────────────────────────────────────
    $dbPath = __DIR__ . '/database.sqlite';
    $db     = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("CREATE TABLE IF NOT EXISTS pedidos (
        transaction_id       TEXT PRIMARY KEY,
        status               TEXT NOT NULL,
        valor                INTEGER NOT NULL,
        nome                 TEXT,
        email                TEXT,
        cpf                  TEXT,
        telefone             TEXT,
        utm_params           TEXT,
        client_ip            TEXT,
        client_user_agent    TEXT,
        fbp                  TEXT,
        fbc                  TEXT,
        created_at           TEXT,
        updated_at           TEXT
    )");
    foreach (['telefone','client_ip','client_user_agent','fbp','fbc'] as $col) {
        try { $db->exec("ALTER TABLE pedidos ADD COLUMN $col TEXT"); } catch (Exception $e) {}
    }

    $stmt = $db->prepare("INSERT OR REPLACE INTO pedidos
        (transaction_id, status, valor, nome, email, cpf, telefone, utm_params, client_ip, client_user_agent, fbp, fbc, created_at, updated_at)
        VALUES (:tid,'pending',:valor,:nome,:email,:cpf,:tel,:utm,:ip,:ua,:fbp,:fbc,:cat,:uat)");
    $stmt->execute([
        'tid'   => $paymentCode,
        'valor' => $valor_centavos,
        'nome'  => $nome_cliente,
        'email' => $email,
        'cpf'   => $cpf,
        'tel'   => $telefone,
        'utm'   => json_encode($utmParams),
        'ip'    => $client_ip,
        'ua'    => $userAgent,
        'fbp'   => $fbp,
        'fbc'   => $fbc,
        'cat'   => date('Y-m-d H:i:s'),
        'uat'   => date('Y-m-d H:i:s'),
    ]);

    error_log("[Mangofy] 💾 Salvo no DB: $paymentCode");

    // ─── UTMify waiting_payment (direto — sem HTTP interno) ──────────
    $utmifyWaiting = [
        'orderId'       => $paymentCode,
        'platform'      => 'PayHubr',
        'paymentMethod' => 'pix',
        'status'        => 'waiting_payment',
        'createdAt'     => gmdate('Y-m-d H:i:s'),
        'approvedDate'  => null,
        'refundedAt'    => null,
        'customer' => [
            'name'       => $nome_cliente,
            'email'      => $email,
            'phone'      => null,
            'document'   => $cpf,
            'country'    => 'BR',
            'ip'         => $client_ip,
            'userAgent'  => $userAgent,
            'externalId' => $paymentCode,
            'fbp'        => $fbp,
            'fbc'        => $fbc,
        ],
        'products' => [[
            'id'           => 'PROD-' . $valor_centavos,
            'name'         => $produtoTitulo,
            'planId'       => null,
            'planName'     => null,
            'quantity'     => 1,
            'priceInCents' => $valor_centavos,
        ]],
        'trackingParameters' => array_merge([
            'src' => null, 'sck' => null, 'utm_source' => null, 'utm_campaign' => null,
            'utm_medium' => null, 'utm_content' => null, 'utm_term' => null,
            'xcod' => null, 'fbclid' => null, 'gclid' => null, 'ttclid' => null,
        ], $utmParams),
        'commission' => [
            'totalPriceInCents'     => $valor_centavos,
            'gatewayFeeInCents'     => 0,
            'userCommissionInCents' => $valor_centavos,
        ],
        'isTest' => false,
    ];

    $chU = curl_init('https://api.utmify.com.br/api-credentials/orders');
    curl_setopt_array($chU, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($utmifyWaiting, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'x-api-token: ' . MANGOFY_UTMIFY_TOKEN,
        ],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $uResp = curl_exec($chU);
    $uCode = curl_getinfo($chU, CURLINFO_HTTP_CODE);
    $uErr  = curl_error($chU);
    curl_close($chU);
    error_log("[Mangofy] UTMify waiting_payment [$uCode]: $uResp");
    // Log dedicado do pendente (diagnóstico): payload enviado + resposta da UTMify
    @file_put_contents(
        $logDir . '/utmify-mangofy-pendente-' . date('Y-m-d') . '.log',
        '[' . date('Y-m-d H:i:s') . "] RECEBIDO DO CHECKOUT (raw):\n" . $rawInput
        . "\n\nPAYLOAD UTMify:\n" . json_encode($utmifyWaiting, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        . "\n[RESP HTTP $uCode]: $uResp" . ($uErr ? " | cURL ERR: $uErr" : '') . "\n" . str_repeat('-', 60) . "\n",
        FILE_APPEND | LOCK_EX
    );

    // ─── Resposta ao frontend (mesmo formato que paradise) ───────────
    echo json_encode([
        'success'       => true,
        'token'         => $paymentCode,
        'pixCode'       => $pixQrText,
        'pixCopiaECola' => $pixQrText,
        'qrCodeUrl'     => $pixQrImage,
        'valor'         => $valor_centavos,
        'expires_at'    => $expiresAt,
    ]);

} catch (Exception $e) {
    error_log("[Mangofy] ❌ Erro: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao gerar o PIX: ' . $e->getMessage(),
    ]);
}
