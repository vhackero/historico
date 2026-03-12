<?php
defined('MOODLE_INTERNAL') || die();

if ($hassiteconfig) {
    // 1. CATEGORÍA RAÍZ
    $ADMIN->add('localplugins', new admin_category('local_versionamiento_root', 'Histórico y reutilización de Aulas'));

    // 2. PANEL DE CONTROL E HISTÓRICO
    $ADMIN->add('local_versionamiento_root', new admin_externalpage('local_versionamiento_admin', 'Panel de control', new moodle_url('/local/versionamiento_de_aulas/admin.php')));
    $ADMIN->add('local_versionamiento_root', new admin_externalpage('local_versionamiento_delete', 'Histórico de Aulas', new moodle_url('/local/versionamiento_de_aulas/admin_delete_courses.php'), 'moodle/site:config'));

    // 3. CONFIGURACIÓN DE PERIODOS (ESTO NO SE TOCA)
    $settings_periodos = new admin_settingpage('local_versionamiento_de_aulas_periodos', 'Configuración de periodos');
    $settings_periodos->add(new admin_setting_heading('header_respaldos', 'Periodo para la generación de respaldos', 'Rango de fechas en los que el plugin permitirá solicitar respaldos.'));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/respaldo_inicio', 'Inicio de solicitudes', '', '', PARAM_TEXT));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/respaldo_fin', 'Fin de solicitudes', '', '', PARAM_TEXT));
    $settings_periodos->add(new admin_setting_heading('header_restauracion', 'Periodo para la restauración de aulas', 'Rango de fechas en los que el plugin permitirá restaurar aulas.'));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/restaurar_inicio', 'Inicio de restauración', '', '', PARAM_TEXT));
    $settings_periodos->add(new admin_setting_configtext('local_versionamiento_de_aulas/restaurar_fin', 'Fin de restauración', '', '', PARAM_TEXT));
    $ADMIN->add('local_versionamiento_root', $settings_periodos);

    // 4. CONFIGURACIÓN DEL PLUGIN (AQUÍ SÓLO AGREGAMOS LO NECESARIO)
    $settings_tecnico = new admin_settingpage('local_versionamiento_de_aulas', 'Configuración de plugin');

    // Se agregan los campos de programación para que la tarea de respaldos sepa cuándo actuar
    $settings_tecnico->add(new admin_setting_heading('header_cron_respaldos', 'Configuración de ejecución del cron (Respaldos)', 'Define la programación para procesar la cola de respaldos.'));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/backup_cron_date', 'Fecha de ejecución respaldos', 'Formato YYYY-MM-DD', '', PARAM_TEXT));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/backup_cron_hour', 'Hora de ejecución respaldos', 'Hora (0-23)', '0', PARAM_RAW));

    $options_freq = [
        '1' => 'Cada minuto',
        '5' => 'Cada 5 minutos',
        '15' => 'Cada 15 minutos',
        '30' => 'Cada 30 minutos',
        '60' => 'Cada hora'
    ];
    $settings_tecnico->add(new admin_setting_configselect('local_versionamiento_de_aulas/backup_cron_freq', 'Frecuencia de ejecución del cron', '', '5', $options_freq));

    // Campos originales de limpieza y repositorio
    $settings_tecnico->add(new admin_setting_heading('header_tecnico', 'Configuración Técnica', ''));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/cron_eliminar_mbz_fecha', 'Fecha para eliminar archivos .mbz', '', '', PARAM_TEXT));
    $settings_tecnico->add(new admin_setting_configcheckbox('local_versionamiento_de_aulas/use_repository_path', 'Guardar respaldos en repositorio externo', 'Si se habilita, se guardará también una copia comprimida (.zst) en la ruta del repositorio.', 0));
    $settings_tecnico->add(new admin_setting_configtext('local_versionamiento_de_aulas/repository_path', 'Ruta del repositorio', '', '/www/backups/', PARAM_TEXT));
    $settings_tecnico->add(new admin_setting_configduration('local_versionamiento_de_aulas/retention_days', 'Días de disponibilidad del respaldo', '', 60 * 60 * 24 * 30));

    $ADMIN->add('local_versionamiento_root', $settings_tecnico);

    // JS para DatePickers
    if (strpos($_SERVER['REQUEST_URI'], 'section=local_versionamiento_') !== false) {
        echo '<script>
            window.onload = function() {
                var dates = ["respaldo_inicio", "respaldo_fin", "restaurar_inicio", "restaurar_fin", "backup_cron_date", "cron_eliminar_mbz_fecha"];
                dates.forEach(function(id) {
                    var input = document.getElementById("id_s_local_versionamiento_de_aulas_" + id);
                    if (input) input.type = "date";
                });
            }
        </script>';
    }
}