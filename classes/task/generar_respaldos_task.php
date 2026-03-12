<?php
namespace local_versionamiento_de_aulas\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
require_once($CFG->dirroot . '/local/versionamiento_de_aulas/lib.php');

class generar_respaldos_task extends \core\task\scheduled_task {
    public function get_name() { return "Procesar cola de respaldos de aulas"; }

    /**
     * Ejecuta el procesamiento de la cola.
     * @param bool $manual Indica si se ejecuta desde AJAX.
     * @param string|array|null $filter_ids IDs específicos a procesar.
     */
    public function execute($manual = false, $filter_ids = null) {
        global $DB, $CFG;

        if (!$manual) {
            $prog_fecha = get_config('local_versionamiento_de_aulas', 'backup_cron_date');
            $prog_hora  = (int)get_config('local_versionamiento_de_aulas', 'backup_cron_hour');
            if (!empty($prog_fecha)) {
                if (date('Y-m-d') < $prog_fecha || (date('Y-m-d') === $prog_fecha && (int)date('H') < $prog_hora)) {
                    return;
                }
            }
        }

        $params = ['status' => 'pendiente'];
        $sql_where = "status = :status";

        if ($manual && !empty($filter_ids)) {
            if (!is_array($filter_ids)) { $filter_ids = explode(',', $filter_ids); }
            list($insql, $inparams) = $DB->get_in_or_equal($filter_ids, SQL_PARAMS_NAMED);
            $sql_where .= " AND id $insql";
            $params = array_merge($params, $inparams);
        }

        $tareas = $DB->get_records_select('local_ver_aulas_cola', $sql_where, $params, 'timecreated ASC');
        $total = count($tareas);
        $count = 0;
        $admin = get_admin();
        $target_dir = get_config('local_versionamiento_de_aulas', 'repository_path');

        if (empty($tareas)) {
            if ($manual) $this->web_log("No hay respaldos pendientes.", 100);
            return;
        }

        foreach ($tareas as $t) {
            $count++;
            $p = round(($count / $total) * 100);
            $course = $DB->get_record('course', ['id' => $t->courseid], 'shortname');
            $shortname = $course ? $course->shortname : "ID {$t->courseid}";

            if ($manual) $this->web_log("Iniciando: {$shortname}", $p);

            $DB->set_field('local_ver_aulas_cola', 'status', 'procesando', ['id' => $t->id]);

            try {
                $bc = new \backup_controller(\backup::TYPE_1COURSE, $t->courseid, \backup::FORMAT_MOODLE,
                    \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, $admin->id);

                $plan = $bc->get_plan();
                if ($plan->setting_exists('users')) { $plan->get_setting('users')->set_value(0); }
                $bc->execute_plan();

                $results = $bc->get_results();
                if (isset($results['backup_destination'])) {
                    $file = $results['backup_destination'];
                    if (!is_dir($target_dir)) { @mkdir($target_dir, 0777, true); }

                    $clean_name = clean_filename($shortname);
                    $new_filename = "Respaldo_{$clean_name}_ID{$t->courseid}_T{$t->id}_" . date('Ymd_His') . ".mbz";
                    $mbzpath = $target_dir . $new_filename;
                    $file->copy_content_to($mbzpath);

                    $zstpath = local_versionamiento_de_aulas_compress_mbz_to_zst($mbzpath);
                    $zstfilename = basename($zstpath);

                    $fs = get_file_storage();
                    $file_record = [
                        'contextid' => \context_course::instance($t->courseid)->id,
                        'component' => 'local_versionamiento_de_aulas',
                        'filearea' => 'backup',
                        'itemid' => $t->id,
                        'filepath' => '/',
                        'filename' => $zstfilename,
                        'userid' => $t->userid,
                    ];
                    $stored_file = $fs->create_file_from_pathname($file_record, $zstpath);

                    $DB->update_record('local_ver_aulas_cola', (object)[
                        'id' => $t->id,
                        'status' => 'finalizado',
                        'backupfileid' => $stored_file->get_id(),
                        'timemodified' => time()
                    ]);

                    if ($manual) $this->web_log("Completado: {$shortname}", $p);
                }
                $bc->destroy();
            } catch (\Exception $e) {
                $DB->set_field('local_ver_aulas_cola', 'status', 'error', ['id' => $t->id]);
                if ($manual) $this->web_log("ERROR en {$shortname}: " . $e->getMessage(), $p);
            }
        }
        if ($manual) $this->web_log("Proceso terminado.", 100);
    }

    private function web_log($m, $p) {
        // Añadimos un relleno de 4096 bytes para forzar el envío en navegadores como Chrome/Edge
        echo "<script>if(typeof updateUI === 'function'){ updateUI('".addslashes($m)."', {$p}); }</script>\n";
        echo str_pad('', 4096) . "\n";

        // Limpiamos todos los niveles de búfer activos
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
}
