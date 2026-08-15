<?php
// Headers já definidos pelo roteador pagamento.php
// NÃO remova este comentário - evita redefinição de headers

// Configurações de erro (sem headers)
ini_set('display_errors', 0);
ini_set('log_errors', 1);
error_reporting(E_ALL);

// Define timezone para horário de Brasília
date_default_timezone_set('America/Sao_Paulo');

// ===== CAPTURA IP REAL DO CLIENTE (considerando proxy/CDN) =====
function getClientIP() {
    // Lista de possíveis headers que contêm o IP real do cliente
    $headers = [
        'HTTP_CF_CONNECTING_IP',    // Cloudflare
        'HTTP_X_REAL_IP',            // Nginx proxy
        'HTTP_X_FORWARDED_FOR',      // Proxy padrão
        'HTTP_CLIENT_IP',            // Proxy
        'REMOTE_ADDR'                // Fallback (IP direto ou do proxy)
    ];
    
    foreach ($headers as $header) {
        if (!empty($_SERVER[$header])) {
            $ip = $_SERVER[$header];
            
            // Se for X-Forwarded-For, pode ter múltiplos IPs separados por vírgula
            // Pega o PRIMEIRO IP (o cliente original)
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
            
            // Valida se é um IP válido
            if (filter_var($ip, FILTER_VALIDATE_IP)) { // ✅ Aceita IPv4 e IPv6
                error_log("[IP] ✅ IP real capturado via $header: $ip");
                return $ip;
            }
            
            // Se não passou na validação acima, ainda aceita IPs privados (para testes locais)
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                error_log("[IP] ⚠️ IP capturado via $header (pode ser privado): $ip");
                return $ip;
            }
        }
    }
    
    error_log("[IP] ❌ Não foi possível capturar IP do cliente");
    return 'IP_DESCONHECIDO';
}

$client_ip = getClientIP();
error_log("[IP] 🌐 IP detectado do cliente: $client_ip");

// ===== CAPTURA IP E SALVA LOG IMEDIATAMENTE =====

// Define caminho do log
$logDir = __DIR__ . '/logs';
$logFilePath = $logDir . '/pix-requests.log';

// Cria diretório se não existir
if (!file_exists($logDir)) {
    $created = @mkdir($logDir, 0777, true);
    if (!$created) {
        error_log("[ERRO CRÍTICO] ❌ Não foi possível criar diretório: $logDir");
    }
}

// Testa se pode escrever no diretório
if (!is_writable($logDir)) {
    error_log("[ERRO CRÍTICO] ❌ Diretório não tem permissão de escrita: $logDir");
    @chmod($logDir, 0777);
}

// Salva log INICIAL com IP (nome será adicionado depois)
$logLineInicial = date('Y-m-d H:i:s') . ' | IP: ' . $client_ip . ' | Status: REQUISIÇÃO INICIADA' . PHP_EOL;
$bytes = @file_put_contents($logFilePath, $logLineInicial, FILE_APPEND | LOCK_EX);

if ($bytes === false) {
    error_log("[ERRO CRÍTICO] ❌ Falha ao escrever no arquivo de log: $logFilePath");
    error_log("[ERRO CRÍTICO] 📁 Diretório existe? " . (file_exists($logDir) ? 'SIM' : 'NÃO'));
    error_log("[ERRO CRÍTICO] 🔓 Diretório gravável? " . (is_writable($logDir) ? 'SIM' : 'NÃO'));
} else {
    error_log("[LOG TXT] ✅ Log inicial salvo com sucesso: $bytes bytes em $logFilePath");
}
// ===== FIM DO LOG INICIAL =====

// Bloqueio de IPs específicos
$blocked_ips = [
    '2804:14d:8e85:8025:5184:a4d6:5ad1:4270',
    '149.102.234.142',
    // '192.168.1.100'
];

// Verifica se o IP do cliente está na lista de IPs bloqueados
if (in_array($client_ip, $blocked_ips)) {
    error_log("[BLOQUEIO] Acesso negado para IP bloqueado: " . $client_ip);
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => 'Acesso negado'
    ]);
    exit;
}

