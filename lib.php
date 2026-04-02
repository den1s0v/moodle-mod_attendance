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
 * Library of functions and constants for module attendance
 *
 * @package   mod_attendance
 * @copyright  2011 Artem Andreev <andreev.artem@gmail.com>
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once(dirname(__FILE__) . '/classes/calendar_helpers.php');

/**
 * Returns the information if the module supports a feature
 *
 * @see plugin_supports() in lib/moodlelib.php
 * @param string $feature FEATURE_xx constant for requested feature
 * @return mixed true if the feature is supported, null if unknown
 */
function attendance_supports($feature) {
    switch ($feature) {
        case FEATURE_GRADE_HAS_GRADE:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        // Artem Andreev: AFAIK it's not tested.
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ADMINISTRATION;
        default:
            return null;
    }
}

/**
 * Collects attendance tooltip data.
 *
 * @param int $attendanceid
 * @param int[]|null $allowedgroupids If null, includes all groups. If array, only these group ids are included.
 * @return array
 */
function attendance_get_tooltip_data(int $attendanceid, ?array $allowedgroupids = null): array {
    global $DB;

    $groupfilter = '';
    $params = ['attendanceid' => $attendanceid];
    if ($allowedgroupids !== null) {
        $allowedgroupids = array_values(array_unique(array_map('intval', $allowedgroupids)));
        if (empty($allowedgroupids)) {
            $groupfilter = ' AND 1 = 0';
        } else {
            [$insql, $inparams] = $DB->get_in_or_equal($allowedgroupids, SQL_PARAMS_NAMED, 'gid');
            $groupfilter = " AND s.groupid $insql";
            $params = array_merge($params, $inparams);
        }
    }

    // Load sessions for groups that still exist.
    $sessions = $DB->get_recordset_sql(
        "SELECT s.id, s.sessdate, s.groupid, s.createdby, g.name AS groupname
           FROM {attendance_sessions} s
           JOIN {groups} g ON g.id = s.groupid
          WHERE s.attendanceid = :attendanceid AND s.groupid > 0 $groupfilter
          ORDER BY s.sessdate ASC",
        $params
    );

    $sessionrows = [];
    $creatorids = [];
    foreach ($sessions as $sess) {
        $sessionrows[] = $sess;
        if (!empty($sess->createdby)) {
            $creatorids[$sess->createdby] = true;
        }
    }
    $sessions->close();

    $attendancename = '';
    $creatorlabel = '';
    if ($attendance = $DB->get_record('attendance', ['id' => $attendanceid], 'id,name,created_by')) {
        $attendancename = $attendance->name;
        if (!empty($attendance->created_by) && get_config('attendance', 'showcreatorintooltip')) {
            if ($creator = $DB->get_record('user', ['id' => $attendance->created_by])) {
                $creatorname = fullname($creator);
                $creatorlabel = get_string('createdbyattendance', 'attendance', $creatorname);
            }
        }
    }

    // Preload session creators.
    $creatorusers = [];
    if (!empty($creatorids)) {
        $creatorusers = $DB->get_records_list('user', 'id', array_keys($creatorids));
    }

    // Group sessions by start time so simultaneous groups share one entry.
    $sessionsbytime = [];
    $sessioncreatorsbytime = [];
    foreach ($sessionrows as $sess) {
        $time = (int)$sess->sessdate;
        $groupid = (int)$sess->groupid;
        if ($groupid <= 0 || $sess->groupname === null || $sess->groupname === '') {
            continue;
        }
        if (!isset($sessionsbytime[$time])) {
            $sessionsbytime[$time] = [];
        }
        $sessionsbytime[$time][$groupid] = $sess->groupname;

        // Store creator name per time slot (first non-empty wins).
        if (!isset($sessioncreatorsbytime[$time]) && !empty($sess->createdby) && isset($creatorusers[$sess->createdby])) {
            $sessioncreatorsbytime[$time] = fullname($creatorusers[$sess->createdby]);
        }
    }

    return [
        'attendancename' => $attendancename,
        'creatorlabel' => $creatorlabel,
        'sessionsbytime' => $sessionsbytime,
        'sessioncreatorsbytime' => $sessioncreatorsbytime,
    ];
}

