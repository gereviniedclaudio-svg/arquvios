<?php
/**
 * Valor da taxa de inscricao - Concurso SES TO 2026 (Edital 001/2026)
 * Medio/Tecnico: R$ 100,00 | Superior: R$ 150,00
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$nivel = strtolower((string) ($_GET['nivel'] ?? $_GET['level'] ?? ''));
$cargo = strtolower((string) ($_GET['cargo'] ?? ''));
$cargo = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $cargo) ?: $cargo;

$isMedio = false;
if ($nivel !== '') {
    $isMedio = (strpos($nivel, 'med') !== false || strpos($nivel, 'tec') !== false);
} elseif ($cargo !== '') {
    $isMedio = (
        strpos($cargo, 'tecnico') !== false ||
        strpos($cargo, 'medio') !== false ||
        strpos($cargo, 'assistente') !== false ||
        strpos($cargo, 'instrumentador') !== false ||
        strpos($cargo, 'imobilizacao') !== false ||
        strpos($cargo, 'radiologia') !== false ||
        strpos($cargo, 'laboratorio') !== false ||
        strpos($cargo, 'saude bucal') !== false
    );
}

$valor = $isMedio ? 100.00 : 150.00;
$label = $isMedio
    ? 'Taxa de Inscricao SES TO 2026 - Nivel Medio/Tecnico'
    : 'Taxa de Inscricao SES TO 2026 - Nivel Superior';

echo json_encode([
    'success' => true,
    'data' => [
        'valor' => $valor,
        'valorFormatado' => 'R$ ' . number_format($valor, 2, ',', '.'),
        'label' => $label,
        'produto' => $label,
        'nivel' => $isMedio ? 'medio' : 'superior',
        'edital' => '001/2026 SECAD/SES/TO',
        'banca' => 'FGV',
    ],
], JSON_UNESCAPED_UNICODE);