// Função para gerar CPF válido
function gerarCPF() {
    // Gera os 9 primeiros dígitos aleatórios
    $cpf = '';
    for($i = 0; $i < 9; $i++) {
        $cpf .= rand(0, 9);
    }

    // Calcula o primeiro dígito verificador
    $soma = 0;
    for($i = 0; $i < 9; $i++) {
        $soma += intval($cpf[$i]) * (10 - $i);
    }
    $resto = $soma % 11;
    $digito1 = ($resto < 2) ? 0 : 11 - $resto;
    $cpf .= $digito1;

    // Calcula o segundo dígito verificador
    $soma = 0;
    for($i = 0; $i < 10; $i++) {
        $soma += intval($cpf[$i]) * (11 - $i);
    }
    $resto = $soma % 11;
    $digito2 = ($resto < 2) ? 0 : 11 - $resto;
    $cpf .= $digito2;

    // Verifica se não é um CPF com dígitos repetidos
    $invalidos = [
        '00000000000',
        '11111111111',
        '22222222222',
        '33333333333',
        '44444444444',
        '55555555555',
        '66666666666',
        '77777777777',
        '88888888888',
        '99999999999'
    ];

    if(in_array($cpf, $invalidos)) {
        return gerarCPF(); // Gera outro CPF se for inválido
    }

    return $cpf;
}

// Função para obter o título baseado no valor do produto
function getUpsellTitle($valor) {
    // Mapeamento de valores para nomes de upsell
    switch($valor) {
        // ===== Funil TikTok Recompensas =====
        case 3737: return 'Taxa de saque';
        case 1920: return 'Taxa de IOF';
        case 1721: return 'Certificação Digital';
        case 1699: return 'Emissão de Comprovante';
        case 1499: return 'Upgrade Premium Vitalício';
        case 998:  return 'Conversão de Saldo USD';
        // ===== Demais produtos =====
        case 13789:
            return 'Liberação de Benefício';
        case 3995:
            return 'SMS';
        case 1970:
            return 'Upsell 2';
        case 3980:
            return 'Upsell 5';
        case 1790:
            return 'Upsell 3';
        case 2490:
            return 'Upsell 4';
        case 1890:
            return 'Upsell 6';
        case 6190:
            return 'Produto 1'; // Valor original do checkout
        case 2790:
            return 'Produto 2'; // Valor padrão do checkoutup
        default:
            return 'Produto ' . ($valor/100); // Para outros valores não mapeados
    }
}

