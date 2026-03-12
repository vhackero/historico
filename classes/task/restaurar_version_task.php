<?php
namespace local_versionamiento_de_aulas\task;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

class restaurar_version_task {

    public function ejecutar_restauracion($courseid, $fileid, $userid, $isweb = false) {
        global $DB, $CFG;

        try {
            $this->log("Iniciando proceso de restauración...", $isweb, 10);

            // 1. Obtener el archivo desde el file storage de Moodle
            $fs = get_file_storage();
            $file = $fs->get_file_by_id($fileid);

            if (!$file) {
                throw new \moodle_exception('filenotfound', 'error');
            }

            // 2. Preparar el directorio temporal de restauración
            $folder = restore_controller::get_tempdir_name($courseid, $userid);
            $path = $CFG->dataroot . '/temp/backup/' . $folder;
            $file->extract_to_pathname(get_file_packer('application/vnd.moodle.backup'), $path);

            $this->log("Archivo extraído. Configurando controlador...", $isweb, 40);

            // 3. Instanciar el restore_controller
            // MODE_SAMESITE: Restaura sobre el curso actual (borrando o mezclando)
            // TARGET_EXISTING_ADDING: Mezcla el contenido actual con el del respaldo
            // TARGET_EXISTING_DELETING: Borra el curso actual y pone el del respaldo
            $rc = new \restore_controller($folder, $courseid,
                \backup::INTERACTIVE_NO, \backup::MODE_SAMESITE, $userid,
                \backup::TARGET_EXISTING_DELETING);

            // 4. Ejecutar validaciones y plan de restauración
            if ($rc->execute_precheck()) {
                $this->log("Validación exitosa. Sobrescribiendo aula...", $isweb, 70);
                $rc->execute_plan();
                $this->log("✅ Aula restaurada con éxito.", $isweb, 100);
            } else {
                throw new \moodle_exception('error_precheck', 'local_versionamiento_de_aulas');
            }

            $rc->destroy();

        } catch (\Exception $e) {
            $this->log("❌ Error: " . $e->getMessage(), $isweb, 0);
        }
    }

    private function log($m, $web, $p) {
        if ($web) {
            echo "<script>updateUI('".addslashes($m)."', {$p});</script>";
            echo str_pad("", 4096);
            flush();
        }
    }
}