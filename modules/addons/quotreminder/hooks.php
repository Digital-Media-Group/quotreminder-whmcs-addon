<?php
/**
 * Quote Reminder para WHMCS
 * Desarrollado por Digital Media Group - https://digitalmediagroup.es
 * GitHub: https://github.com/tuusuario/quotreminder-whmcs-addon
 * 
 * Licencia: MIT
 */

if (!defined('WHMCS')) { die('Access denied'); }
use WHMCS\Database\Capsule;

add_hook('DailyCronJob',1,function(){

    $rem = Capsule::table('whmcsmod_quote_reminders')->get();
    if ($rem->isEmpty()) return;

    $systemURL = \WHMCS\Config\Setting::getValue('SystemURL');
    $quotes    = Capsule::table('tblquotes')->where('stage','Sent')->get();
    $now       = time();

    // Recordatorios (enviar sólo si aún está "Sent")
    foreach($quotes as $q){
        $created = strtotime($q->datecreated);
        foreach($rem as $r){
            if ($now < $created + $r->days_after*86400) continue;
            $sent = Capsule::table('whmcsmod_quote_reminder_log')
                    ->where('quote_id',$q->id)
                    ->where('reminder_num',$r->num)->exists();
            if ($sent) continue;

            $pdfUrl = $systemURL."dl.php?type=q&id=".$q->id."&mode=download";
            $rawPdf = @file_get_contents($pdfUrl);
            $attachments=[];
            if($rawPdf){
                $attachments[]=[
                    'filename'=>"Presupuesto-{$q->id}.pdf",
                    'data'    => base64_encode($rawPdf)
                ];
            }

            $quoteURL = $systemURL."viewquote.php?id=".$q->id;

            $api = [
                'messagename' => $r->template_name,
                'id'          => $q->userid,
                'customvars'  => "QUOTE_URL=$quoteURL",
            ];
            if ($attachments) {
                $api['attachments'] = json_encode($attachments);
            }
            localAPI('SendEmail', $api);

            Capsule::table('whmcsmod_quote_reminder_log')->insert([
                'quote_id'     => $q->id,
                'reminder_num' => $r->num,
                'date_sent'    => date('Y-m-d H:i:s')
            ]);
            logActivity("Quote Reminder #{$r->num} enviado (QuoteID {$q->id})", $q->userid);
        }
    }

    // ----------- PRESUPUESTOS MUERTOS ("dead") -----------
    $dead_days = (int)Capsule::table('tbladdonmodules')
        ->where('module','quotreminder')->where('setting','dead_after_days')->value('value', 0);
    $dead_send = (int)Capsule::table('tbladdonmodules')
        ->where('module','quotreminder')->where('setting','dead_send_mail')->value('value', 0);
    $dead_tpl  = Capsule::table('tbladdonmodules')
        ->where('module','quotreminder')->where('setting','dead_deadtemplate')->value('value', '');

    if ($dead_days>0) {
        foreach($quotes as $q) {
            $fecha_creacion = strtotime($q->datecreated);
            $vence = $fecha_creacion + $dead_days*86400;
            if ($now >= $vence) {
                // Marcar como muerto/cancelado
                Capsule::table('tblquotes')->where('id', $q->id)->update(['stage'=>'Dead']);
                logActivity("Presupuesto #$q->id marcado como muerto automáticamente después de $dead_days días", $q->userid);

                // Avisar cliente (si aplica y aún no lo estaba)
                if ($dead_send && $dead_tpl) {
                    $quoteURL = $systemURL."viewquote.php?id=".$q->id;
                    $pdfUrl = $systemURL."dl.php?type=q&id=".$q->id."&mode=download";
                    $rawPdf = @file_get_contents($pdfUrl);
                    $attachments = [];
                    if ($rawPdf) {
                        $attachments[] = [
                            'filename' => "Presupuesto-{$q->id}.pdf",
                            'data'     => base64_encode($rawPdf)
                        ];
                    }
                    $api = [
                        'messagename' => $dead_tpl,
                        'id'          => $q->userid,
                        'customvars'  => "QUOTE_URL=$quoteURL",
                    ];
                    if ($attachments) {
                        $api['attachments'] = json_encode($attachments);
                    }
                    localAPI('SendEmail', $api);
                    logActivity("Aviso de presupuesto muerto enviado (quoteID $q->id)", $q->userid);
                }
            }
        }
    }

});
