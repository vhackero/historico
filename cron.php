<?php
/**
 * Ejecución de respaldos y Registro de Auditoría.
 * Ubicación: /local/versionamiento_de_aulas/cron.php
 */

define('CLI_SCRIPT', true);
require_once(__DIR__ . '/../../config.php');
require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');

global $DB, $CFG;

$pendientes = $DB->get_records('local_ver_aulas_cola', ['status' => 'pendiente']);

foreach ($pendientes as $reg) {
    try {
        $reg->status = 'procesando';
        $DB->update_record('local_ver_aulas_cola', $reg);

        $bc = new backup_controller(backup::TYPE_1COURSE, $reg->courseid, backup::FORMAT_MOODLE,
            backup::INTERACTIVE_NO, backup::MODE_GENERAL, $reg->userid);

        $bc->execute_plan();
        $results = $bc->get_results();

        if (isset($results['backup_destination'])) {
            $file = $results['backup_destination'];
            $reg->backupfileid = $file->get_id();
            $reg->status = 'finalizado';
            $reg->timecreated = time();
            $DB->update_record('local_ver_aulas_cola', $reg);

            // --- REGISTRO DE TRAZABILIDAD PARA EL ADMIN ---
            $log = new stdClass();
            $log->userid      = $reg->userid;
            $log->courseid    = $reg->courseid;
            $log->action      = 'respaldo_finalizado';
            $log->info        = "Respaldo automático generado con éxito.";
            $log->timecreated = time();
            $DB->insert_record('local_ver_aulas_logs', $log);
            echo "✅ Respaldo y log registrados." . PHP_EOL;
        }
        $bc->destroy();
        echo "✅ Respaldo finalizado para ID: {$reg->id}\n";
    } catch (Exception $e) {
        $reg->status = 'error';
        $DB->update_record('local_ver_aulas_cola', $reg);
        echo "❌ Error: " . $e->getMessage() . "\n";
    }
}