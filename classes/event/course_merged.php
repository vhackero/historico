<?php
namespace local_versionamiento_de_aulas\event;

defined('MOODLE_INTERNAL') || die();

class course_merged extends \core\event\base {
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'course';
    }

    public static function get_name() {
        return get_string('eventcoursemerged', 'local_versionamiento_de_aulas');
    }

    public function get_description() {
        return "El usuario con id '{$this->userid}' fusionó contenido en el curso con id '{$this->courseid}'.";
    }

    public function get_url() {
        return new \moodle_url('/course/view.php', ['id' => $this->courseid]);
    }
}
