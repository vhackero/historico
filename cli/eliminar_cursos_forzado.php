<?php
/**
 * ELIMINACIÓN FORZADA DE CURSOS - VERSIÓN ULTRA LIMPIEZA
 * Incluye: Moodle Core, Logs Estándar, Cola Local y Registros de Historial.
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');
require_once($CFG->libdir . '/cronlib.php');
require_once($CFG->dirroot . '/course/lib.php');

@ini_set('max_execution_time', '0');
@ini_set('memory_limit', '-1');
core_php_time_limit::raise(0);

// 1. Definición de parámetros
list($options, $unrecognized) = cli_get_params(
    [
        'coursename' => '',
        'dryrun'     => 'true',
        'force'      => false,
        'deletefile' => false
    ],
    ['d' => 'dryrun', 'f' => 'force']
);

$is_dry_run = true;
if ($options['dryrun'] === false || $options['dryrun'] === 'false' || $options['dryrun'] === 0 || $options['dryrun'] === '0') {
    $is_dry_run = false;
}

if ($is_dry_run) {
    echo "\n╔══════════════════════════════════════════════════════════════╗\n";
    echo "║                     MODO DRY RUN ACTIVADO                    ║\n";
    echo "╚══════════════════════════════════════════════════════════════╝\n\n";
}

// 2. Búsqueda de cursos
$courses_to_delete = [];
if (!empty($options['coursename'])) {
    $search = $options['coursename'];
    $select = $DB->sql_like('fullname', ':p1', false) . " OR " . $DB->sql_like('shortname', ':p2', false);
    $params = ['p1' => "%$search%", 'p2' => "%$search%"];
    $courses_to_delete = $DB->get_records_select('course', $select, $params, 'id ASC');
}

if (empty($courses_to_delete)) {
    echo "✅ No hay cursos para el criterio: " . $options['coursename'] . "\n";
    exit(0);
}

echo "🔍 Encontrados " . count($courses_to_delete) . " cursos para purgar.\n\n";

$target_dir = get_config('local_versionamiento_de_aulas', 'repository_path');

foreach ($courses_to_delete as $course) {
    if ($course->id == SITEID) continue;

    echo "📦 Procesando limpieza total: [$course->id] $course->fullname\n";

    if (!$is_dry_run) {
        // A. Obtener contexto antes de borrar el curso (necesario para los logs)
        $coursecontext = \context_course::instance($course->id);

        // B. BORRADO NATIVO DE MOODLE (Actividades, archivos de curso, notas)
        if (delete_course($course, false)) {
            echo "   ✅ Eliminado de Moodle Core.\n";

            // C. LIMPIEZA DE LOGS ESTÁNDAR (mdl_logstore_standard_log)
            // Borramos por contextid para asegurar que no quede rastro de actividad
            $DB->delete_records('logstore_standard_log', ['contextid' => $coursecontext->id]);
            echo "   🔥 Logs de actividad (standard_log) purgados.\n";

            // D. LIMPIEZA DE TU TABLA DE COLA (local_ver_aulas_cola)
            $registro_cola = $DB->get_record('local_ver_aulas_cola', ['courseid' => $course->id]);
            if ($registro_cola) {
                // Borrado opcional de archivo físico .mbz
                if ($options['deletefile'] && !empty($target_dir)) {
                    $pattern = $target_dir . "Respaldo_*_ID{$course->id}_*";
                    foreach (glob($pattern) as $filename) {
                        if (@unlink($filename)) echo "   🗑️ Archivo externo borrado: " . basename($filename) . "\n";
                    }
                }
                $DB->delete_records('local_ver_aulas_cola', ['id' => $registro_cola->id]);
                echo "   🧹 Registro de cola eliminado.\n";
            }

            // E. REGISTRO FINAL DE AUDITORÍA (local_ver_aulas_logs)
            $log = new stdClass();
            $log->courseid    = $course->id;
            $log->userid      = 0;
            $log->action      = 'purga_total_cli';
            $log->info        = "Limpieza absoluta (Curso + Logs + Cola). Criterio: " . $options['coursename'];
            $log->timecreated = time();
            $DB->insert_record('local_ver_aulas_logs', $log);
            echo "   📝 Acción registrada en historial local.\n";

        } else {
            echo "   ❌ Error crítico al borrar curso ID: $course->id\n";
        }
    } else {
        echo "   (Simulación) Se purgarían logs, archivos, registros de cola y el curso.\n";
    }
}

echo "\n✨ Purga total finalizada.\n";