/**
 * Builds attendance tooltip HTML for course module listing.
 *
 * @param string $attendancename
 * @param string $creatorlabel
 * @param array $sessionsbytime
 * @param array $sessioncreatorsbytime
 * @return string
 */
function attendance_build_tooltip_html(string $attendancename, string $creatorlabel, array $sessionsbytime, array $sessioncreatorsbytime): string {
    // Build formatted blocks with past/future styling.
    // Number of groups per line (configurable, can be moved to plugin settings later).
    $groupsperline = 5;
    $blocks = [];
    $showsessioncreator = get_config('attendance', 'showsessioncreatorintooltip');
    if (!empty($sessionsbytime)) {
        ksort($sessionsbytime, SORT_NUMERIC);
        $now = time();
        foreach ($sessionsbytime as $time => $namesbyid) {
            $names = array_values($namesbyid);
            sort($names, SORT_NATURAL | SORT_FLAG_CASE);
            $datetime = userdate($time, '📅 %d.%m.%Y   🕙 %H:%M');
            $class = ($time < $now) ? 'attendance-session-past' : 'attendance-session-future';

            $teachername = $sessioncreatorsbytime[$time] ?? '';

            // Split groups into chunks of $groupsperline.
            $groupchunks = array_chunk($names, $groupsperline);
            $firstchunk = true;
            foreach ($groupchunks as $chunk) {
                $groupsstr = implode(', ', array_map('s', $chunk));
                if ($firstchunk) {
                    // First line: date/time [teacher] — groups.
                    $teacherpart = '';
                    if ($showsessioncreator && $teachername !== '') {
                        $teacherpart = ' &nbsp;&nbsp; ' . s($teachername) . ' ';
                    }
                    $blocks[] = '<span class="attendance-session-block ' . $class . '">' .
                        s($datetime) . $teacherpart . ' &nbsp;&nbsp; — &nbsp;&nbsp; 👥 ' . $groupsstr . '</span>';
                    $firstchunk = false;
                } else {
                    // Subsequent lines: indented to align after the dash.
                    $blocks[] = '<span class="attendance-session-block ' . $class . ' attendance-session-continuation">' .
                        '👥 ' . $groupsstr . '</span>';
                }
            }
        }
    }

    // Always show tooltip, even if no sessions.
    $tooltipcontent = '';
    if (empty($blocks)) {
        $tooltipcontent = '<span class="attendance-session-empty">' .
            get_string('nosessions', 'attendance') . '</span>';
    } else {
        $tooltipcontent = implode('<br>', $blocks);
    }

    $creatorhtml = '';
    if ($creatorlabel !== '' && get_config('attendance', 'showcreatorintooltip')) {
        $creatorhtml = '<span class="attendance-session-creator">' . s($creatorlabel) . '</span><br>';
    }

    $tooltiphtml = '<span class="attendance-session-tooltip" role="tooltip">' .
        '<span class="attendance-session-title">' . s($attendancename) . '</span><br>' .
        $creatorhtml .
        $tooltipcontent .
        '</span>';

    // Minimal container - tooltip triggered by link hover via CSS.
    return '<span class="attendance-session-summary">' . $tooltiphtml . '</span>';
}

/**
 * Adds compact group session info to the course module listing.
 *
 * This prepares a short, single-block summary for the course page without
 * expanding the activity height. It lists up to two grouped session entries
 * inline (YYYY.DD.MM HH:MM - Group1, Group2), sorted from past to future and
 * grouped by identical start time. When there are more than two entries, the
 * inline text shows "..." and the full list is available in a CSS-only tooltip.
 * Past and future entries are marked with CSS classes for visual distinction.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|null
 */
function attendance_get_coursemodule_info($coursemodule) {
    $tooltipdata = attendance_get_tooltip_data((int)$coursemodule->instance);
    $html = attendance_build_tooltip_html(
        $tooltipdata['attendancename'],
        $tooltipdata['creatorlabel'],
        $tooltipdata['sessionsbytime'],
        $tooltipdata['sessioncreatorsbytime']
    );

    $info = new cached_cm_info();
    $info->content = $html;

    return $info;
}

