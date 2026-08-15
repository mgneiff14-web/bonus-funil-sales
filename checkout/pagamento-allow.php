<?php
// Habilita o log de erros
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

// Função para gerar CPF válido
function gerarCPF() {
    $cpf = '';
    for ($i = 0; $i < 9; $i++) {
        $cpf .= rand(0, 9);
    }

    $soma = 0;
    for ($i = 0; $i < 9; $i++) {
        $soma += intval($cpf[$i]) * (10 - $i);
    }
    $resto = $soma % 11;
    $digito1 = ($resto < 2) ? 0 : 11 - $resto;
    $cpf .= $digito1;

    $soma = 0;
    for ($i = 0; $i < 10; $i++) {
        $soma += intval($cpf[$i]) * (11 - $i);
    }
    $resto = $soma % 11;
    $digito2 = ($resto < 2) ? 0 : 11 - $resto;
    $cpf .= $digito2;

    $invalidos = [
        '00000000000', '11111111111', '22222222222', '33333333333', 
        '44444444444', '55555555555', '66666666666', '77777777777', 
        '88888888888', '99999999999'
    ];

    if (in_array($cpf, $invalidos)) {
        return gerarCPF();
    }

    return $cpf;
}

try {

    // Recebe dados JSON do body da requisição
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Função para buscar campo em JSON, POST ou GET
    function getRequestField($field) {
        global $input;
        if (isset($input[$field]) && $input[$field] !== '') return $input[$field];
        if (isset($_POST[$field]) && $_POST[$field] !== '') return $_POST[$field];
        if (isset($_GET[$field]) && $_GET[$field] !== '') return $_GET[$field];
        return null;
    }
    
    // Captura todos os campos esperados
    $valor_centavos = getRequestField('valor');
    $nome_cliente = getRequestField('nome') ?? getRequestField('name');
    $email = getRequestField('email');
    $cpf = getRequestField('cpf') ?? getRequestField('document');
    $telefone = getRequestField('telefone') ?? getRequestField('telephone');
    
    // Parâmetros UTM
    $utmParams = [
        'utm_source' => getRequestField('utm_source'),
        'utm_medium' => getRequestField('utm_medium'),
        'utm_campaign' => getRequestField('utm_campaign'),
        'utm_content' => getRequestField('utm_content'),
        'utm_term' => getRequestField('utm_term'),
        'xcod' => getRequestField('xcod'),
        'sck' => getRequestField('sck'),
        'src' => getRequestField('src'),
        'utm_id' => getRequestField('utm_id')
    ];
    $utmParams = array_filter($utmParams, function($value) {
        return $value !== null && $value !== '';
    });
    error_log("[Pagamento] 📦 Dados recebidos: " . json_encode([
        'valor' => $valor_centavos,
        'nome' => $nome_cliente,
        'email' => $email,
        'cpf' => $cpf,
        'telefone' => $telefone,
        'utm' => $utmParams
    ]));

    // Conecta ao SQLite (arquivo de banco de dados)
    $dbPath = __DIR__ . '/database.sqlite'; // Caminho para o arquivo SQLite
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Verifica se a tabela 'pedidos' existe e cria se necessário
    $db->exec("CREATE TABLE IF NOT EXISTS pedidos (
        transaction_id TEXT PRIMARY KEY,
        status TEXT NOT NULL,
        valor INTEGER NOT NULL,
        nome TEXT,
        email TEXT,
        cpf TEXT,
        utm_params TEXT,
        created_at TEXT,
        updated_at TEXT
    )");

    $valor = 6190; // Valor dinâmico ou fallback
    // Valor padrão se não vier
    if (!$valor_centavos || $valor_centavos <= 0) {
        $valor_centavos = 6190;
        error_log("[Pagamento] ⚠️ Valor não recebido, usando padrão: " . $valor_centavos . " centavos");
    }
    $valor = $valor_centavos;
    if (!$valor || $valor <= 0) {
        throw new Exception('Valor inválido');
    }

    // Gera dados do cliente
    $nomes_masculinos = [
        'João', 'Pedro', 'Lucas', 'Miguel', 'Arthur', 'Gabriel', 'Bernardo', 'Rafael',
        'Gustavo', 'Felipe', 'Daniel', 'Matheus', 'Bruno', 'Thiago', 'Carlos'
    ];

    $nomes_femininos = [
        'Maria', 'Ana', 'Julia', 'Sofia', 'Isabella', 'Helena', 'Valentina', 'Laura',
        'Alice', 'Manuela', 'Beatriz', 'Clara', 'Luiza', 'Mariana', 'Sophia'
    ];

    $sobrenomes = [
        'Silva', 'Santos', 'Oliveira', 'Souza', 'Rodrigues', 'Ferreira', 'Alves', 
        'Pereira', 'Lima', 'Gomes', 'Costa', 'Ribeiro', 'Martins', 'Carvalho', 
        'Almeida', 'Lopes', 'Soares', 'Fernandes', 'Vieira', 'Barbosa'
    ];

    // Parâmetros UTM
     $utmParams = [
        'utm_source' => $input['utm_source'] ?? $_POST['utm_source'] ?? $_GET['utm_source'] ?? null,
        'utm_medium' => $input['utm_medium'] ?? $_POST['utm_medium'] ?? $_GET['utm_medium'] ?? null,
        'utm_campaign' => $input['utm_campaign'] ?? $_POST['utm_campaign'] ?? $_GET['utm_campaign'] ?? null,
        'utm_content' => $input['utm_content'] ?? $_POST['utm_content'] ?? $_GET['utm_content'] ?? null,
        'utm_term' => $input['utm_term'] ?? $_POST['utm_term'] ?? $_GET['utm_term'] ?? null,
        'xcod' => $input['xcod'] ?? $_POST['xcod'] ?? $_GET['xcod'] ?? null,
        'sck' => $input['sck'] ?? $_POST['sck'] ?? $_GET['sck'] ?? null,
        'src' => $input['src'] ?? $_POST['src'] ?? $_GET['src'] ?? null,
        'utm_id' => $input['utm_id'] ?? $_POST['utm_id'] ?? $_GET['utm_id'] ?? null
    ];

    $utmParams = array_filter($utmParams, function($value) {
        return $value !== null && $value !== '';
    });

    error_log("[Pagamento] 📊 Parâmetros UTM recebidos: " . json_encode($utmParams));

    $utmQuery = http_build_query($utmParams);

    // Usa os dados enviados pelo formulário, se disponíveis, senão gera dados falsos como fallback
    // Usar dados reais se disponíveis, senão gerar dados falsos como fallback
    if (!empty($nome_cliente) && !empty($cpf)) {
        $cpf = preg_replace('/[^0-9]/', '', $cpf);
        if (empty($email)) {
            $email = strtolower(str_replace([' ', '+'], ['.', '.'], $nome_cliente)) . '@email.com';
            error_log("[Pagamento] 📧 Email gerado baseado no nome: " . $email);
        }
        if (empty($telefone)) {
            $telefone = '11999999999';
        }
        error_log("[Pagamento] ✅ Usando dados REAIS do cliente: Nome: $nome_cliente, CPF: $cpf, Email: $email, Telefone: $telefone");
    } else {
        // Gerar dados falsos como fallback
        $genero = rand(0, 1);
        $nome = $genero ? $nomes_masculinos[array_rand($nomes_masculinos)] : $nomes_femininos[array_rand($nomes_femininos)];
        $sobrenome1 = $sobrenomes[array_rand($sobrenomes)];
        $sobrenome2 = $sobrenomes[array_rand($sobrenomes)];
        $nome_cliente = "$nome $sobrenome1 $sobrenome2";
        $email = strtolower(str_replace(' ', '.', $nome_cliente)) . '@email.com';
        $cpf = gerarCPF();
        $telefone = '11999999999';
        error_log("[Pagamento] ⚠️ Usando dados FALSOS como fallback: Nome: $nome_cliente, CPF: $cpf, Telefone: $telefone");
    }

    // Configurações da API AllowPay v2
    $apiUrl = "https://api.allowpay.online/functions/v1/transactions";
    $secretKey = 'sk_live_dGsL1uaHQIG3MOYugx2fb3mFfa0wQcHxzh0rsGnQNzzb1l2r'; // Secret Key
    $companyId = '2e03c27f-550d-4f2e-a21f-24a7d7fd142c'; // Company ID

    error_log("[AllowPay] 📝 Preparando dados para envio: " . json_encode([
        'valor' => $valor,
        'valor_centavos' => $valor_centavos,
        'nome' => $nome_cliente,
        'email' => $email,
        'cpf' => $cpf
    ]));

    // Cria o payload para a API AllowPay v2
    $externalRef = uniqid('Deposito');
    $serverUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]";
    $postbackUrl = $serverUrl . "/api/webhook.php";

    $data = [
        "paymentMethod" => "PIX",
        "ip" => $_SERVER['REMOTE_ADDR'] ?? "127.0.0.1",
        "pix" => [
            "expiresInDays" => 1
        ],
        "customer" => [
            "name" => $nome_cliente,
            "email" => $email,
            "phone" => $telefone,
            "document" => [
                "type" => "CPF",
                "number" => $cpf
            ]
        ],
        "items" => [
            [
                "title" => "Deposito",
                "quantity" => 1,
                "unitPrice" => $valor_centavos
            ]
        ],
        "amount" => $valor_centavos,
        "postbackUrl" => $postbackUrl,
        "metadata" => json_encode([
            "utm_params" => $utmParams,
        
        ]),
        "description" => "taxa"
    ];

    error_log("[AllowPay] 🌐 URL da requisição: " . $apiUrl);
    error_log("[AllowPay] 📦 Dados enviados: " . json_encode($data));

    // Prepara a autenticação Basic Auth
    $auth = base64_encode($secretKey . ':' . $companyId);

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Basic ' . $auth,
        'Content-Type: application/json'
    ]);

    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);

    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    error_log("[AllowPay] 🔍 Detalhes da requisição cURL:\n" . $verboseLog);

    if ($curlError) {
        error_log("[AllowPay] ❌ Erro cURL: " . $curlError . " (errno: " . $curlErrno . ")");
        throw new Exception("Erro na requisição: " . $curlError);
    }

    curl_close($ch);

    error_log("[AllowPay] 📊 HTTP Status Code: " . $httpCode);
    error_log("[AllowPay] 📄 Resposta bruta: " . $response);
    error_log("[AllowPay] 🔍 ===== RESPOSTA BRUTA DA API ALLOWPAY =====");
    error_log("[AllowPay] 🔍 " . str_repeat("=", 80));
    error_log($response);
    error_log("[AllowPay] 🔍 " . str_repeat("=", 80));

    if ($httpCode < 200 || $httpCode >= 300) {
        throw new Exception("Erro na API: HTTP " . $httpCode . " - " . $response);
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar resposta: " . json_last_error_msg() . " - Resposta: " . $response);
    }

    error_log("[AllowPay] 🔍 ===== ESTRUTURA JSON DECODIFICADA =====");
    error_log("[AllowPay] 🔍 JSON completo: " . json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    error_log("[AllowPay] 🔍 Chaves no nível raiz: " . json_encode(array_keys($result)));
    
    // Adapta a resposta da AllowPay para o formato esperado pelo frontend
    $transactionId = $result['id'] ?? $result['data']['id'] ?? null;
    error_log("[AllowPay] 🔍 Transaction ID encontrado: " . ($transactionId ?? 'NULL'));
    
    $pixCode = $result['pix']['qrCode'] ?? $result['data']['pix']['qrCode'] ?? null;
    error_log("[AllowPay] 🔍 PixCode (primeira tentativa): " . ($pixCode ?? 'NULL'));

    // Depuração detalhada da resposta para identificar onde está o código PIX
    error_log("[AllowPay] 🔍 Estrutura da resposta: " . print_r($result, true));
    
    // Tenta encontrar o código PIX em diferentes locais possíveis na resposta
    if (!$pixCode) {
        if (isset($result['data']) && isset($result['data']['pix'])) {
            $pixCode = $result['data']['pix']['qrCode'] ?? $result['data']['pix']['qrcode'] ?? $result['data']['pix']['code'] ?? null;
            error_log("[AllowPay] 🔍 Tentando extrair pixCode de data.pix: " . $pixCode);
        } elseif (isset($result['pix'])) {
            $pixCode = $result['pix']['qrCode'] ?? $result['pix']['qrcode'] ?? $result['pix']['code'] ?? null;
            error_log("[AllowPay] 🔍 Tentando extrair pixCode de pix: " . $pixCode);
        } elseif (isset($result['qrCode'])) {
            $pixCode = $result['qrCode'];
            error_log("[AllowPay] 🔍 Tentando extrair pixCode de qrCode: " . $pixCode);
        } elseif (isset($result['pixCode'])) {
            $pixCode = $result['pixCode'];
            error_log("[AllowPay] 🔍 Tentando extrair pixCode de pixCode: " . $pixCode);
        }
    }

    // Se ainda não encontrou o código PIX, cria um código de exemplo para teste


    if (!$transactionId) {
        throw new Exception("ID não encontrado na resposta da API");
    }

    // Salva os dados no SQLite
    $stmt = $db->prepare("INSERT INTO pedidos (transaction_id, status, valor, nome, email, cpf, utm_params, created_at) 
        VALUES (:transaction_id, 'pending', :valor, :nome, :email, :cpf, :utm_params, :created_at)");
    $stmt->execute([
        'transaction_id' => $transactionId,
        'valor' => $valor_centavos,
        'nome' => $nome_cliente,
        'email' => $email,
        'cpf' => $cpf,
        'utm_params' => json_encode($utmParams),
        'created_at' => date('c')
    ]);

    session_start();
    $_SESSION['payment_id'] = $transactionId;

    error_log("[AllowPay] 💳 Transação criada com sucesso: " . $transactionId);
    error_log("[AllowPay] 📄 Resposta completa da API: " . $response);
    error_log("[AllowPay] 🔑 Token gerado: " . $transactionId);

    error_log("[Sistema] 📡 Iniciando comunicação com utmify-pendente.php");

    function getUpsellTitle($valor) {
        // Mapeamento de valores para nomes de upsell
        switch($valor) {
            case 4790:
                return 'Curso helton vieira';
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
            case 6190:
                return 'taxa'; // Valor atual
            default:
                return 'Produto ' . ($valor/100); // Para outros valores não mapeados
        }
    }

    $utmifyData = [
        'orderId' => $transactionId,
        'platform' => 'MinhaPlataforma',
        'paymentMethod' => 'pix',
        'status' => 'waiting_payment',
        'createdAt' => date('Y-m-d H:i:s'),
        'approvedDate' => null,
        'refundedAt' => null,
        'customer' => [
            'name' => $nome_cliente,
            'email' => $email,
            'phone' => $telefone,
            'document' => $cpf,
            'country' => 'BR',
            'ip' => $_SERVER['REMOTE_ADDR'] ?? null
        ],
        'products' => [
            [
                'id' => uniqid('PROD_'),
                'name' => getUpsellTitle($valor_centavos),
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => $valor_centavos
            ]
        ],
        'trackingParameters' => $utmParams,
        'commission' => [
            'totalPriceInCents' => $valor_centavos,
            'gatewayFeeInCents' => 0, // A API HYDRA HUB pode não fornecer esta informação
            'userCommissionInCents' => $valor_centavos
        ],
        'isTest' => false
    ];

    error_log("[Utmify] 📦 Preparando dados para envio ao utmify-pendente.php: " . json_encode($utmifyData));

     // Constrói URL do utmify-pendente.php de forma mais robusta
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443 ? "https" : "http";
    $host = $_SERVER['HTTP_HOST'];
    
    // Pega o diretório do script atual (sem o nome do arquivo)
    $scriptPath = dirname($_SERVER['SCRIPT_NAME']);
    
    // Remove barras duplicadas e garante que termina sem barra
    $scriptPath = rtrim($scriptPath, '/');
    
    // Monta a URL completa
    $utmifyUrl = $protocol . "://" . $host . $scriptPath . "/utmify-pendente.php";
    
    error_log("[Utmify] 🌐 URL construída: " . $utmifyUrl);
    
    $ch = curl_init($utmifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($utmifyData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false
    ]);

    $utmifyResponse = curl_exec($ch);
    $utmifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $utmifyError = curl_error($ch);
    $utmifyErrno = curl_errno($ch);
    
    error_log("[Sistema] 🔍 Detalhes da requisição Utmify: " . print_r([
        'url' => $utmifyUrl,
        'status' => $utmifyHttpCode,
        'resposta' => $utmifyResponse,
        'erro' => $utmifyError,
        'errno' => $utmifyErrno
    ], true));
    
    curl_close($ch);

    error_log("[Sistema] ✉️ Resposta do utmify-pendente.php: " . $utmifyResponse);
    error_log("[Sistema] 📊 Status code do utmify-pendente.php: " . $utmifyHttpCode);

    if ($utmifyHttpCode !== 200) {
        error_log("[Sistema] ❌ Erro ao enviar dados para utmify-pendente.php: " . $utmifyResponse);
    } else {
        error_log("[Sistema] ✅ Dados enviados com sucesso para utmify-pendente.php");
    }

    // Preparar resposta para o frontend (mantendo a mesma estrutura)
    $responseData = [
        'success' => true,
        'token' => $transactionId,
        'pixCode' => $pixCode,
        'qrCodeUrl' => $pixCode ? 
            'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($pixCode) . '&size=300x300&charset-source=UTF-8&charset-target=UTF-8&qzone=1&format=png&ecc=L' : 
            null,
        'valor' => $valor,
        'logs' => [
            'utmParams' => $utmParams,
            'transacao' => [
                'valor' => $valor,
                'cliente' => $nome_cliente,
                'email' => $email,
                'cpf' => $cpf
            ],
            'ghostspaysResponse' => [
                'httpCode' => $httpCode,
                'respostaBruta' => $response,
                'jsonDecodificado' => $result,
                'chavesRaiz' => array_keys($result)
            ],
            'utmifyResponse' => [
                'status' => $utmifyHttpCode,
                'resposta' => $utmifyResponse
            ]
        ]
    ];

    // Verificação adicional para garantir que o QR Code está sendo gerado
    if ($pixCode && !$responseData['qrCodeUrl']) {
        error_log("[AllowPay] ⚠️ QR Code URL não foi gerado mesmo com pixCode disponível");
        $responseData['qrCodeUrl'] = 'https://api.qrserver.com/v1/create-qr-code/?data=' . urlencode($pixCode) . '&size=300x300&charset-source=UTF-8&charset-target=UTF-8&qzone=1&format=png&ecc=L';
    }

    error_log("[AllowPay] 📤 Enviando resposta ao frontend: " . json_encode($responseData));
    error_log("[AllowPay] 🧾 Detalhes da resposta - Token: " . $responseData['token'] . ", PixCode: " . ($responseData['pixCode'] ? 'Disponível' : 'Não disponível') . ", QR Code URL: " . ($responseData['qrCodeUrl'] ? 'Gerado' : 'Não gerado'));
    
    echo json_encode($responseData);

} catch (Exception $e) {
    error_log("[AllowPay] ❌ Erro: " . $e->getMessage());
    error_log("[AllowPay] 🔍 Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao gerar o PIX: ' . $e->getMessage()
    ]);
}
?>