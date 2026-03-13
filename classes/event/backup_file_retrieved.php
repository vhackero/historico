<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class backup_file_retrieved extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'r';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'files';
    }

    public static function get_name() {
        return get_string('eventbackupfileretrieved', 'local_versionamiento_de_aulas');
    }

    public function get_description() {
        return "Se obtuvo un archivo de respaldo para restauración en el curso con id '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/local/versionamiento_de_aulas/index.php', ['id' => $this->courseid]);
    }
}