/**
 * Adjusts cached course-module content per user.
 *
 * Students in separate groups mode only see their own groups in the tooltip.
 * In no groups / visible groups mode everyone sees all groups.
 *
 * @param cm_info $cm
 */
function attendance_cm_info_view(cm_info $cm) {
    global $USER;

    if (!$cm->uservisible) {
        return;
    }

    $studentfilteringenabled = get_config('attendance', 'enablestudentgroupfilterintooltip');
    // get_config() returns false when the setting is missing (for example right after deploy,
    // before admin settings are saved). Keep default behavior as enabled in that case.
    if ($studentfilteringenabled === false) {
        $studentfilteringenabled = 1;
    }
    if (empty($studentfilteringenabled)) {
        return;
    }

    $groupmode = groups_get_activity_groupmode($cm, $cm->get_course());
    if ($groupmode !== SEPARATEGROUPS) {
        return;
    }

    // Teachers (and roles with takeattendances capability) should see all groups,
    // so no per-student filtering is applied for them.
    if (has_capability('mod/attendance:takeattendances', $cm->context, $USER)) {
        return;
    }

    $usergroupsbygrouping = groups_get_user_groups($cm->course, $USER->id);
    $allowedgroupids = [];
    foreach ($usergroupsbygrouping as $groupids) {
        foreach ($groupids as $groupid) {
            $allowedgroupids[] = (int)$groupid;
        }
    }
    $allowedgroupids = array_values(array_unique($allowedgroupids));

    $tooltipdata = attendance_get_tooltip_data((int)$cm->instance, $allowedgroupids);
    $cm->set_content(attendance_build_tooltip_html(
        $tooltipdata['attendancename'],
        $tooltipdata['creatorlabel'],
        $tooltipdata['sessionsbytime'],
        $tooltipdata['sessioncreatorsbytime']
    ));
}

/**
 * Add default set of statuses to the new attendance.
 *
 * @param int $attid - id of attendance instance.
 */
function att_add_default_statuses($attid) {
    global $DB;

    $statuses = $DB->get_recordset('attendance_statuses', ['attendanceid' => 0], 'id');
    foreach ($statuses as $st) {
        $rec = $st;
        $rec->attendanceid = $attid;
        $DB->insert_record('attendance_statuses', $rec);
    }
    $statuses->close();
}

/**
 * Add default set of warnings to the new attendance.
 *
 * @param int $id - id of attendance instance.
 */
function attendance_add_default_warnings($id) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/attendance/locallib.php');

    $warnings = $DB->get_recordset(
        'attendance_warning',
        ['idnumber' => 0],
        'id'
    );
    foreach ($warnings as $n) {
        $rec = $n;
        $rec->idnumber = $id;
        $DB->insert_record('attendance_warning', $rec);
    }
    $warnings->close();
}

/**
 * Add new attendance instance.
 *
 * @param stdClass $attendance
 * @return bool|int
 */
function attendance_add_instance($attendance) {
    global $DB, $USER;

    $attendance->timemodified = time();

    // Default grade (similar to what db fields defaults if no grade attribute is passed),
    // but we need it in object for grading update.
    if (!isset($attendance->grade)) {
        $attendance->grade = 100;
    }

    // Store the creator of this attendance instance for analytics.
    if (!isset($attendance->created_by) || empty($attendance->created_by)) {
        if (!empty($USER) && !empty($USER->id)) {
            $attendance->created_by = $USER->id;
        } else {
            $attendance->created_by = null;
        }
    }

    $attendance->id = $DB->insert_record('attendance', $attendance);

    att_add_default_statuses($attendance->id);

    attendance_add_default_warnings($attendance->id);

    attendance_grade_item_update($attendance);

    return $attendance->id;
}

/**
 * Update existing attendance instance.
 *
 * @param stdClass $attendance
 * @return bool
 */
function attendance_update_instance($attendance) {
    global $DB;

    $attendance->timemodified = time();
    $attendance->id = $attendance->instance;

    if (! $DB->update_record('attendance', $attendance)) {
        return false;
    }

    attendance_grade_item_update($attendance);

    return true;
}

/**
 * Delete existing attendance
 *
 * @param int $id
 * @return bool
 */
