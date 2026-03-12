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


/**
 * Copia un respaldo .zst al repositorio configurado (local o remoto por SSH/SCP).
 *
 * @param string $zstpath Ruta local absoluta del archivo .zst.
 * @throws moodle_exception Si falla la conexión/copia al repositorio.
 */
function local_versionamiento_de_aulas_copy_to_repository(string $zstpath): void {
    if (!is_file($zstpath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo ZST no encontrado para copiar al repositorio.');
    }

    $host = trim((string)get_config('local_versionamiento_de_aulas', 'repository_host'));
    $targetdir = trim((string)get_config('local_versionamiento_de_aulas', 'repository_path'));
    $username = trim((string)get_config('local_versionamiento_de_aulas', 'repository_user'));
    $port = (int)get_config('local_versionamiento_de_aulas', 'repository_port');
    $authmethod = trim((string)get_config('local_versionamiento_de_aulas', 'repository_auth_method')) ?: 'password';
    $password = (string)get_config('local_versionamiento_de_aulas', 'repository_password');
    $privatekey = trim((string)get_config('local_versionamiento_de_aulas', 'repository_private_key'));

    if (empty($host)) {
        throw new moodle_exception('invalidrepositoryhost', 'local_versionamiento_de_aulas');
    }
    if (empty($targetdir)) {
        throw new moodle_exception('invalidrepositorypath', 'local_versionamiento_de_aulas');
    }
    if ($port <= 0) {
        $port = 22;
    }

    if (!in_array($authmethod, ['password', 'key'], true)) {
        $authmethod = 'password';
    }

    $localhosts = ['localhost', '127.0.0.1', '::1'];
    $islocal = in_array(strtolower($host), $localhosts, true);

    if (!$islocal && empty($username)) {
        throw new moodle_exception('invalidrepositoryuser', 'local_versionamiento_de_aulas');
    }

    if ($islocal) {
        if (substr($targetdir, -1) !== '/' && substr($targetdir, -1) !== DIRECTORY_SEPARATOR) {
            $targetdir .= DIRECTORY_SEPARATOR;
        }
        if (!is_dir($targetdir) && !mkdir($targetdir, 0770, true)) {
            throw new moodle_exception('invalidrepositorypath', 'local_versionamiento_de_aulas', '', $targetdir);
        }
        if (!is_writable($targetdir)) {
            throw new moodle_exception('invalidrepositorypath', 'local_versionamiento_de_aulas', '', $targetdir);
        }
        $destination = $targetdir . basename($zstpath);
        if (!copy($zstpath, $destination)) {
            throw new moodle_exception('errorrepositorycopy', 'local_versionamiento_de_aulas', '', $destination);
        }
        return;
    }

    $sshbin = '/usr/bin/ssh';
    $scpbin = '/usr/bin/scp';
    if (!is_executable($sshbin) || !is_executable($scpbin)) {
        throw new moodle_exception('errorrepositorytransport', 'local_versionamiento_de_aulas', '', 'ssh/scp');
    }

    $sshauthopts = '';
    $scpauthopts = '';
    $usepass = false;

    if ($authmethod === 'key') {
        if (empty($privatekey) || !is_readable($privatekey)) {
            throw new moodle_exception('invalidrepositorykey', 'local_versionamiento_de_aulas', '', $privatekey);
        }
        $keyopt = '-i ' . escapeshellarg($privatekey);
        $sshauthopts = $keyopt;
        $scpauthopts = $keyopt;
    } else {
        if (empty($password)) {
            throw new moodle_exception('invalidrepositorypassword', 'local_versionamiento_de_aulas');
        }
        if (!is_executable('/usr/bin/sshpass')) {
            throw new moodle_exception('errorrepositorytransport', 'local_versionamiento_de_aulas', '', 'sshpass');
        }
        putenv('SSHPASS=' . $password);
        $usepass = true;
    }

    $usertarget = escapeshellarg($username . '@' . $host);
    $remotecommand = 'mkdir -p ' . escapeshellarg($targetdir);

    $mkdircmd = ($usepass ? '/usr/bin/sshpass -e ' : '') .
        $sshbin . ' ' . $sshauthopts .
        ' -p ' . (int)$port .
        ' -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null ' .
        $usertarget . ' ' . escapeshellarg($remotecommand) . ' 2>&1';

    exec($mkdircmd, $mkdirout, $mkdircode);
    if ($mkdircode !== 0) {
        if ($usepass) {
            putenv('SSHPASS');
        }
        throw new moodle_exception('errorrepositoryconnect', 'local_versionamiento_de_aulas', '', implode("\n", $mkdirout));
    }

    $remote = escapeshellarg($username . '@' . $host . ':' . rtrim($targetdir, '/'). '/');
    $scpcmd = ($usepass ? '/usr/bin/sshpass -e ' : '') .
        $scpbin . ' ' . $scpauthopts .
        ' -P ' . (int)$port .
        ' -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null ' .
        escapeshellarg($zstpath) . ' ' . $remote . ' 2>&1';

    exec($scpcmd, $scout, $sccode);
    if ($usepass) {
        putenv('SSHPASS');
    }

    if ($sccode !== 0) {
        throw new moodle_exception('errorrepositorycopy', 'local_versionamiento_de_aulas', '', implode("\n", $scout));
    }
}
