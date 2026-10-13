<?php
// Copie para config.php (mesma pasta) e preencha. Nunca versione o config.php.
return [
    'site_host'       => 'fonilgroup.com.br',   // checagem de Origin (aceita também www.)
    'crm_webhook_url' => '',                    // URL completa do webhook do Supabase, com ?token=...
    'ip_salt'         => '',                    // string aleatória longa, ex.: bin2hex(random_bytes(24))
];
