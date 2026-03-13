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
 * Busca la ruta de un binario del sistema.
 *
 * @param array $candidates
 * @return string
 * @throws moodle_exception
 */
function local_versionamiento_de_aulas_find_system_binary(array $candidates): string {
    foreach ($candidates as $candidate) {
        if (!empty($candidate) && is_executable($candidate)) {
            return $candidate;
        }
    }

    foreach ($candidates as $candidate) {
        if (empty($candidate)) {
            continue;
        }
        $name = basename($candidate);
        $resolved = trim((string)shell_exec('command -v ' . escapeshellarg($name) . ' 2>/dev/null'));
        if (!empty($resolved) && is_executable($resolved)) {
            return $resolved;
        }
    }

    throw new moodle_exception('errorrepositorytransport', 'local_versionamiento_de_aulas', '', implode(', ', $candidates));
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
    $targetdir = rtrim($targetdir, '/');
    if ($targetdir === '') {
        $targetdir = '/';
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

    $sshbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/ssh', '/bin/ssh']);
    $scpbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/scp', '/bin/scp']);

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
        $sshpassbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/sshpass', '/bin/sshpass']);
        putenv('SSHPASS=' . $password);
        $usepass = true;
    }

    $usertarget = escapeshellarg($username . '@' . $host);
    $remotecommand = 'mkdir -p ' . escapeshellarg($targetdir);
    $sshopts = '-o ConnectTimeout=15 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o GlobalKnownHostsFile=/dev/null -o UpdateHostKeys=no -o LogLevel=ERROR -F /dev/null';

    // Evita que ssh/scp intenten crear ~/.ssh en cuentas de servicio sin HOME escribible (p.ej. apache/httpd).
    $originalhome = getenv('HOME');
    $temphome = make_request_directory('local_versionamiento_de_aulas_ssh_home');
    putenv('HOME=' . $temphome);

    $mkdircmd = ($usepass ? ($sshpassbin . ' -e ') : '') .
        $sshbin . ' ' . $sshauthopts .
        ' -p ' . (int)$port .
        ' ' . $sshopts . ' ' .
        $usertarget . ' ' . escapeshellarg($remotecommand) . ' 2>&1';

    exec($mkdircmd, $mkdirout, $mkdircode);
    if ($mkdircode !== 0) {
        if ($usepass) {
            putenv('SSHPASS');
        }
        if ($originalhome !== false) {
            putenv('HOME=' . $originalhome);
        } else {
            putenv('HOME');
        }
        throw new moodle_exception('errorrepositoryconnect', 'local_versionamiento_de_aulas', '', implode("\n", $mkdirout));
    }

    $remote = escapeshellarg($username . '@' . $host . ':' . $targetdir . '/');
    $scpcmd = ($usepass ? ($sshpassbin . ' -e ') : '') .
        $scpbin . ' ' . $scpauthopts .
        ' -P ' . (int)$port .
        ' ' . $sshopts . ' ' .
        escapeshellarg($zstpath) . ' ' . $remote . ' 2>&1';

    exec($scpcmd, $scout, $sccode);
    if ($usepass) {
        putenv('SSHPASS');
    }

    if ($originalhome !== false) {
        putenv('HOME=' . $originalhome);
    } else {
        putenv('HOME');
    }

    if ($sccode !== 0) {
        $rawerror = implode("\n", $scout);

        // Fallback automático: si no hay permisos en la ruta configurada, intentar en HOME remoto.
        if (stripos($rawerror, 'Permission denied') !== false) {
            $fallbackdir = '~/versionamiento_backups';
            $fallbackmkdir = ($usepass ? ($sshpassbin . ' -e ') : '') .
                $sshbin . ' ' . $sshauthopts .
                ' -p ' . (int)$port .
                ' ' . $sshopts . ' ' .
                $usertarget . ' ' . escapeshellarg('mkdir -p ' . $fallbackdir) . ' 2>&1';

            exec($fallbackmkdir, $fallbackmkdirout, $fallbackmkdircode);

            if ($fallbackmkdircode === 0) {
                $fallbackremote = escapeshellarg($username . '@' . $host . ':' . $fallbackdir . '/');
                $fallbackscpcmd = ($usepass ? ($sshpassbin . ' -e ') : '') .
                    $scpbin . ' ' . $scpauthopts .
                    ' -P ' . (int)$port .
                    ' ' . $sshopts . ' ' .
                    escapeshellarg($zstpath) . ' ' . $fallbackremote . ' 2>&1';

                exec($fallbackscpcmd, $fallbackscout, $fallbacksccode);
                if ($fallbacksccode === 0) {
                    return;
                }

                $rawerror .= "\nFallback HOME remoto falló: " . implode("\n", $fallbackscout);
            } else {
                $rawerror .= "\nFallback HOME remoto falló al crear carpeta: " . implode("\n", $fallbackmkdirout);
            }
        }

        throw new moodle_exception('errorrepositorycopy', 'local_versionamiento_de_aulas', '', $rawerror);
    }
}


/**
 * Copia un respaldo .zst a la ruta local configurada.
 *
 * @param string $zstpath Ruta local absoluta del archivo .zst.
 * @throws moodle_exception Si falla la copia local.
 */
