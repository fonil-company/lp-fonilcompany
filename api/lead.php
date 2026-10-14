<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// ---------- helpers ----------
function config(): array {
    static $c;
    if ($c) return $c;
    // private/ fora do public_html (recomendado) ou, como fallback, dentro dele
    foreach ([dirname(__DIR__, 2) . '/private/config.php', dirname(__DIR__) . '/private/config.php'] as $path) {
        if (is_file($path)) return $c = require $path;
    }
    // Docker/Easypanel: credenciais via variáveis de ambiente
    if (getenv('CRM_WEBHOOK_URL')) {
        return $c = [
            'site_host'       => (string) getenv('SITE_HOST'),
            'crm_webhook_url' => (string) getenv('CRM_WEBHOOK_URL'),
            'ip_salt'         => (string) getenv('IP_SALT'),
            'meta_pixel_id'        => (string) (getenv('META_PIXEL_ID') ?: '1014610764961858'),
            'meta_capi_token'      => (string) getenv('META_CAPI_TOKEN'),
            'meta_test_event_code' => (string) getenv('META_TEST_EVENT_CODE'),
        ];
    }
    error_log('[lead.php] private/config.php não encontrado e CRM_WEBHOOK_URL não definida');
    respond(500, ['ok' => false, 'message' => 'Não foi possível enviar agora. Tente novamente em instantes.']);
}

function respond(int $code, array $body): never {
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}

// Real visitor IP: behind Hostinger CDN / Traefik REMOTE_ADDR is the proxy
function client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $h) {
        foreach (explode(',', (string) ($_SERVER[$h] ?? '')) as $ip) {
            $ip = trim($ip);
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $ip;
        }
    }
    return (string) ($_SERVER['REMOTE_ADDR'] ?? '');
}

// ---------- Meta Conversions API: Lead (dedup with browser Pixel via event_id) ----------
function send_capi_lead(array $cfg, array $lead): void {
    $pixel = $cfg['meta_pixel_id'] ?? '';
    $token = $cfg['meta_capi_token'] ?? '';
    if ($pixel === '' || $token === '') return;

    $h = fn(string $v) => $v === '' ? null : hash('sha256', $v);
    $norm = fn(string $v) => mb_strtolower(trim(preg_replace('/\s+/u', ' ', $v)));
    $parts = explode(' ', $norm($lead['nome']));

    // _fbc: cookie set by the Pixel; fallback built from the ad click's fbclid
    $fbc = (string) ($_COOKIE['_fbc'] ?? '');
    if ($fbc === '' && $lead['fbclid'] !== '') $fbc = 'fb.1.' . (int) (microtime(true) * 1000) . '.' . $lead['fbclid'];

    $userData = array_filter([
        'em'                => [$h($lead['email'])],
        'ph'                => [$h('55' . $lead['whatsapp'])],
        'fn'                => [$h($parts[0] ?? '')],
        'ln'                => [$h(count($parts) > 1 ? end($parts) : '')],
        'country'           => [$h('br')],
        'external_id'       => [$h($lead['email'])],
        'client_ip_address' => client_ip(),
        'client_user_agent' => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500),
        'fbp'               => (string) ($_COOKIE['_fbp'] ?? ''),
        'fbc'               => $fbc,
    ], fn($v) => is_array($v) ? $v[0] !== null : $v !== '');

    $payload = [
        'data' => [[
            'event_name'       => 'Lead',
            'event_time'       => time(),
            'event_id'         => $lead['event_id'],
            'event_source_url' => $lead['pagina'] ?: 'https://' . ($cfg['site_host'] ?: 'fonilgroup.com.br') . '/',
            'action_source'    => 'website',
            'user_data'        => $userData,
            'custom_data'      => [
                'content_name' => 'diagnostico_comercial',
                'faturamento'  => $lead['faturamento'],
                'cargo'        => $lead['cargo'],
            ],
        ]],
    ];
    if (!empty($cfg['meta_test_event_code'])) $payload['test_event_code'] = $cfg['meta_test_event_code'];

    $ch = curl_init('https://graph.facebook.com/v24.0/' . rawurlencode($pixel) . '/events?access_token=' . rawurlencode($token));
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err      = curl_error($ch);
    curl_close($ch);
    if ($err || $status < 200 || $status >= 300) {
        error_log("[lead.php] Meta CAPI failed – HTTP {$status} – {$err} – body: {$response}");
    }
}

// ---------- 1. Receive: POST + JSON only ----------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(405, ['ok' => false, 'message' => 'Método não permitido.']);
}

$cfg  = config();
$host = strtolower($cfg['site_host']);

// Checa Origin (se site_host estiver preenchido)
if ($host !== '') {
    $origin = strtolower((string) parse_url($_SERVER['HTTP_ORIGIN'] ?? '', PHP_URL_HOST));
    if ($origin !== $host && $origin !== 'www.' . $host) {
        respond(403, ['ok' => false, 'message' => 'Origem não autorizada.']);
    }
}

$in = json_decode((string) file_get_contents('php://input', false, null, 0, 20000), true);
if (!is_array($in)) respond(400, ['ok' => false, 'message' => 'Requisição inválida.']);

$str = fn(string $k, int $max = 255) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);

// ---------- 2. Anti-spam: honeypot + tempo mínimo ----------
if ($str('website') !== '' || (int) ($in['elapsed_ms'] ?? 0) < 3000) {
    respond(200, ['ok' => true, 'crm_sent' => false]); // silently accept bots without recording a conversion
}

// ---------- Rate limit simples (arquivo, sem DB) ----------
$ipHash = hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . ($cfg['ip_salt'] ?? ''));
$rlDir  = sys_get_temp_dir() . '/fonil_rl';
if (!is_dir($rlDir)) @mkdir($rlDir, 0700, true);
$rlFile = $rlDir . '/' . $ipHash;
$now    = time();