try {
    // Configurações da API Paradise Pags
    $apiUrl = 'https://multi.paradisepags.com/api/v1/transaction.php';
    $apiKey = 'sk_7972ef18f47be8fb7e505d8178ca8792747d6d14a29a5cd72158538ae202e465';
    $storeId = '1216';

    // Conecta ao SQLite (arquivo de banco de dados na pasta checkout)
    $dbPath = __DIR__ . '/database.sqlite'; // Caminho para o arquivo SQLite na pasta checkout
    $db = new PDO("sqlite:$dbPath");
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Cria a tabela pedidos se ela não existir
    $createTableSQL = "
        CREATE TABLE IF NOT EXISTS pedidos (
            transaction_id TEXT PRIMARY KEY,
            status TEXT NOT NULL,
            valor INTEGER NOT NULL,
            nome TEXT,
            email TEXT,
            cpf TEXT,
            telefone TEXT,
            utm_params TEXT,
            client_ip TEXT,
            client_user_agent TEXT,
            fbp TEXT,
            fbc TEXT,
            created_at TEXT,
            updated_at TEXT
        )
    ";

    // ✅ Adiciona colunas novas caso a tabela já exista (migration segura)
    $colunasNovas = ['telefone', 'client_ip', 'client_user_agent', 'fbp', 'fbc'];
    foreach ($colunasNovas as $coluna) {
        try { $db->exec("ALTER TABLE pedidos ADD COLUMN $coluna TEXT"); } catch (Exception $e) { /* coluna já existe */ }
    }
    
    $db->exec($createTableSQL);
    
    // Criar índices para melhor performance
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pedidos_status ON pedidos(status)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pedidos_created_at ON pedidos(created_at)");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_pedidos_valor ON pedidos(valor)");
    
    error_log("[Pagamento] 🔌 Conectado ao banco de dados SQLite em: " . $dbPath);
    error_log("[Pagamento] 📋 Tabela 'pedidos' verificada/criada com sucesso");

    // Recebe dados JSON do body da requisição
    $input = json_decode(file_get_contents('php://input'), true);
    
    // Recebe o valor da requisição (index.html envia JÁ EM CENTAVOS)
    $valor_centavos = $input['valor'] ?? $_POST['valor'] ?? $_GET['valor'] ?? null;
    
    // Se não receber valor, usa o padrão
    if (!$valor_centavos || $valor_centavos <= 0) {
        $valor_centavos = 13789; // Valor padrão em centavos
        error_log("[Pagamento] ⚠️ Valor não recebido, usando padrão: " . $valor_centavos . " centavos");
    }
    
    $valor = $valor_centavos; // Mantém compatibilidade com código existente
    error_log("[Pagamento] 💰 Valor recebido: " . $valor_centavos . " centavos (R$ " . number_format($valor_centavos/100, 2, ',', '.') . ")");

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

    // Capturar dados reais do cliente - primeiro tenta do JSON, depois do POST/GET
    $dadosReais = [
        'nome' => $input['nome'] ?? $_POST['nome'] ?? $_GET['nome'] ?? null,
        'email' => $input['email'] ?? $_POST['email'] ?? $_GET['email'] ?? null,
        'cpf' => $input['cpf'] ?? $_POST['cpf'] ?? $_GET['cpf'] ?? null,
        'telefone' => $input['telefone'] ?? $_POST['telefone'] ?? $_GET['telefone'] ?? null
    ];
    
    // O funil (app React) manda os parâmetros dentro de "tracking"; considera essa fonte também
    $tracking = (isset($input['tracking']) && is_array($input['tracking'])) ? $input['tracking'] : [];

    // Parâmetros UTM/rastreamento - JSON top-level -> tracking -> POST -> GET
    $utmParams = [
        'utm_source' => $input['utm_source'] ?? $tracking['utm_source'] ?? $_POST['utm_source'] ?? $_GET['utm_source'] ?? null,
        'utm_medium' => $input['utm_medium'] ?? $tracking['utm_medium'] ?? $_POST['utm_medium'] ?? $_GET['utm_medium'] ?? null,
        'utm_campaign' => $input['utm_campaign'] ?? $tracking['utm_campaign'] ?? $_POST['utm_campaign'] ?? $_GET['utm_campaign'] ?? null,
        'utm_content' => $input['utm_content'] ?? $tracking['utm_content'] ?? $_POST['utm_content'] ?? $_GET['utm_content'] ?? null,
        'utm_term' => $input['utm_term'] ?? $tracking['utm_term'] ?? $_POST['utm_term'] ?? $_GET['utm_term'] ?? null,
        'xcod' => $input['xcod'] ?? $tracking['xcod'] ?? $_POST['xcod'] ?? $_GET['xcod'] ?? null,
        'sck' => $input['sck'] ?? $tracking['sck'] ?? $_POST['sck'] ?? $_GET['sck'] ?? null,
        'src' => $input['src'] ?? $tracking['src'] ?? $_POST['src'] ?? $_GET['src'] ?? null,
        'utm_id' => $input['utm_id'] ?? $tracking['utm_id'] ?? $_POST['utm_id'] ?? $_GET['utm_id'] ?? null,
        // ✅ Identificadores TikTok para atribuição server-side (Events API)
        'ttclid' => $input['ttclid'] ?? $tracking['ttclid'] ?? $_POST['ttclid'] ?? $_GET['ttclid'] ?? null,
        'ttp' => $input['ttp'] ?? $tracking['ttp'] ?? $_POST['ttp'] ?? $_GET['ttp'] ?? null
    ];

    // ✅ Captura fbp, fbc e user_agent enviados pelo frontend
    $fbp        = $input['fbp']        ?? $_POST['fbp']        ?? null;
    $fbc        = $input['fbc']        ?? $_POST['fbc']        ?? null;
    $userAgent  = $input['userAgent']  ?? $_POST['userAgent']  ?? $_SERVER['HTTP_USER_AGENT'] ?? null;

    error_log("[Pagamento] 🍪 fbp: " . $fbp);
    error_log("[Pagamento] 🍪 fbc: " . $fbc);
    error_log("[Pagamento] 🌐 userAgent: " . $userAgent);

    // Remove parâmetros vazios
    $utmParams = array_filter($utmParams, function($value) {
        return $value !== null && $value !== '';
    });

    error_log("[Pagamento] 👤 Dados reais recebidos: " . json_encode($dadosReais));
    error_log("[Pagamento] 📊 Parâmetros UTM recebidos: " . json_encode($utmParams));

    $utmQuery = http_build_query($utmParams);

    // ===== VALIDAÇÃO E SANITIZAÇÃO DE SEGURANÇA =====
    function sanitizeInput($input, $maxLength = 255) {
        if (empty($input)) return null;
        
        // Remove espaços em branco extras
        $input = trim($input);
        
        // Remove caracteres de controle e null bytes
        $input = preg_replace('/[\x00-\x1F\x7F]/u', '', $input);
        
        // Limita o tamanho
        $input = mb_substr($input, 0, $maxLength, 'UTF-8');
        
        return $input;
    }

    function validateNome($nome) {
        // Sanitiza primeiro
        $nome = sanitizeInput($nome, 100);
        if (empty($nome)) return null;
        
        // Rejeita SQL injection patterns
        $sqlPatterns = [
            '/(\bUNION\b|\bSELECT\b|\bINSERT\b|\bUPDATE\b|\bDELETE\b|\bDROP\b|\bEXEC\b|\bEXECUTE\b)/i',
            '/(\-\-|\/\*|\*\/|;)/i',  // Comentários SQL
            '/(\bOR\b|\bAND\b)\s*[\'\"]?\d+[\'\"]?\s*=\s*[\'\"]?\d+/i', // OR 1=1, AND 1=1
        ];
        
        foreach ($sqlPatterns as $pattern) {
            if (preg_match($pattern, $nome)) {
                error_log("[SECURITY ALERT] 🚨 SQL Injection attempt detected in nome: " . $nome);
                return null; // Rejeita entrada maliciosa
            }
        }
        
        // Permite apenas letras, espaços, apóstrofos e hífens (nomes válidos)
        if (!preg_match('/^[\p{L}\s\'\-]+$/u', $nome)) {
            error_log("[SECURITY ALERT] ⚠️ Invalid character in nome: " . $nome);
            return null;
        }
        
        return $nome;
    }

    function validateEmail($email) {
        $email = sanitizeInput($email, 255);
        if (empty($email)) return null;
        
        // Validação básica de email
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            error_log("[SECURITY ALERT] ⚠️ Invalid email format: " . $email);
            return null;
        }
        
        return $email;
    }

    function validateCPF($cpf) {
        $cpf = sanitizeInput($cpf, 14);
        if (empty($cpf)) return null;
        
        // Remove tudo exceto números
        $cpf = preg_replace('/\D/', '', $cpf);
        
        // Valida se tem 11 dígitos
        if (strlen($cpf) !== 11) {
            return null;
        }
        
        return $cpf;
    }
    // ===== FIM DA VALIDAÇÃO DE SEGURANÇA =====

    // Validar dados reais ANTES de usar
    $dadosValidados = [
        'nome' => validateNome($dadosReais['nome']),
        'email' => validateEmail($dadosReais['email']),
        'cpf' => validateCPF($dadosReais['cpf']),
        'telefone' => !empty($dadosReais['telefone']) ? preg_replace('/\D/', '', $dadosReais['telefone']) : null
    ];

    // Log de segurança
    if ($dadosReais['nome'] && !$dadosValidados['nome']) {
        error_log("[SECURITY ALERT] 🚨 Nome rejeitado por validação: " . $dadosReais['nome']);
    }
    if ($dadosReais['email'] && !$dadosValidados['email']) {
        error_log("[SECURITY ALERT] 🚨 Email rejeitado por validação: " . $dadosReais['email']);
    }
    if ($dadosReais['cpf'] && !$dadosValidados['cpf']) {
        error_log("[SECURITY ALERT] 🚨 CPF rejeitado por validação: " . $dadosReais['cpf']);
    }

    // Usar dados reais se disponíveis E VÁLIDOS, senão gerar dados falsos como fallback
    if (!empty($dadosValidados['nome']) && !empty($dadosValidados['cpf'])) {
        // Usar dados reais VALIDADOS
        $nome_cliente = $dadosValidados['nome'];
        $cpf = $dadosValidados['cpf'];
        $telefone = $dadosValidados['telefone'] ?: '11999999999';
        
        // Usa email validado ou gera baseado no nome
        if (!empty($dadosValidados['email'])) {
            $email = $dadosValidados['email'];
            error_log("[Pagamento] 📧 Usando email REAL validado: " . $email);
        } else {
            $email = strtolower(str_replace([' ', '+'], ['.', '.'], $nome_cliente)) . '@email.com';
            error_log("[Pagamento] 📧 Email gerado baseado no nome: " . $email);
        }
        
        error_log("[Pagamento] ✅ Usando dados REAIS VALIDADOS do cliente: Nome: $nome_cliente, CPF: " . substr($cpf, 0, 3) . ".***.***-" . substr($cpf, -2));
    } else {
        // Gerar dados falsos como fallback
        $genero = rand(0, 1);
        $nome = $genero ? 
            $nomes_masculinos[array_rand($nomes_masculinos)] : 
            $nomes_femininos[array_rand($nomes_femininos)];
        
        $sobrenome1 = $sobrenomes[array_rand($sobrenomes)];
        $sobrenome2 = $sobrenomes[array_rand($sobrenomes)];
        
        $nome_cliente = "$nome $sobrenome1 $sobrenome2";
        $email = strtolower(str_replace(' ', '.', $nome_cliente)) . '@email.com';
        $cpf = gerarCPF();
        $telefone = '11999999999';
        
        error_log("[Pagamento] ⚠️ Usando dados FALSOS como fallback: Nome: $nome_cliente, CPF: $cpf, Telefone: $telefone");
    }

    // Obtém o título do produto baseado no valor
    $produtoTitulo = getUpsellTitle($valor_centavos);
    error_log("[Pagamento] 🏷️ Título do produto: " . $produtoTitulo);

    // ===== ATUALIZA LOG TXT COM NOME DO CLIENTE =====
    $logLineCompleto = date('Y-m-d H:i:s') . ' | IP: ' . $client_ip . ' | Nome: ' . $nome_cliente . ' | Valor: R$ ' . number_format($valor_centavos/100, 2, ',', '.') . PHP_EOL;
    $bytes = @file_put_contents($logFilePath, $logLineCompleto, FILE_APPEND | LOCK_EX);
    
    if ($bytes === false) {
        error_log("[LOG TXT] ❌ ERRO ao salvar log completo");
    } else {
        error_log("[LOG TXT] ✅ Log completo salvo: IP=$client_ip | Nome=$nome_cliente | Bytes: $bytes");
        error_log("[LOG TXT] 📂 Arquivo: $logFilePath");
    }
    // ===== FIM DO LOG COMPLETO =====

    // Gera uma referência única para a transação
    $reference = 'REF-' . time() . '-' . rand(1000, 9999);

    error_log("[ParadisePags] 📝 Preparando dados para envio: " . json_encode([
        'valor' => $valor,
        'valor_centavos' => $valor_centavos,
        'nome' => $nome_cliente,
        'email' => $email,
        'cpf' => $cpf,
        'telefone' => $telefone,
        'reference' => $reference
    ]));

    // Estrutura do payload conforme documentação Paradise Pags
    $data = [
        "amount" => $valor_centavos,
        "description" => $produtoTitulo,
        "reference" => $reference,
        "productHash" => 'prod_8fd878c115149f28',
        "customer" => [
            "name" => $nome_cliente,
            "document" => $cpf,
            "email" => $email,
            "phone" => $telefone
        ]
    ];

    // Adiciona tracking se houver parâmetros UTM
    if (!empty($utmParams)) {
        $data['tracking'] = $utmParams;
    }

    error_log("[ParadisePags] 🌐 URL da requisição: " . $apiUrl);
    error_log("[ParadisePags] 📦 Dados enviados: " . json_encode($data));

    $ch = curl_init($apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'X-API-Key: ' . $apiKey
    ]);
    
    // Configurações para seguir redirecionamentos
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    
    // Configurações SSL
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    
    // User Agent para evitar bloqueios
    curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');

    // Adiciona opções para debug
    curl_setopt($ch, CURLOPT_VERBOSE, true);
    $verbose = fopen('php://temp', 'w+');
    curl_setopt($ch, CURLOPT_STDERR, $verbose);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    $curlErrno = curl_errno($ch);
    
    // Informações adicionais sobre redirecionamentos
    $redirectCount = curl_getinfo($ch, CURLINFO_REDIRECT_COUNT);
    $effectiveUrl = curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    
    error_log("[ParadisePags] 🔄 Redirecionamentos: " . $redirectCount);
    error_log("[ParadisePags] 🎯 URL final: " . $effectiveUrl);

    // Log detalhado do cURL
    rewind($verbose);
    $verboseLog = stream_get_contents($verbose);
    error_log("[ParadisePags] 🔍 Detalhes da requisição cURL:\n" . $verboseLog);

    if ($curlError) {
        error_log("[ParadisePags] ❌ Erro cURL: " . $curlError . " (errno: " . $curlErrno . ")");
        throw new Exception("Erro na requisição: " . $curlError);
    }

    curl_close($ch);

    error_log("[ParadisePags] 📊 HTTP Status Code: " . $httpCode);
    error_log("[ParadisePags] 📄 Resposta bruta: " . $response);

    // Aceita códigos de sucesso (200-299)
    if ($httpCode < 200 || $httpCode >= 300) {
        $errorMsg = "Erro na API: HTTP " . $httpCode;
        if (!empty($response)) {
            $errorMsg .= " - " . $response;
        }
        throw new Exception($errorMsg);
    }

    $result = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Erro ao decodificar resposta: " . json_last_error_msg() . " - Resposta: " . $response);
    }

    // Verifica se a resposta foi bem-sucedida
    if (!isset($result['status']) || $result['status'] !== 'success') {
        $errorMsg = isset($result['message']) ? $result['message'] : 'Erro desconhecido na API';
        throw new Exception("Erro na API: " . $errorMsg);
    }

    if (!isset($result['transaction_id'])) {
        throw new Exception("Transaction ID não encontrado na resposta da API");
    }

    // Usa transaction_id da Paradise Pags
    $transactionId = $result['transaction_id'];

    // Salva os dados no SQLite usando INSERT OR REPLACE para permitir atualização de IDs duplicados
    $stmt = $db->prepare("INSERT OR REPLACE INTO pedidos (transaction_id, status, valor, nome, email, cpf, telefone, utm_params, client_ip, client_user_agent, fbp, fbc, created_at, updated_at) 
        VALUES (:transaction_id, 'pending', :valor, :nome, :email, :cpf, :telefone, :utm_params, :client_ip, :client_user_agent, :fbp, :fbc, :created_at, :updated_at)");
    $stmt->execute([
        'transaction_id'     => $transactionId,
        'valor'              => $valor_centavos,
        'nome'               => $nome_cliente,
        'email'              => $email,
        'cpf'                => $cpf,
        'telefone'           => $telefone,
        'utm_params'         => json_encode($utmParams),
        'client_ip'          => $client_ip,          // ✅ IP real do cliente
        'client_user_agent'  => $userAgent,           // ✅ User Agent real
        'fbp'                => $fbp,                 // ✅ Cookie _fbp
        'fbc'                => $fbc,                 // ✅ Cookie _fbc
        'created_at'         => date('c'),
        'updated_at'         => date('c')
    ]);
    
    error_log("[Pagamento] 💾 Dados salvos/atualizados no banco SQLite com transaction_id: " . $transactionId);

    session_start();
    $_SESSION['payment_id'] = $transactionId;
    
    error_log("[ParadisePags] 💳 Transação criada com sucesso: " . $transactionId);
    error_log("[ParadisePags] 📄 Resposta completa da API: " . $response);
    error_log("[ParadisePags] 🔑 Token gerado: " . $transactionId);

    error_log("[Sistema] 📡 Iniciando comunicação com utmify-pendente.php");

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
            'phone' => $telefone,             // ✅ Telefone real
            'document' => $cpf,
            'country' => 'BR',
            'ip'         => $client_ip,       // ✅ IP real do cliente
            'userAgent'  => $userAgent,       // ✅ User Agent real
            'externalId' => $transactionId,   // ✅ External ID
            'fbp'        => $fbp,             // ✅ Cookie _fbp
            'fbc'        => $fbc,             // ✅ Cookie _fbc
        ],
        'products' => [
            [
                'id' => uniqid('PROD_'),
                'name' => $produtoTitulo,
                'planId' => null,
                'planName' => null,
                'quantity' => 1,
                'priceInCents' => $valor_centavos
            ]
        ],
        'trackingParameters' => $utmParams,
        'commission' => [
            'totalPriceInCents' => $valor_centavos,
            'gatewayFeeInCents' => 0,
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
    
    // Prepara o payload JSON
    $utmifyPayload = json_encode($utmifyData);
    error_log("[Utmify] 📋 Payload JSON: " . $utmifyPayload);
    
    $ch = curl_init($utmifyUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $utmifyPayload,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    error_log("[Utmify] � Iniciando requisição para: " . $utmifyUrl);
    
    $utmifyResponse = curl_exec($ch);
    $utmifyHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $utmifyError = curl_error($ch);
    $utmifyErrno = curl_errno($ch);
    
    $curlInfo = curl_getinfo($ch);
    
    error_log("[Utmify] 🔍 Detalhes completos da requisição: " . json_encode([
        'url' => $utmifyUrl,
        'http_code' => $utmifyHttpCode,
        'total_time' => $curlInfo['total_time'],
        'connect_time' => $curlInfo['connect_time'],
        'size_download' => $curlInfo['size_download'],
        'resposta' => $utmifyResponse,
        'erro' => $utmifyError,
        'errno' => $utmifyErrno
    ], JSON_PRETTY_PRINT));
    
    curl_close($ch);

    error_log("[Utmify] ✉️ Resposta do utmify-pendente.php: " . $utmifyResponse);
    error_log("[Utmify] 📊 Status HTTP: " . $utmifyHttpCode);

    if ($utmifyErrno !== 0) {
        error_log("[Utmify] ❌ Erro cURL ao enviar para utmify-pendente.php: " . $utmifyError . " (errno: " . $utmifyErrno . ")");
    } elseif ($utmifyHttpCode !== 200) {
        error_log("[Utmify] ⚠️ Status HTTP não-200 do utmify-pendente.php: " . $utmifyHttpCode . " - Resposta: " . $utmifyResponse);
    } else {
        error_log("[Utmify] ✅ Dados enviados com sucesso para utmify-pendente.php");
        
        // Tenta decodificar a resposta
        $utmifyResponseData = json_decode($utmifyResponse, true);
        if ($utmifyResponseData) {
            error_log("[Utmify] 📦 Resposta decodificada: " . json_encode($utmifyResponseData, JSON_PRETTY_PRINT));
        }
    }

    // Gera URL do QR Code se não vier da API
    $qrCodeUrl = $result['qr_code_base64'] ?? null;
    
    // Se não tiver QR Code e tiver o código PIX, gera via API pública
    if (empty($qrCodeUrl) && !empty($result['qr_code'])) {
        $qrCodeUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=' . urlencode($result['qr_code']);
        error_log("[ParadisePags] 🔄 QR Code gerado via API externa: " . $qrCodeUrl);
    }

    // Preparar resposta
    $responseData = [
        'success' => true,
        'token' => $transactionId,
        'pixCode' => $result['qr_code'] ?? null,
        'pixCopiaECola' => $result['qr_code'] ?? null, // Adiciona o campo que o frontend espera
        'qrCodeUrl' => $qrCodeUrl, // QR Code gerado ou da API
        'valor' => $valor,
        'expires_at' => $result['expires_at'] ?? null,
        'logs' => [
            'utmParams' => $utmParams,
            'transacao' => [
                'valor' => $valor,
                'cliente' => $nome_cliente,
                'email' => $email,
                'cpf' => $cpf,
                'reference' => $reference
            ],
            'utmifyResponse' => [
                'status' => $utmifyHttpCode,
                'resposta' => $utmifyResponse
            ]
        ]
    ];

    error_log("[ParadisePags] 📤 Enviando resposta ao frontend: " . json_encode($responseData));
    echo json_encode($responseData);

} catch (Exception $e) {
    error_log("[ParadisePags] ❌ Erro: " . $e->getMessage());
    error_log("[ParadisePags] 🔍 Stack trace: " . $e->getTraceAsString());
    
    echo json_encode([
        'success' => false,
        'message' => 'Erro ao gerar o PIX: ' . $e->getMessage()
    ]);
}