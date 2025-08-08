<?php
/**
 * WHMCS Quote Reminder Addon
 * Desarrollado por Digital Media Group - https://digitalmediagroup.es
 * Licencia: MIT
 */

if (!defined('WHMCS')) { die('Access denied'); }

use WHMCS\Database\Capsule;

/*-----------------------------------------------------------------
 CONFIG
-----------------------------------------------------------------*/
function quotreminder_config() {
    return [
        'name'        => 'Quote Reminder',
        'description' => 'Hasta 7 recordatorios automáticos con PDF adjunto, link y cancelación automática.',
        'author'      => 'Digital Media Group',
        'version'     => '2.2',
        'fields'      => []
    ];
}

/*-----------------------------------------------------------------
 ACTIVAR (crea tablas)
-----------------------------------------------------------------*/
function quotreminder_activate() {
    try {
        // Tabla de configuración
        if (!Capsule::schema()->hasTable('whmcsmod_quote_reminders')) {
            Capsule::schema()->create('whmcsmod_quote_reminders', function ($t) {
                $t->increments('id');
                $t->tinyInteger('num');
                $t->integer('days_after');
                $t->string('template_name', 150);
            });
        }
        // Tabla de log
        if (!Capsule::schema()->hasTable('whmcsmod_quote_reminder_log')) {
            Capsule::schema()->create('whmcsmod_quote_reminder_log', function ($t) {
                $t->increments('id');
                $t->integer('quote_id');
                $t->tinyInteger('reminder_num');
                $t->dateTime('date_sent');
            });
        }
        return ['status'=>'success','description'=>'Tablas creadas correctamente.'];
    } catch (Throwable $e) {
        return ['status'=>'error','description'=>$e->getMessage()];
    }
}

/*-----------------------------------------------------------------
 DESACTIVAR (borra tablas salvo ?keepdata=1)
-----------------------------------------------------------------*/
function quotreminder_deactivate() {
    if (isset($_REQUEST['keepdata']) && $_REQUEST['keepdata'] == '1') {
        return ['status'=>'success','description'=>'Datos conservados.'];
    }
    try {
        Capsule::schema()->dropIfExists('whmcsmod_quote_reminder_log');
        Capsule::schema()->dropIfExists('whmcsmod_quote_reminders');
        // Borra settings extra:
        Capsule::table('tbladdonmodules')->where('module','quotreminder')->whereIn('setting', [
            'dead_after_days','dead_send_mail','dead_deadtemplate'
        ])->delete();
        return ['status'=>'success','description'=>'Tablas eliminadas.'];
    } catch (Throwable $e) {
        return ['status'=>'error','description'=>$e->getMessage()];
    }
}

