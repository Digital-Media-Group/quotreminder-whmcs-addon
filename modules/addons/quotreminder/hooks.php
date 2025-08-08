<?php
/**
 * WHMCS Quote Reminder Addon
 * Desarrollado por Digital Media Group - https://digitalmediagroup.es
 * Licencia: MIT
 */

if (!defined('WHMCS')) { die('Access denied'); }

use WHMCS\Database\Capsule;

/**
 * Descarga binaria con cURL (si existe) o file_get_contents como fallback.
 */
function dmr_fetchBinary($url) {
    // cURL primero
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT      => 'WHMCS-QuoteReminder'
        ]);
        $data = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($data !== false && $code >= 200 && $code < 300) {
            return $data;
        }
    }
    // Fallback a file_get_contents
    $ctx = stream_context_create([
        'http' => ['timeout' => 20, 'header' => "User-Agent: WHMCS-QuoteReminder\r\n"]
    ]);
    $data = @file_get_contents($url, false, $ctx);
    if ($data !== false) {
        return $data;
    }
    return false;
}

/**
 * Lógica principal del módulo: envía recordatorios y marca como Dead si procede.
 */
function dmr_quoteReminderRunner() {
    // Recordatorios configurados
    $rem = Capsule::table('whmcsmod_quote_reminders')->get();
    if ($rem->isEmpty()) return;

    // Normalizar SystemURL
    $systemURL = \WHMCS\Config\Setting::getValue('SystemURL') ?: '';
    $systemURL = rtrim($systemURL, '/') . '/';

    // WHMCS suele usar Delivered/Sent al enviar al cliente
    $quotes = Capsule::table('tblquotes')->whereIn('stage', ['Sent','Delivered'])->get();
    if ($quotes->isEmpty()) return;

    $now = time();

    // 1) Envío de recordatorios
    foreach ($quotes as $q) {
        $created = strtotime($q->datecreated);

        foreach ($rem as $r) {
            // ¿Toca enviar?
            if ($now < $created + ($r->days_after * 86400)) {
                continue;
            }

            // ¿Ya enviado este número para este presupuesto?
            $sent = Capsule::table('whmcsmod_quote_reminder_log')
                ->where('quote_id', $q->id)
                ->where('reminder_num', $r->num)
                ->exists();
            if ($sent) {
                continue;
            }

            // Adjuntar PDF (si accesible)
            $pdfUrl = $systemURL . "dl.php?type=q&id=" . $q->id . "&mode=download";
            $rawPdf = dmr_fetchBinary($pdfUrl);
            $attachments = [];
            if ($rawPdf !== false && strlen($rawPdf) > 0) {
                $attachments[] = [
                    'filename' => "Presupuesto-{$q->id}.pdf",
                    'data'     => base64_encode($rawPdf),
                ];
            }

            // URL directa del presupuesto
            $quoteURL = $systemURL . "viewquote.php?id=" . $q->id;

            // Variables personalizadas (formato recomendado por WHMCS)
            $customvars = base64_encode(serialize([
                'QUOTE_URL' => $quoteURL,
                'QUOTE_ID'  => $q->id,
            ]));

            // Enviar correo
            $api = [
                'messagename' => $r->template_name,
                'id'          => $q->userid,
                'customvars'  => $customvars,
            ];
            if (!empty($attachments)) {
                // localAPI acepta array anidado de adjuntos
                $api['attachments'] = $attachments;
            }

            $resp = localAPI('SendEmail', $api);

            if (!is_array($resp) || ($resp['result'] ?? '') !== 'success') {
                $err = is_array($resp) ? json_encode($resp) : 'respuesta inválida';
                logActivity("Quote Reminder ERROR al enviar (QuoteID {$q->id}, R#{$r->num}): $err", $q->userid);
                continue;
            }

            // Marca como enviado
            Capsule::table('whmcsmod_quote_reminder_log')->insert([
                'quote_id'     => $q->id,
                'reminder_num' => $r->num,
                'date_sent'    => date('Y-m-d H:i:s'),
            ]);

            logActivity("Quote Reminder #{$r->num} enviado (QuoteID {$q->id})", $q->userid);
        }
    }

    // 2) Marcar como Dead si procede
    $dead_days_val = Capsule::table('tbladdonmodules')
        ->where('module', 'quotreminder')->where('setting', 'dead_after_days')->value('value');
    $dead_send_val = Capsule::table('tbladdonmodules')
        ->where('module', 'quotreminder')->where('setting', 'dead_send_mail')->value('value');
    $dead_tpl      = Capsule::table('tbladdonmodules')
        ->where('module', 'quotreminder')->where('setting', 'dead_deadtemplate')->value('value');

    $dead_days = is_null($dead_days_val) ? 0 : (int)$dead_days_val;
    $dead_send = is_null($dead_send_val) ? 0 : (int)$dead_send_val;
    if (!is_string($dead_tpl)) $dead_tpl = '';

    if ($dead_days > 0) {
        foreach ($quotes as $q) {
            $created  = strtotime($q->datecreated);
            $deadline = $created + ($dead_days * 86400);
            if ($now < $deadline) {
                continue;
            }

            // Marcar como Dead
            Capsule::table('tblquotes')->where('id', $q->id)->update(['stage' => 'Dead']);
            logActivity("Presupuesto #{$q->id} marcado como Dead automáticamente tras {$dead_days} días", $q->userid);

            // Aviso al cliente si aplica
            if ($dead_send && $dead_tpl) {
                $pdfUrl = $systemURL . "dl.php?type=q&id=" . $q->id . "&mode=download";
                $rawPdf = dmr_fetchBinary($pdfUrl);
                $attachments = [];
                if ($rawPdf !== false && strlen($rawPdf) > 0) {
                    $attachments[] = [
                        'filename' => "Presupuesto-{$q->id}.pdf",
                        'data'     => base64_encode($rawPdf),
                    ];
                }

                $quoteURL   = $systemURL . "viewquote.php?id=" . $q->id;
                $customvars = base64_encode(serialize([
                    'QUOTE_URL' => $quoteURL,
                    'QUOTE_ID'  => $q->id,
                ]));

                $api = [
                    'messagename' => $dead_tpl,
                    'id'          => $q->userid,
                    'customvars'  => $customvars,
                ];
                if (!empty($attachments)) {
                    $api['attachments'] = $attachments;
                }

                $resp = localAPI('SendEmail', $api);
                if (($resp['result'] ?? '') === 'success') {
                    logActivity("Aviso de presupuesto Dead enviado (QuoteID {$q->id})", $q->userid);
                } else {
                    $err = is_array($resp) ? json_encode($resp) : 'respuesta inválida';
                    logActivity("ERROR enviando aviso de Dead (QuoteID {$q->id}): $err", $q->userid);
                }
            }
        }
    }
}

// Ejecuta en el cron diario y al finalizar cualquier cron
add_hook('DailyCronJob', 1, 'dmr_quoteReminderRunner');
add_hook('AfterCronJob', 1, 'dmr_quoteReminderRunner');