if (is_file($rlFile)) {
    $stamps = array_filter(explode("\n", file_get_contents($rlFile)), fn($t) => (int)$t > ($now - 600));
    if (count($stamps) >= 5) {
        respond(429, ['ok' => false, 'message' => 'Muitas tentativas. Aguarde alguns minutos e tente novamente.']);
    }
} else {
    $stamps = [];
}

// ---------- Listas válidas ----------
$FATURAMENTO = [
    'abaixo-200k', '200k-500k', '500k-1m', '1m-3m', '3m-ou-mais', 'prefiro-nao-informar',
];
$FATURAMENTO_LABEL = [
    'abaixo-200k' => 'Abaixo de R$ 200 mil',
    '200k-500k' => 'R$ 200 mil a menos de R$ 500 mil',
    '500k-1m' => 'R$ 500 mil a menos de R$ 1 milhão',
    '1m-3m' => 'R$ 1 milhão a menos de R$ 3 milhões',
    '3m-ou-mais' => 'R$ 3 milhões ou mais',
    'prefiro-nao-informar' => 'Prefiro não informar',
];
$CARGO = [
    'proprietario-socio', 'diretor', 'gerente-comercial', 'gerente-marketing', 'outro',
];
$CARGO_LABEL = [
    'proprietario-socio' => 'Proprietário(a)/Sócio(a)',
    'diretor' => 'Diretor(a)',
    'gerente-comercial' => 'Gerente comercial',
    'gerente-marketing' => 'Gerente de marketing',
    'outro' => 'Outro',
];
$UTM = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'pagina'];

// ---------- 3. Validação server-side ----------
$lead = [
    'nome'        => $str('nome', 120),
    'whatsapp'    => preg_replace('/\D/', '', $str('whatsapp', 20)),
    'email'       => mb_strtolower($str('email', 160)),
    'empresa'     => $str('empresa', 160),
    'faturamento' => $str('faturamento', 40),
    'cargo'       => $str('cargo', 40),
    'event_id'    => $str('event_id', 36),
];
foreach ($UTM as $k) $lead[$k] = $str($k, $k === 'pagina' ? 500 : 255);

$errors = [];
if (!preg_match('/^\S{2,}(\s+\S+)+$/u', $lead['nome']))       $errors['nome']        = 'Informe seu nome e sobrenome.';
if (!preg_match('/^[1-9]\d{9,10}$/', $lead['whatsapp']))       $errors['whatsapp']    = 'Informe o WhatsApp com DDD.';
if (!filter_var($lead['email'], FILTER_VALIDATE_EMAIL))        $errors['email']       = 'Informe um e-mail válido.';
if (mb_strlen($lead['empresa']) < 2)                           $errors['empresa']     = 'Informe o nome da empresa.';
if (!in_array($lead['faturamento'], $FATURAMENTO, true))       $errors['faturamento'] = 'Selecione uma faixa de faturamento.';
if (!in_array($lead['cargo'], $CARGO, true))                   $errors['cargo']       = 'Selecione seu cargo na empresa.';

if ($errors) respond(422, ['ok' => false, 'message' => 'Revise os campos destacados.', 'errors' => $errors]);

// ---------- 4. Salva contagem de rate-limit ----------
$stamps[] = (string) $now;
@file_put_contents($rlFile, implode("\n", $stamps));

// ---------- 5. Enviar ao CRM (Supabase webhook) ----------
$webhookUrl = trim((string) ($cfg['crm_webhook_url'] ?? ''));
if ($webhookUrl === '') {
    error_log('[lead.php] Webhook CRM não configurado');
    respond(500, ['ok' => false, 'message' => 'Não foi possível enviar agora. Tente novamente em instantes.']);
}
$body = [
    'source'        => 'landing-diagnostico',
    'event_id'      => $lead['event_id'],
    'created_at'    => date('c'),
    'name'          => $lead['nome'],
    'email'         => $lead['email'],
    'phone'         => '55' . $lead['whatsapp'],
    'company'       => $lead['empresa'],
    'revenue_range' => $FATURAMENTO_LABEL[$lead['faturamento']] ?? $lead['faturamento'],
    'role'          => $CARGO_LABEL[$lead['cargo']] ?? $lead['cargo'],
];
foreach ($UTM as $k) $body[$k] = $lead[$k] ?? '';

if ($webhookUrl !== '') {
    $ch = curl_init($webhookUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
    ]);
    $response = curl_exec($ch);
    $status   = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err || $status < 200 || $status >= 300) {
        error_log("[lead.php] Webhook failed – HTTP {$status} – {$err} – body: {$response}");
        // Salva localmente como backup
        $backupDir = (is_dir(dirname(__DIR__, 2) . '/private') ? dirname(__DIR__, 2) : dirname(__DIR__)) . '/private/leads_backup';
        if (!is_dir($backupDir)) @mkdir($backupDir, 0700, true);
        @file_put_contents(
            $backupDir . '/' . date('Y-m-d_H-i-s') . '_' . substr($lead['event_id'], 0, 8) . '.json',
            json_encode($body, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        respond(500, ['ok' => false, 'message' => 'Não foi possível enviar agora. Tente novamente em instantes.']);
    }
}

// ---------- 6. Sucesso: responde ao visitante e só depois envia ao Meta ----------
ignore_user_abort(true);
http_response_code(200);
echo json_encode(['ok' => true, 'crm_sent' => true]);
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
elseif (function_exists('litespeed_finish_request')) litespeed_finish_request();

// ---------- 7. Meta Conversions API (respeita a recusa no banner de cookies) ----------
if (($in['consent'] ?? '') !== 'denied') send_capi_lead($cfg, $lead);
exit;
