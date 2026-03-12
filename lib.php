<?php
defined('MOODLE_INTERNAL') || die();

/**
 * Inyecta el acceso al versionamiento automáticamente en el menú de navegación del curso.
 */
function local_versionamiento_de_aulas_extend_navigation_course($navigation, $course, $context) {
    global $USER, $DB;

    $rol_id_permitido = 10; // ID del rol Docente en Línea

    // Verificamos si el usuario es DL en este curso específico
    if (user_has_role_assignment($USER->id, $rol_id_permitido, $context->id)) {

        // CORRECCIÓN: Se añade el parámetro 'id' del curso actual a la URL
        $url = new moodle_url('/local/versionamiento_de_aulas/index.php', array('id' => $course->id));

        $navigation->add(
            'Reutilización del aula',
            $url,
            navigation_node::TYPE_SETTING,
            null,
            'versionamiento_dl',
            new pix_icon('i/backup', '')
        );
    }
}

/**
 * Sirve los archivos de respaldo MBZ.
 */
function local_versionamiento_de_aulas_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    if ($context->contextlevel != CONTEXT_SYSTEM && $context->contextlevel != CONTEXT_COURSE) {
        return false;
    }
    require_login();
    if ($filearea !== 'backup') {
        return false;
    }
    $fileid = array_shift($args);
    $fs = get_file_storage();
    $file = $fs->get_file_by_id($fileid);
    if (!$file) return false;
    send_stored_file($file, 0, 0, true, $options);
}

/**
 * Determina si un nombre de archivo corresponde a un respaldo comprimido con Zstandard.
 *
 * @param string $filename
 * @return bool
 */
function local_versionamiento_de_aulas_is_zst_filename(string $filename): bool {
    return (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'zst');
}

/**
 * Comprime un respaldo MBZ con /bin/zstd a nivel 20 y multihilo automático.
 *
 * @param string $mbzpath Ruta absoluta al archivo .mbz.
 * @return string Ruta absoluta del archivo .zst generado.
 * @throws moodle_exception Si falla la compresión.
 */
function local_versionamiento_de_aulas_compress_mbz_to_zst(string $mbzpath): string {
    if (!is_file($mbzpath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo MBZ no encontrado para compresión.');
    }

    $zstpath = $mbzpath . '.zst';
    $command = '/bin/zstd -20 -T0 --force --keep ' . escapeshellarg($mbzpath) . ' 2>&1';
    exec($command, $output, $returncode);

    if ($returncode !== 0 || !is_file($zstpath)) {
        throw new moodle_exception('errorzstdcompression', 'local_versionamiento_de_aulas', '', implode("\n", $output));
    }

    return $zstpath;
}

/**
 * Devuelve el archivo de respaldo listo para extraer (MBZ), descomprimiendo ZST si corresponde.
 *
 * @param string $archivepath Ruta absoluta del archivo fuente (.mbz o .zst).
 * @return string Ruta absoluta del archivo .mbz listo para restaurar.
 * @throws moodle_exception Si falla la descompresión.
 */
function local_versionamiento_de_aulas_prepare_backup_archive(string $archivepath): string {
    if (!is_file($archivepath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo de respaldo no encontrado.');
    }

    if (!local_versionamiento_de_aulas_is_zst_filename($archivepath)) {
        return $archivepath;
    }

    $mbzpath = preg_replace('/\.zst$/i', '', $archivepath);
    if (empty($mbzpath)) {
        throw new moodle_exception('errorzstddecompression', 'local_versionamiento_de_aulas');
    }

    $command = '/bin/zstd -d --force -o ' . escapeshellarg($mbzpath) . ' ' . escapeshellarg($archivepath) . ' 2>&1';
    exec($command, $output, $returncode);

    if ($returncode !== 0 || !is_file($mbzpath)) {
        throw new moodle_exception('errorzstddecompression', 'local_versionamiento_de_aulas', '', implode("\n", $output));
    }

    return $mbzpath;
}
