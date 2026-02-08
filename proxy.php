<?php
// ==========================================================
// CONFIGURAÇÕES DA PARADISE
// ==========================================================

// COLOQUE SUA SECRET KEY AQUI
$apiKey = 'sk_c151872b71cd2c6976c6e7524450cbc8ad8305a64dc34d55b079b5d6a5e42d08'; 

// URL Base da API Paradise
$apiBaseUrl = 'https://multi.paradisepags.com/api/v1';

// ==========================================================
// CONFIGURAÇÕES DO SERVIDOR (CORS E JSON)
// ==========================================================
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-API-Key');
header('Content-Type: application/json');

// Se for requisição OPTIONS (Pre-flight do navegador), encerra aqui
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ==========================================================
// LÓGICA DO PROXY
// ==========================================================

// Identifica a ação (GET para consultar status, POST para criar transação)
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? null;

// ----------------------------------------------------------
// 1. VERIFICAR STATUS DO PAGAMENTO (Polling)
// ----------------------------------------------------------
if ($method === 'GET' && $action === 'check_status') {
    $transactionId = $_GET['hash'] ?? null;

    if (!$transactionId) {
        echo json_encode(['error' => 'ID da transação não fornecido.']);
        exit;
    }

    // Chama a API da Paradise para consultar
    // Endpoint: /api/v1/query.php?action=get_transaction&id={id}
    $response = curlRequest(
        "$apiBaseUrl/query.php?action=get_transaction&id=$transactionId",
        'GET',
        null,
        $apiKey
    );

    $data = json_decode($response, true);

    // Mapeia a resposta para o formato que o seu JS espera
    // O JS espera: { payment_status: 'paid' } se aprovado
    $status = $data['status'] ?? 'pending';
    $outputStatus = ($status === 'approved' || $status === 'paid') ? 'paid' : 'pending';

    echo json_encode(['payment_status' => $outputStatus]);
    exit;
}

// ----------------------------------------------------------
// 2. CRIAR NOVA TRANSAÇÃO (Gerar PIX)
// ----------------------------------------------------------
if ($method === 'POST') {
    // Lê o JSON enviado pelo JavaScript do site
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode(['error' => 'Dados inválidos.']);
        exit;
    }

    // Extrai dados do payload do seu site
    $amount = $input['amount'] ?? 0; // Já vem em centavos do JS
    $productHash = $input['product_hash'] ?? '';
    $customer = $input['customer'] ?? [];
    $utms = $input['utms'] ?? [];

    // Validação básica
    if ($amount <= 0 || empty($productHash)) {
        echo json_encode(['error' => 'Dados obrigatórios faltando (valor ou produto).']);
        exit;
    }

    // Monta o payload conforme a documentação da Paradise
    $payload = [
        "amount" => $amount,
        "description" => "Assinatura VIP", // Nome genérico ou dinâmico
        "reference" => "PED-" . time() . "-" . rand(100, 999), // Gera ID único
        "productHash" => $productHash,
        "source" => "api_externa", // Importante para não validar hash estrito se não cadastrado
        "customer" => [
            "name" => $customer['name'] ?? 'Cliente',
            "email" => $customer['email'] ?? 'email@teste.com',
            "document" => preg_replace('/\D/', '', $customer['document'] ?? '00000000000'),
            "phone" => preg_replace('/\D/', '', $customer['phone_number'] ?? '00000000000')
        ],
        "tracking" => [
            "utm_source" => $utms['utm_source'] ?? '',
            "utm_medium" => $utms['utm_medium'] ?? '',
            "utm_campaign" => $utms['utm_campaign'] ?? '',
            "utm_content" => $utms['utm_content'] ?? '',
            "utm_term" => $utms['utm_term'] ?? '',
            "src" => $utms['src'] ?? ''
        ]
    ];

    // Envia para a Paradise
    // Endpoint: POST /api/v1/transaction.php
    $response = curlRequest(
        "$apiBaseUrl/transaction.php",
        'POST',
        $payload,
        $apiKey
    );

    $data = json_decode($response, true);

    // Verifica se houve erro na API
    if (isset($data['status']) && $data['status'] === 'success') {
        // Mapeia a resposta para o formato que o seu JS espera
        // O JS espera: { hash: transaction_id, pix: { pix_qr_code: '...' } }
        $output = [
            'hash' => $data['transaction_id'],
            'pix' => [
                'pix_qr_code' => $data['qr_code'],
                'expiration_date' => $data['expires_at'] ?? null
            ]
        ];
        echo json_encode($output);
    } else {
        // Retorna erro se a Paradise recusar
        http_response_code(400);
        echo json_encode(['error' => 'Erro na API Paradise', 'details' => $data]);
    }
    exit;
}

// ==========================================================
// FUNÇÃO AUXILIAR CURL
// ==========================================================
function curlRequest($url, $method, $data, $key) {
    $curl = curl_init();

    $headers = [
        'X-API-Key: ' . $key,
        'Content-Type: application/json'
    ];

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => '',
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
    ];

    if ($method === 'POST' && !empty($data)) {
        $options[CURLOPT_POSTFIELDS] = json_encode($data);
    }

    curl_setopt_array($curl, $options);

    $response = curl_exec($curl);
    $err = curl_error($curl);

    curl_close($curl);

    if ($err) {
        return json_encode(['error' => "cURL Error #: " . $err]);
    } else {
        return $response;
    }
}
?>
