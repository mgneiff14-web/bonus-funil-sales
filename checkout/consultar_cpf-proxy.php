<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET');
header('Access-Control-Allow-Headers: Content-Type');

// Aceita CPF via JSON (POST), POST form ou GET
$cpf = '';
$input = json_decode(file_get_contents('php://input'), true);
if (is_array($input) && isset($input['cpf'])) {
    $cpf = $input['cpf'];
} elseif (isset($_POST['cpf'])) {
    $cpf = $_POST['cpf'];
} elseif (isset($_GET['cpf'])) {
    $cpf = $_GET['cpf'];
}

// Limpar e validar CPF
$cpf = preg_replace('/[^0-9]/', '', $cpf);
if (strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF inválido']);
    exit;
}

// Configurações da API
$token = "4097";
$url = "https://searchapi.it.com/consulta?token_api={$token}&cpf={$cpf}";

$response = false;
$statusCode = 200;

// --- Tenta via cURL (com SSL desabilitado, funciona em host gringo) ---
if (function_exists('curl_init')) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Accept: application/json',
        'Content-Type: application/json',
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
    ]);
    $response = curl_exec($ch);
    if (curl_errno($ch)) {
        $response = false;
    } else {
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    curl_close($ch);
}

// --- Fallback: file_get_contents com SSL off ---
if ($response === false) {
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => 20,
            'ignore_errors' => true,
            'header' => "Accept: application/json\r\nUser-Agent: Mozilla/5.0\r\n"
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true
        ]
    ]);
    $response = @file_get_contents($url, false, $context);
    if (isset($http_response_header[0])) {
        $parts = explode(' ', $http_response_header[0]);
        if (count($parts) >= 2 && is_numeric($parts[1])) {
            $statusCode = (int) $parts[1];
        }
    }
}

if ($response === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao consultar API externa']);
    exit;
}

if ($statusCode !== 200) {
    http_response_code($statusCode);
    echo json_encode(['error' => 'Erro na API externa', 'status' => $statusCode]);
    exit;
}

// Decodificar resposta
$data = json_decode($response, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao decodificar resposta da API']);
    exit;
}

// Verificar se tem dados válidos
if (!isset($data['dados']) || empty($data['dados'])) {
    http_response_code(404);
    echo json_encode(['error' => 'CPF não encontrado']);
    exit;
}

// Pegar o primeiro resultado
$dadosCpf = $data['dados'][0];

// Formatar resposta no formato que o frontend espera
$responseFormatted = [
    'data' => [
        'nome'     => $dadosCpf['NOME'] ?? '',
        'nome_mae' => $dadosCpf['NOME_MAE'] ?? '',
        'sexo'     => $dadosCpf['SEXO'] ?? '',
        'nasc'     => isset($dadosCpf['NASC']) ? convertDateFormat($dadosCpf['NASC']) : '',
        'cpf'      => $dadosCpf['CPF'] ?? $cpf
    ]
];

echo json_encode($responseFormatted);

// Converte data DD/MM/YYYY para YYYY-MM-DD
function convertDateFormat($date) {
    if (empty($date)) return '';
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
        return $matches[3] . '-' . $matches[2] . '-' . $matches[1] . ' 00:00:00';
    }
    return $date;
}
?>
