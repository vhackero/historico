<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_decompressed extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'local_ver_aulas_cola';
    }

    public static function get_name() {
        return get_string('eventbackupdecompressed', 'local_versionamiento_de_aulas');
    }

    public function get_description() {
        return "Se descomprimió un respaldo para restauración en el curso con id '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/index.php', ['id' => $this->courseid]);
    }
}