function attendance_delete_instance($id) {
    global $DB, $CFG;
    require_once($CFG->dirroot . '/mod/attendance/locallib.php');

    if (! $attendance = $DB->get_record('attendance', ['id' => $id])) {
        return false;
    }

    if ($sessids = array_keys($DB->get_records('attendance_sessions', ['attendanceid' => $id], '', 'id'))) {
        if (attendance_existing_calendar_events_ids($sessids)) {
            attendance_delete_calendar_events($sessids);
        }
        $DB->delete_records_list('attendance_log', 'sessionid', $sessids);
        $DB->delete_records('attendance_sessions', ['attendanceid' => $id]);
    }
    $DB->delete_records('attendance_statuses', ['attendanceid' => $id]);

    $DB->delete_records('attendance_warning', ['idnumber' => $id]);

    // Grades must be deleted before the main attendance record.
    attendance_grade_item_delete($attendance);

    $DB->delete_records('attendance', ['id' => $id]);

    return true;
}

/**
 * Called by course/reset.php
 * @param moodleform $mform form passed by reference
 */
function attendance_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'attendanceheader', get_string('modulename', 'attendance'));

    $mform->addElement(
        'static',
        'description',
        get_string('description', 'attendance'),
        get_string('resetdescription', 'attendance')
    );
    $mform->addElement('checkbox', 'reset_attendance_log', get_string('deletelogs', 'attendance'));

    $mform->addElement('checkbox', 'reset_attendance_sessions', get_string('deletesessions', 'attendance'));
    $mform->disabledIf('reset_attendance_sessions', 'reset_attendance_log', 'notchecked');

    $mform->addElement('checkbox', 'reset_attendance_statuses', get_string('resetstatuses', 'attendance'));
    $mform->setAdvanced('reset_attendance_statuses');
    $mform->disabledIf('reset_attendance_statuses', 'reset_attendance_log', 'notchecked');
}

/**
 * Course reset form defaults.
 *
 * @param stdClass $course
 * @return array
 */
function attendance_reset_course_form_defaults($course) {
    return ['reset_attendance_log' => 0, 'reset_attendance_statuses' => 0, 'reset_attendance_sessions' => 0];
}

/**
 * Reset user data within attendance.
 *
 * @param stdClass $data
 * @return array
 */
function attendance_reset_userdata($data) {
    global $DB;

    $status = [];

    $attids = array_keys($DB->get_records('attendance', ['course' => $data->courseid], '', 'id'));

    if (!empty($data->reset_attendance_log)) {
        $sess = $DB->get_records_list('attendance_sessions', 'attendanceid', $attids, '', 'id');
        if (!empty($sess)) {
            [$sql, $params] = $DB->get_in_or_equal(array_keys($sess));
            $DB->delete_records_select('attendance_log', "sessionid $sql", $params);
            [$sql, $params] = $DB->get_in_or_equal($attids);
            $DB->set_field_select('attendance_sessions', 'lasttaken', 0, "attendanceid $sql", $params);
            if (empty($data->reset_attendance_sessions)) {
                // If sessions are being retained, clear automarkcompleted value.
                $DB->set_field_select('attendance_sessions', 'automarkcompleted', 0, "attendanceid $sql", $params);
            }

            $status[] = [
                'component' => get_string('modulenameplural', 'attendance'),
                'item' => get_string('attendancedata', 'attendance'),
                'error' => false,
            ];
        }
    }

    if (!empty($data->reset_attendance_statuses)) {
        $DB->delete_records_list('attendance_statuses', 'attendanceid', $attids);
        foreach ($attids as $attid) {
            att_add_default_statuses($attid);
        }

        $status[] = [
            'component' => get_string('modulenameplural', 'attendance'),
            'item' => get_string('sessions', 'attendance'),
            'error' => false,
        ];
    }

    if (!empty($data->reset_attendance_sessions)) {
        $sessionsids = array_keys($DB->get_records_list('attendance_sessions', 'attendanceid', $attids, '', 'id'));
        if (attendance_existing_calendar_events_ids($sessionsids)) {
            attendance_delete_calendar_events($sessionsids);
        }
        $DB->delete_records_list('attendance_sessions', 'attendanceid', $attids);

        $status[] = [
            'component' => get_string('modulenameplural', 'attendance'),
            'item' => get_string('statuses', 'attendance'),
            'error' => false,
        ];
    }

    return $status;
}
/**
 * Return a small object with summary information about what a
 *  user has done with a given particular instance of this module
 *  Used for user activity reports.
 *  $return->time = the time they did it
 *  $return->info = a short text description
 *
 * @param stdClass $course - full course record.
 * @param stdClass $user - full user record
 * @param stdClass $mod
 * @param stdClass $attendance
 * @return stdClass.
 */
