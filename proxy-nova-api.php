<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: GET, OPTIONS");
header("Access-Control-Allow-Headers: *");
header("Content-Type: application/json; charset=utf-8");

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$cpf = isset($_GET['cpf']) ? $_GET['cpf'] : null;

if (!$cpf || $cpf === '') {
    http_response_code(400);
    echo json_encode(['error' => 'CPF nao fornecido']);
    exit;
}

$cpf = preg_replace('/[^0-9]/', '', $cpf);

if (strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['error' => 'CPF invalido, deve conter 11 digitos']);
    exit;
}

$token = "6873";
$url = "https://searchapi.it.com/consulta?token_api={$token}&cpf={$cpf}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'Accept: application/json',
    'Content-Type: application/json'
]);

$response = curl_exec($ch);

if (curl_errno($ch)) {
    http_response_code(500);
    echo json_encode(['error' => 'Erro ao consultar a API: ' . curl_error($ch)]);
    curl_close($ch);
    exit;
}

$statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($statusCode !== 200) {
    http_response_code($statusCode);
    echo json_encode(['error' => 'Erro na API externa', 'status' => $statusCode]);
    exit;
}

$data = json_encode(json_decode($response), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
echo $data;
?>