/*-----------------------------------------------------------------
 PANTALLA DE CONFIGURACIÓN
-----------------------------------------------------------------*/
function quotreminder_output($vars)
{
    // Guardar recordatorios (1-7)
    if (isset($_POST['save_reminders'])) {
        Capsule::table('whmcsmod_quote_reminders')->truncate();
        for ($i=1; $i<=7; $i++){
            $dias = (int)($_POST["days_after_$i"] ?? 0);
            $tpl  = trim($_POST["template_name_$i"] ?? '');
            if ($dias > 0 && $tpl !== '') {
                Capsule::table('whmcsmod_quote_reminders')->insert([
                    'num'           => $i,
                    'days_after'    => $dias,
                    'template_name' => $tpl
                ]);
            }
        }
        echo '<div class="successbox">Recordatorios guardados.</div>';
    }

    // Guardar configuración "presupuesto muerto"
    if (isset($_POST['dead_settings_save'])) {
        $dead_days = (int)($_POST['dead_after_days'] ?? 0);
        $dead_send = isset($_POST['dead_send_mail']) ? 1 : 0;
        $dead_tpl  = trim($_POST['dead_deadtemplate'] ?? '');

        // Dead days
        if (Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_after_days')->count()) {
            Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_after_days')->update(['value'=>$dead_days]);
        } else {
            Capsule::table('tbladdonmodules')->insert(['module'=>'quotreminder','setting'=>'dead_after_days','value'=>$dead_days]);
        }
        // Dead send mail
        if (Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_send_mail')->count()) {
            Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_send_mail')->update(['value'=>$dead_send]);
        } else {
            Capsule::table('tbladdonmodules')->insert(['module'=>'quotreminder','setting'=>'dead_send_mail','value'=>$dead_send]);
        }
        // Dead template
        if (Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_deadtemplate')->count()) {
            Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_deadtemplate')->update(['value'=>$dead_tpl]);
        } else {
            Capsule::table('tbladdonmodules')->insert(['module'=>'quotreminder','setting'=>'dead_deadtemplate','value'=>$dead_tpl]);
        }

        echo '<div class="successbox">Configuración sobre presupuesto "muerto" guardada.</div>';
    }

    // Cargar datos actuales "muerto"
    $dead_days_raw = Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_after_days')->value('value');
    $dead_days = is_null($dead_days_raw) ? 0 : (int)$dead_days_raw;

    $dead_send_raw = Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_send_mail')->value('value');
    $dead_send = is_null($dead_send_raw) ? 0 : (int)$dead_send_raw;

    $dead_tpl = Capsule::table('tbladdonmodules')->where('module','quotreminder')->where('setting','dead_deadtemplate')->value('value');
    if (!is_string($dead_tpl)) $dead_tpl = '';

    // Datos para desplegables
    $plantillas = Capsule::table('tblemailtemplates')
                 ->where('type','general')
                 ->orderBy('name')->get();
    $reminders  = Capsule::table('whmcsmod_quote_reminders')
                 ->get()->keyBy('num');

    echo '<h2>Configuración de los 7 recordatorios</h2>
    <form method="post">
      <table class="form" width="100%" cellpadding="4">
        <tr><th>#</th><th>Días después de la creación</th><th>Plantilla de correo</th></tr>';

    for ($i=1; $i<=7; $i++){
        $d   = $reminders[$i]->days_after     ?? '';
        $tpl = $reminders[$i]->template_name ?? '';
        echo "<tr>
               <td style='text-align:center'>$i</td>
               <td><input type='number' name='days_after_$i' value='".htmlspecialchars($d)."' min='1' style='width:80px'></td>
               <td><select name='template_name_$i'><option value=''>-- Seleccione --</option>";
        foreach ($plantillas as $p){
            $sel = ($p->name == $tpl) ? 'selected':'';
            echo "<option value='".htmlspecialchars($p->name)."' $sel>"
                 .htmlspecialchars($p->name)."</option>";
        }
        echo "</select></td></tr>";
    }
    echo '</table><br>
        <input type="submit" name="save_reminders" value="Guardar" class="btn btn-primary">
    </form>';

    // Sección de cancelación/muerte automática
    echo '<hr><h3>Presupuestos: muerte/cancelación automática</h3>
<form method="post">
    <label>Días tras crear para marcar como "muerto":
        <input type="number" name="dead_after_days" value="'.htmlspecialchars($dead_days).'" min="1" style="width:60px;"></label>
    <br><label><input type="checkbox" name="dead_send_mail" value="1" '.($dead_send?'checked':'').'> Enviar email al cliente al marcar como muerto/cancelado</label>
    <br>
    <label>Plantilla de email (si se envía): 
    <select name="dead_deadtemplate"><option value="">-- Selecciona --</option>';
    foreach ($plantillas as $p) {
        $sel = ($p->name == $dead_tpl) ? 'selected' : '';
        echo "<option value=\"".htmlspecialchars($p->name)."\" $sel>".htmlspecialchars($p->name)."</option>";
    }
    echo '</select></label>
    <br><br><input type="submit" name="dead_settings_save" value="Guardar" class="btn btn-danger">
</form>
<div style="margin-top:1em;padding:1em;border:1px solid #eaeaea;color:#222;">
    <b>NOTA:</b> Tus plantillas deben ser de tipo <b>general</b> y usar la variable <b>{$QUOTE_URL}</b> para mostrar el enlace al presupuesto.<br>
    Cada correo llevará adjunto el PDF generado automáticamente.
</div>';
}