function attendance_user_outline($course, $user, $mod, $attendance) {
    global $CFG;
    require_once(dirname(__FILE__) . '/locallib.php');
    require_once($CFG->libdir . '/gradelib.php');

    $grades = grade_get_grades($course->id, 'mod', 'attendance', $attendance->id, $user->id);

    $result = new stdClass();
    if (!empty($grades->items[0]->grades)) {
        $grade = reset($grades->items[0]->grades);
        $result->time = $grade->dategraded;
    } else {
        $result->time = 0;
    }
    if (has_capability('mod/attendance:canbelisted', $mod->context, $user->id)) {
        $summary = new mod_attendance_summary($attendance->id, $user->id);
        $usersummary = $summary->get_all_sessions_summary_for($user->id);

        $result->info = $usersummary->pointsallsessions;
    }

    return $result;
}
/**
 * Print a detailed representation of what a  user has done with
 * a given particular instance of this module, for user activity reports.
 *
 * @param stdClass $course
 * @param stdClass $user
 * @param stdClass $mod
 * @param stdClass $attendance
 */
function attendance_user_complete($course, $user, $mod, $attendance) {
    global $CFG;

    require_once(dirname(__FILE__) . '/renderhelpers.php');
    require_once($CFG->libdir . '/gradelib.php');

    if (has_capability('mod/attendance:canbelisted', $mod->context, $user->id)) {
        echo construct_full_user_stat_html_table($attendance, $user);
    }
}

/**
 * Dummy function - must exist to allow quick editing of module name.
 *
 * @param stdClass $attendance
 * @param int $userid
 * @param bool $nullifnone
 */
function attendance_update_grades($attendance, $userid = 0, $nullifnone = true) {
    // We need this function to exist so that quick editing of module name is passed to gradebook.
}
/**
 * Create grade item for given attendance
 *
 * @param stdClass $attendance object with extra cmidnumber
 * @param mixed $grades optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function attendance_grade_item_update($attendance, $grades = null) {
    global $CFG, $DB;

    require_once('locallib.php');

    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir . '/gradelib.php');
    }

    if (!isset($attendance->courseid)) {
        $attendance->courseid = $attendance->course;
    }

    if (!empty($attendance->cmidnumber)) {
        $params = ['itemname' => $attendance->name, 'idnumber' => $attendance->cmidnumber];
    } else {
        // MDL-14303.
        $params = ['itemname' => $attendance->name];
    }

    if ($attendance->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax']  = $attendance->grade;
        $params['grademin']  = 0;
    } else if ($attendance->grade < 0) {
        $params['gradetype'] = GRADE_TYPE_SCALE;
        $params['scaleid']   = -$attendance->grade;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/attendance', $attendance->courseid, 'mod', 'attendance', $attendance->id, 0, $grades, $params);
}

/**
 * Delete grade item for given attendance
 *
 * @param object $attendance object
 * @return object attendance
 */
function attendance_grade_item_delete($attendance) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    if (!isset($attendance->courseid)) {
        $attendance->courseid = $attendance->course;
    }

    return grade_update(
        'mod/attendance',
        $attendance->courseid,
        'mod',
        'attendance',
        $attendance->id,
        0,
        null,
        ['deleted' => 1]
    );
}

/**
 * This function returns if a scale is being used by one attendance
 * it it has support for grading and scales. Commented code should be
 * modified if necessary. See book, glossary or journal modules
 * as reference.
 *
 * @param int $attendanceid
 * @param int $scaleid
 * @return boolean True if the scale is used by any attendance
 */
function attendance_scale_used($attendanceid, $scaleid) {
    return false;
}

