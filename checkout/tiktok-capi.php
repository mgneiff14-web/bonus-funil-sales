<?php
/**
 * TikTok Events API (server-side) — envio de conversões, equivalente ao Meta CAPI.
 *
 * ➜ COMO CONFIGURAR:
 *   Para cada pixel que tiver a "Events API" habilitada no TikTok Events Manager,
 *   cole o access_token correspondente abaixo. Pixel com token vazio é IGNORADO.
 *   (Você pode habilitar em: Events Manager > seu pixel > Settings > Events API > Generate Access Token)
 *
 * ➜ PRECISÃO:
 *   - event_id = transaction_id  -> o TikTok deduplica; nunca conta a mesma venda 2x.
 *   - Disparado só pelo webhook (quando o pagamento é aprovado) -> nunca perde venda
 *     e não depende do navegador do cliente.
 */

// ===================================================================
// CONFIG: pixels + access tokens (os 4 pixels são os mesmos do funil)
// ===================================================================
$GLOBALS['TIKTOK_PIXELS'] = [
    ['pixel_code' => 'D9V9RLRC77UFF28NGI6G', 'access_token' => '67bced1fb5de844022ad6b7e7a893f5388c1fbed'],
];

// ⚠️ CÓDIGO DE TESTE:
//    Enquanto isto estiver preenchido, os eventos vão para "Test Events" no TikTok
//    e NÃO contam como conversão real. Depois de validar, DEIXE VAZIO ('') para produção.
$GLOBALS['TIKTOK_TEST_EVENT_CODE'] = '';
// ===================================================================

/**
 * Faz o hash SHA-256 exigido pelo TikTok (email/telefone).
 * Retorna null se vazio.
 */
function tiktokHash($valor) {
    $valor = strtolower(trim((string) $valor));
    return $valor === '' ? null : hash('sha256', $valor);
}

/**
 * Normaliza telefone para o formato E.164 (padrão Brasil) antes do hash.
 */
function tiktokPhoneE164($phone) {
    $d = preg_replace('/\D/', '', (string) $phone);
    if ($d === '') return null;
    // Se não tiver DDI, assume Brasil (55)
    if (strlen($d) <= 11) {
        $d = '55' . $d;
    }
    return '+' . $d;
}

/**
 * Envia UM evento para a TikTok Events API em todos os pixels configurados (com token).
 *
 * $data = [
 *   'event'        => 'CompletePayment',
 *   'event_id'     => (string) transaction_id,   // idempotência/dedup
 *   'event_time'   => (int) unix seconds,
 *   'value'        => (float) valor em REAIS,
 *   'currency'     => 'BRL',
 *   'content_id'   => string,
 *   'content_name' => string,
 *   'email'        => string (texto puro; será hasheado),
 *   'phone'        => string (texto puro; será normalizado + hasheado),
 *   'ip'           => string,
 *   'user_agent'   => string,
 *   'ttclid'       => string,
 *   'ttp'          => string,
 *   'url'          => string,
 * ]
 */
function enviarEventoTikTok(array $data): void {
    $pixels = $GLOBALS['TIKTOK_PIXELS'] ?? [];

    $notEmpty = function ($v) { return $v !== null && $v !== ''; };

    $user = array_filter([
        'email'      => tiktokHash($data['email'] ?? null),
        'phone'      => tiktokHash(tiktokPhoneE164($data['phone'] ?? null)),
        'ip'         => $data['ip'] ?? null,
        'user_agent' => $data['user_agent'] ?? null,
        'ttclid'     => $data['ttclid'] ?? null,
        'ttp'        => $data['ttp'] ?? null,
    ], $notEmpty);

    $properties = array_filter([
        'currency' => $data['currency'] ?? 'BRL',
        'value'    => isset($data['value']) ? (float) $data['value'] : null,
        'contents' => [array_filter([
            'content_id'   => $data['content_id'] ?? null,
            'content_type' => 'product',
            'content_name' => $data['content_name'] ?? null,
        ], $notEmpty)],
    ], function ($v) { return $v !== null; });

    $event = [
        'event'      => $data['event'] ?? 'CompletePayment',
        'event_time' => (int) ($data['event_time'] ?? time()),
        'event_id'   => (string) ($data['event_id'] ?? ''),
        'user'       => $user,
        'properties' => $properties,
    ];
    if (!empty($data['url'])) {
        $event['page'] = ['url' => $data['url']];
    }

    foreach ($pixels as $p) {
        if (empty($p['access_token'])) {
            continue; // pixel sem token configurado -> não envia
        }

        $body = [
            'event_source'    => 'web',
            'event_source_id' => $p['pixel_code'],
            'data'            => [$event],
        ];

        // Código de teste (aparece em "Test Events"; remover em produção)
        if (!empty($GLOBALS['TIKTOK_TEST_EVENT_CODE'])) {
            $body['test_event_code'] = $GLOBALS['TIKTOK_TEST_EVENT_CODE'];
        }

        $ch = curl_init('https://business-api.tiktok.com/open_api/v1.3/event/track/');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Access-Token: ' . $p['access_token'],
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 15,
        ]);
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        error_log("[TikTok CAPI] pixel {$p['pixel_code']} [$code] event={$event['event']} id={$event['event_id']} value=" . ($data['value'] ?? '') . " resp=$resp err=$err");
    }
}
