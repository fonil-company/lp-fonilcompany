<?php
declare(strict_types=1);
date_default_timezone_set('America/Sao_Paulo');

const FATURAMENTO = [
    'abaixo-200k' => 'Abaixo de R$ 200 mil',
    '200k-500k' => 'R$ 200 mil a menos de R$ 500 mil',
    '500k-1m' => 'R$ 500 mil a menos de R$ 1 milhão',
    '1m-3m' => 'R$ 1 milhão a menos de R$ 3 milhões',
    '3m-ou-mais' => 'R$ 3 milhões ou mais',
    'prefiro-nao-informar' => 'Prefiro não informar',
];
const CARGO = [
    'proprietario-socio' => 'Proprietário(a)/Sócio(a)',
    'diretor' => 'Diretor(a)',
    'gerente-comercial' => 'Gerente comercial',
    'gerente-marketing' => 'Gerente de marketing',
    'outro' => 'Outro',
];
const UTM_FIELDS = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid', 'pagina'];

function config(): array
{
    static $cfg;
    return $cfg ??= require __DIR__ . '/config.php';
}

function db(): PDO
{
    static $pdo;
    if (!$pdo) {
        $c = config()['db'];
        $pdo = new PDO($c['dsn'], $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}

/** Returns true when the CRM accepted the lead (2xx). */
function send_to_crm(array $lead): bool
{
    $url = config()['crm_webhook_url'] ?? '';
    if ($url === '') return false;

    $body = [
        'source' => 'landing-diagnostico',
        'event_id' => $lead['event_id'],
        'created_at' => $lead['criado_em'],
        'name' => $lead['nome'],
        'email' => $lead['email'],
        'phone' => '55' . $lead['whatsapp'],
        'company' => $lead['empresa'],
        'revenue_range' => FATURAMENTO[$lead['faturamento']] ?? $lead['faturamento'],
        'role' => CARGO[$lead['cargo']] ?? $lead['cargo'],
    ];
    foreach (UTM_FIELDS as $k) $body[$k] = $lead[$k] ?? '';

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
    ]);
    curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $failed = curl_errno($ch) !== 0;
    curl_close($ch);

    if ($failed || $status < 200 || $status >= 300) {
        error_log("[lead] CRM webhook failed for {$lead['event_id']} (HTTP {$status})");
        return false;
    }
    return true;
}

function mark_crm(int $id, bool $ok): void
{
    db()->prepare('UPDATE leads SET status_crm = ?, crm_tentativas = crm_tentativas + 1 WHERE id = ?')
        ->execute([$ok ? 'enviado' : 'pendente', $id]);
}

function notify_team(array $lead): void
{
    $cfg = config();
    if (empty($cfg['notify_to']) || empty($cfg['notify_from'])) return;

    $esc = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    $wa = 'https://wa.me/55' . $lead['whatsapp'];
    $rows = [
        'Nome' => $esc($lead['nome']),
        'Empresa' => $esc($lead['empresa']),
        'WhatsApp' => '<a href="' . $wa . '">' . $esc($lead['whatsapp']) . '</a>',
        'E-mail' => $esc($lead['email']),
        'Faturamento' => $esc(FATURAMENTO[$lead['faturamento']] ?? ''),
        'Cargo' => $esc(CARGO[$lead['cargo']] ?? ''),
    ];
    foreach (UTM_FIELDS as $k) if (!empty($lead[$k])) $rows[$k] = $esc($lead[$k]);

    $html = '<h2>Novo pedido de diagnóstico</h2><table cellpadding="6">';
    foreach ($rows as $k => $v) $html .= "<tr><td><b>{$k}</b></td><td>{$v}</td></tr>";
    $html .= '</table>';

    $subject = '=?UTF-8?B?' . base64_encode('Novo lead: ' . $lead['empresa']) . '?=';
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: Fonil Landing <' . $cfg['notify_from'] . '>',
        'Reply-To: ' . str_replace(["\r", "\n"], '', $lead['email']),
    ]);
    if (!@mail($cfg['notify_to'], $subject, $html, $headers)) {
        error_log("[lead] notification e-mail failed for {$lead['event_id']}");
    }
}