/**
 * Checks if scale is being used by any instance of attendance
 *
 * This is used to find out if scale used anywhere
 *
 * @param int $scaleid
 * @return bool true if the scale is used by any book
 */
function attendance_scale_used_anywhere($scaleid) {
    return false;
}

/**
 * Serves the attendance sessions descriptions files.
 *
 * @param object $course
 * @param object $cm
 * @param object $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @return bool false if file not found, does not return if found - justsend the file
 */
function attendance_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload) {
    global $DB;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_login($course, false, $cm);

    if (!$DB->record_exists('attendance', ['id' => $cm->instance])) {
        return false;
    }

    // Session area is served by pluginfile.php.
    $fileareas = ['session'];
    if (!in_array($filearea, $fileareas)) {
        return false;
    }

    $sessid = (int)array_shift($args);
    if (!$DB->record_exists('attendance_sessions', ['id' => $sessid])) {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_attendance/$filearea/$sessid/$relativepath";
    if (!$file = $fs->get_file_by_hash(sha1($fullpath)) || $file->is_directory()) {
        return false;
    }
    send_stored_file($file, 0, 0, true);
}

/**
 * Print tabs on attendance settings page.
 *
 * @param string $selected - current selected tab.
 */
function attendance_print_settings_tabs($selected = 'settings') {
    global $CFG;
    // Print tabs for different settings pages.
    $tabs = [];
    $tabs[] = new tabobject(
        'settings',
        "{$CFG->wwwroot}/{$CFG->admin}/settings.php?section=modsettingattendance",
        get_string('settings', 'attendance'),
        get_string('settings'),
        false
    );

    $tabs[] = new tabobject(
        'defaultstatus',
        $CFG->wwwroot . '/mod/attendance/defaultstatus.php',
        get_string('defaultstatus', 'attendance'),
        get_string('defaultstatus', 'attendance'),
        false
    );

    if (get_config('attendance', 'enablewarnings')) {
        $tabs[] = new tabobject(
            'defaultwarnings',
            $CFG->wwwroot . '/mod/attendance/warnings.php',
            get_string('defaultwarnings', 'attendance'),
            get_string('defaultwarnings', 'attendance'),
            false
        );
    }

    $tabs[] = new tabobject(
        'customfields',
        $CFG->wwwroot . '/mod/attendance/customfields.php',
        get_string('customfields', 'attendance'),
        get_string('customfields', 'attendance'),
        false
    );

    $tabs[] = new tabobject(
        'coursesummary',
        $CFG->wwwroot . '/mod/attendance/coursesummary.php',
        get_string('coursesummary', 'attendance'),
        get_string('coursesummary', 'attendance'),
        false
    );

    if (get_config('attendance', 'enablewarnings')) {
        $tabs[] = new tabobject(
            'absentee',
            $CFG->wwwroot . '/mod/attendance/absentee.php',
            get_string('absenteereport', 'attendance'),
            get_string('absenteereport', 'attendance'),
            false
        );
    }

    $tabs[] = new tabobject(
        'resetcalendar',
        $CFG->wwwroot . '/mod/attendance/resetcalendar.php',
        get_string('resetcalendar', 'attendance'),
        get_string('resetcalendar', 'attendance'),
        false
    );

    $tabs[] = new tabobject(
        'importsessions',
        $CFG->wwwroot . '/mod/attendance/import/sessions.php',
        get_string('importsessions', 'attendance'),
        get_string('importsessions', 'attendance'),
        false
    );

    ob_start();
    print_tabs([$tabs], $selected);
    $tabmenu = ob_get_contents();
    ob_end_clean();

    return $tabmenu;
}

/**
 * Helper function to remove a user from the thirdpartyemails record of the attendance_warning table.
 *
 * @param array $warnings - list of warnings to parse.
 * @param int $userid - User id of user to remove.
 */
function attendance_remove_user_from_thirdpartyemails($warnings, $userid) {
    global $DB;

    // Update the third party emails list for all the relevant warnings.
    $updatedwarnings = array_map(
        function (stdClass $warning) use ($userid): stdClass {
            $warning->thirdpartyemails = implode(',', array_diff(explode(',', $warning->thirdpartyemails), [$userid]));
            return $warning;
        },
        array_filter(
            $warnings,
            function (stdClass $warning) use ($userid): bool {
                return in_array($userid, explode(',', $warning->thirdpartyemails));
            }
        )
    );

    // Sadly need to update each individually, no way to bulk update as all the thirdpartyemails field can be different.
    foreach ($updatedwarnings as $updatedwarning) {
        $DB->update_record('attendance_warning', $updatedwarning);
    }
}

/**
 * Add nodes to myprofile page.
 *
 * @param \core_user\output\myprofile\tree $tree Tree object
 * @param stdClass $user user object
 * @param bool $iscurrentuser
 * @param stdClass $course Course object
 *
 * @return bool
 */
function mod_attendance_myprofile_navigation(core_user\output\myprofile\tree $tree, $user, $iscurrentuser, $course) {
    if (empty($course)) {
        return;
    }
    $cms = get_all_instances_in_course('attendance', $course, $user->id);
    if (empty($cms)) {
        return;
    }
    $cm = reset($cms);
    if (!empty($cm->coursemodule) && has_capability('mod/attendance:viewreports', context_module::instance($cm->coursemodule))) {
        $url = new moodle_url('/mod/attendance/view.php', ['id' => $cm->coursemodule,
                                                           'mode' => mod_attendance_view_page_params::MODE_THIS_COURSE,
                                                           'studentid' => $user->id]);

        $node = new core_user\output\myprofile\node(
            'reports',
            'attendanceuserreport',
            get_string('attendanceuserreport', 'attendance'),
            null,
            $url
        );
        $tree->add_node($node);
    }
}

/**
 * Adds module specific settings to the settings block
 *
 * @param settings_navigation $settingsnav The settings navigation object
 * @param navigation_node $attendancenode The node to add module settings to
 */
function attendance_extend_settings_navigation(settings_navigation $settingsnav, navigation_node $attendancenode) {

    $context = $settingsnav->get_page()->cm->context;
    $cm = $settingsnav->get_page()->cm;
    $nodes = [];
    if (has_capability('mod/attendance:viewreports', $context)) {
        $nodes[] = ['url' => new moodle_url('/mod/attendance/report.php', ['id' => $cm->id]),
                    'title' => get_string('report', 'attendance'), ];
    }
    if (has_capability('mod/attendance:import', $context)) {
        $nodes[] = ['url' => new moodle_url('/mod/attendance/import.php', ['id' => $cm->id]),
                    'title' => get_string('import', 'attendance'), ];
    }
    if (has_capability('mod/attendance:export', $context)) {
        $nodes[] = ['url' => new moodle_url('/mod/attendance/export.php', ['id' => $cm->id]),
                    'title' => get_string('export', 'attendance'), ];
    }

    if (has_capability('mod/attendance:viewreports', $context) && get_config('attendance', 'enablewarnings')) {
        $nodes[] = ['url' => new moodle_url('/mod/attendance/absentee.php', ['id' => $cm->id]),
                    'title' => get_string('absenteereport', 'attendance'), ];
    }
    if (has_capability('mod/attendance:changepreferences', $context)) {
        $nodes[] = ['url' => new moodle_url('/mod/attendance/preferences.php', ['id' => $cm->id]),
                    'title' => get_string('statussetsettings', 'attendance'), ];
        if (get_config('attendance', 'enablewarnings')) {
            $nodes[] = ['url' => new moodle_url('/mod/attendance/warnings.php', ['id' => $cm->id]),
            'title' => get_string('warnings', 'attendance'), ];
        }
    }

    if (has_capability('mod/attendance:managetemporaryusers', context_module::instance($cm->id))) {
        $nodes[] = ['url' => new moodle_url('/mod/attendance/tempusers.php', ['id' => $cm->id]),
        'title' => get_string('tempusers', 'attendance'),
        'more' => true, ];
    }

    foreach ($nodes as $node) {
        $settingsnode = navigation_node::create(
            $node['title'],
            $node['url'],
            navigation_node::TYPE_SETTING
        );
        if (isset($settingsnode)) {
            if (!empty($node->more)) {
                $settingsnode->set_force_into_more_menu(true);
            }
            $attendancenode->add_node($settingsnode);
        }
    }
}