function local_versionamiento_de_aulas_copy_to_local_repository(string $zstpath): void {
    if (!is_file($zstpath)) {
        throw new moodle_exception('invalidparameter', 'error', '', 'Archivo ZST no encontrado para copiar al repositorio local.');
    }

    $localpath = trim((string)get_config('local_versionamiento_de_aulas', 'local_repository_path'));
    if (empty($localpath)) {
        throw new moodle_exception('invalidlocalrepositorypath', 'local_versionamiento_de_aulas');
    }

    if (substr($localpath, -1) !== '/' && substr($localpath, -1) !== DIRECTORY_SEPARATOR) {
        $localpath .= DIRECTORY_SEPARATOR;
    }

    if (!is_dir($localpath) && !mkdir($localpath, 0770, true)) {
        throw new moodle_exception('invalidlocalrepositorypath', 'local_versionamiento_de_aulas', '', $localpath);
    }

    if (!is_writable($localpath)) {
        throw new moodle_exception('invalidlocalrepositorypath', 'local_versionamiento_de_aulas', '', $localpath);
    }

    $destination = $localpath . basename($zstpath);
    if (!copy($zstpath, $destination)) {
        throw new moodle_exception('errorlocalrepositorycopy', 'local_versionamiento_de_aulas', '', $destination);
    }
}


/**
 * Elimina un respaldo por nombre desde repositorios configurados (local/remoto).
 *
 * @param string $filename Nombre del archivo (normalmente .zst o .mbz).
 */
function local_versionamiento_de_aulas_delete_from_repositories(string $filename): void {
    if (empty($filename)) {
        return;
    }

    $variants = [$filename];
    if (preg_match('/\.zst$/i', $filename)) {
        $variants[] = preg_replace('/\.zst$/i', '', $filename);
    } else if (preg_match('/\.mbz$/i', $filename)) {
        $variants[] = $filename . '.zst';
    }
    $variants = array_values(array_unique(array_filter($variants)));

    // Borrado local.
    $localpath = trim((string)get_config('local_versionamiento_de_aulas', 'local_repository_path'));
    if (!empty($localpath) && is_dir($localpath)) {
        foreach ($variants as $name) {
            $localfile = rtrim($localpath, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($localfile)) {
                @unlink($localfile);
            }
        }
    }

    $host = trim((string)get_config('local_versionamiento_de_aulas', 'repository_host'));
    $targetdir = trim((string)get_config('local_versionamiento_de_aulas', 'repository_path'));
    $username = trim((string)get_config('local_versionamiento_de_aulas', 'repository_user'));
    $port = (int)get_config('local_versionamiento_de_aulas', 'repository_port');
    $authmethod = trim((string)get_config('local_versionamiento_de_aulas', 'repository_auth_method')) ?: 'password';
    $password = (string)get_config('local_versionamiento_de_aulas', 'repository_password');
    $privatekey = trim((string)get_config('local_versionamiento_de_aulas', 'repository_private_key'));

    if (empty($host) || empty($targetdir)) {
        return;
    }

    $localhosts = ['localhost', '127.0.0.1', '::1'];
    if (in_array(strtolower($host), $localhosts, true)) {
        foreach ($variants as $name) {
            $file = rtrim($targetdir, '/\\') . DIRECTORY_SEPARATOR . $name;
            if (is_file($file)) {
                @unlink($file);
            }
        }
        return;
    }

    if (empty($username)) {
        return;
    }
    if ($port <= 0) {
        $port = 22;
    }

    try {
        $sshbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/ssh', '/bin/ssh']);
    } catch (\Exception $e) {
        return;
    }

    $sshauthopts = '';
    $usepass = false;
    if ($authmethod === 'key') {
        if (empty($privatekey) || !is_readable($privatekey)) {
            return;
        }
        $sshauthopts = '-i ' . escapeshellarg($privatekey);
    } else {
        if (empty($password)) {
            return;
        }
        try {
            $sshpassbin = local_versionamiento_de_aulas_find_system_binary(['/usr/bin/sshpass', '/bin/sshpass']);
        } catch (\Exception $e) {
            return;
        }
        putenv('SSHPASS=' . $password);
        $usepass = true;
    }

    $sshopts = '-o ConnectTimeout=15 -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o GlobalKnownHostsFile=/dev/null -o UpdateHostKeys=no -o LogLevel=ERROR -F /dev/null';
    $usertarget = escapeshellarg($username . '@' . $host);

    $paths = [];
    foreach ($variants as $name) {
        $paths[] = rtrim($targetdir, '/') . '/' . $name;
        $paths[] = '~/versionamiento_backups/' . $name;
    }
    $paths = array_unique($paths);

    $quoted = array_map('escapeshellarg', $paths);
    $rmcmd = 'rm -f ' . implode(' ', $quoted);

    $originalhome = getenv('HOME');
    $temphome = make_request_directory('local_versionamiento_de_aulas_ssh_home_delete');
    putenv('HOME=' . $temphome);

    $command = ($usepass ? ($sshpassbin . ' -e ') : '') .
        $sshbin . ' ' . $sshauthopts .
        ' -p ' . (int)$port .
        ' ' . $sshopts . ' ' .
        $usertarget . ' ' . escapeshellarg($rmcmd) . ' 2>&1';

    exec($command, $output, $returncode);

    if ($usepass) {
        putenv('SSHPASS');
    }
    if ($originalhome !== false) {
        putenv('HOME=' . $originalhome);
    } else {
        putenv('HOME');
    }
}
