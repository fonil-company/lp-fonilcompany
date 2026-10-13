<?php
// Hostinger cron (every 15 min): php /home/USER/domains/DOMAIN/private/cron-retry-crm.php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require __DIR__ . '/lib.php';

$rows = db()->query("SELECT * FROM leads WHERE status_crm = 'pendente' AND crm_tentativas < 50 ORDER BY id LIMIT 50")->fetchAll();
$sent = 0;
foreach ($rows as $lead) {
    $ok = send_to_crm($lead);
    mark_crm((int) $lead['id'], $ok);
    $sent += (int) $ok;
}
echo date('c') . " pending=" . count($rows) . " sent={$sent}\n";
