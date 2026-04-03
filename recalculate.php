<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Manual recalculation helper for attendance grades.
 *
 * @package    mod_attendance
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/locallib.php');

$run = optional_param('run', 0, PARAM_BOOL);
$attendanceid = optional_param('attendanceid', 0, PARAM_INT);

require_login();

$systemcontext = context_system::instance();
if (!is_siteadmin()) {
    throw new required_capability_exception($systemcontext, 'moodle/site:config', 'nopermissions', '');
}

$baseurl = new moodle_url('/mod/attendance/recalculate.php', ['attendanceid' => $attendanceid]);
$PAGE->set_context($systemcontext);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('recalculategrades', 'attendance'));
$PAGE->set_heading(get_string('recalculategrades', 'attendance'));

/**
 * Returns potentially affected attendance/user pairs:
 * overlapping sessions in the same timeslot for the same student.
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @return array
 */
function mod_attendance_get_potential_recalc_pairs(moodle_database $DB, int $attendanceid = 0): array {
    $where = '';
    $params = [];
    if (!empty($attendanceid)) {
        $where = ' AND ats.attendanceid = :attendanceid';
        $params['attendanceid'] = $attendanceid;
    }

    $sql = "SELECT ats.attendanceid, atl.studentid
              FROM {attendance_sessions} ats
              JOIN {attendance_log} atl ON atl.sessionid = ats.id
             WHERE ats.lasttaken != 0
                   {$where}
          GROUP BY ats.attendanceid, atl.studentid, ats.sessdate, ats.duration
            HAVING COUNT(1) > 1";

    $records = $DB->get_records_sql($sql, $params);
    $pairs = [];
    foreach ($records as $record) {
        if (!isset($pairs[$record->attendanceid])) {
            $pairs[$record->attendanceid] = [];
        }
        $pairs[$record->attendanceid][$record->studentid] = true;
    }
    return $pairs;
}

$pairs = mod_attendance_get_potential_recalc_pairs($DB, $attendanceid);
$numattendance = count($pairs);
$numusers = 0;
foreach ($pairs as $users) {
    $numusers += count($users);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('recalculategrades', 'attendance'));

if ($run && confirm_sesskey()) {
    if (empty($pairs)) {
        echo $OUTPUT->notification(get_string('recalculategradesnothing', 'attendance'), \core\output\notification::NOTIFY_INFO);
    } else {
        $updatedactivities = 0;
        $updatedusers = 0;
        foreach ($pairs as $attid => $users) {
            $attendance = $DB->get_record('attendance', ['id' => $attid], 'id,grade', MUST_EXIST);
            if (empty($attendance->grade)) {
                continue;
            }
            $userids = array_map('intval', array_keys($users));
            attendance_update_users_grades_by_id((int)$attid, (int)$attendance->grade, $userids);
            $updatedactivities++;
            $updatedusers += count($userids);
        }
        $message = get_string('recalculategradesdone', 'attendance',
            (object)['activities' => $updatedactivities, 'users' => $updatedusers]);
        echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_SUCCESS);
    }
}

$summary = get_string('recalculategradessummary', 'attendance',
    (object)['activities' => $numattendance, 'users' => $numusers]);
echo html_writer::div($summary, 'alert alert-info');

$formurl = new moodle_url('/mod/attendance/recalculate.php', ['attendanceid' => $attendanceid, 'run' => 1]);
$button = new single_button($formurl, get_string('recalculategradesconfirm', 'attendance'), 'post');
$button->formid = 'attendance-recalculate-form';

if (empty($pairs)) {
    echo html_writer::div(get_string('recalculategradesnothing', 'attendance'), 'alert alert-warning');
} else {
    echo $OUTPUT->render($button);
}

echo $OUTPUT->footer();

