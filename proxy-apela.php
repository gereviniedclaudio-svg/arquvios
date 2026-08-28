<?php
// Simple server-side proxy to call Apela CPF API and avoid browser CORS
// Usage: GET /proxy-apela.php?cpf=00000000000

header('Content-Type: application/json; charset=utf-8');

// Optional CORS headers (same-origin fetch won't need, but safe to include)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
	http_response_code(204);
	exit;
}

// Validate input
$cpf = isset($_GET['cpf']) ? preg_replace('/\D/', '', $_GET['cpf']) : '';
if (empty($cpf) || strlen($cpf) < 11) {
	http_response_code(400);
	echo json_encode(['status' => 400, 'erro' => 'CPF inválido ou ausente']);
	exit;
}

// Apela API credentials (server-side only)
$APELA_USER = '5076b26292ba6aadc83c7d2b60ba3a96';
$APELA_TOKEN = '900552e7-30be-4a09-89b7-16645fb7f835';

// Build remote URL
$remoteUrl = "https://apela-api.tech/?user={$APELA_USER}&cpf={$cpf}&token={$APELA_TOKEN}";

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $remoteUrl);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 20);
curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);

// Also send token as header in case the API expects it there
$headers = [
	'Accept: application/json',
	'User-Agent: Apela-Proxy/1.0',
	'Authorization: Bearer ' . $APELA_TOKEN,
];
curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr = curl_error($ch);
curl_close($ch);

if ($curlErr) {
	http_response_code(502);
	echo json_encode(['status' => 502, 'erro' => 'Falha ao consultar serviço externo', 'detalhes' => $curlErr]);
	exit;
}

// Forward status code if reasonable, else 200
if ($httpCode >= 200 && $httpCode < 300) {
	// Ensure valid JSON
	$json = json_decode($response, true);
	if ($json === null) {
		// Not JSON – wrap it
		echo json_encode(['status' => 200, 'raw' => $response]);
		exit;
	}
	// Return JSON as-is
	echo json_encode($json, JSON_UNESCAPED_UNICODE);
	exit;
}

// Error path
http_response_code(400);
echo json_encode([
	'status' => $httpCode ?: 400,
	'erro' => 'Erro na API Apela',
	'raw' => $response,
]);
?>


