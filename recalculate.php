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

$action = optional_param('action', 'preview', PARAM_ALPHA);
$attendanceid = optional_param('attendanceid', 0, PARAM_INT);
$era = optional_param('era', 'before_fix', PARAM_ALPHA);
$fixdate = optional_param('fixdate', date('Y-m-d'), PARAM_TEXT);
$eps = optional_param('eps', 0.00001, PARAM_FLOAT);

require_login();

$systemcontext = context_system::instance();
if (!is_siteadmin()) {
    throw new required_capability_exception($systemcontext, 'moodle/site:config', 'nopermissions', '');
}

$baseurl = new moodle_url('/mod/attendance/recalculate.php', [
    'attendanceid' => $attendanceid,
    'era' => $era,
    'fixdate' => $fixdate,
    'eps' => $eps,
]);
$PAGE->set_context($systemcontext);
$PAGE->set_url($baseurl);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('recalculategrades', 'attendance'));
$PAGE->set_heading(get_string('recalculategrades', 'attendance'));

/**
 * Build candidate list where rawgrade mismatches slot-policy expected rawgrade.
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @param string $era
 * @param int $fixts
 * @param float $eps
 * @return array<int, stdClass>
 */
function mod_attendance_get_raw_mismatch_candidates(
    moodle_database $DB,
    int $attendanceid,
    string $era,
    int $fixts,
    float $eps
): array {
    $where = '';
    $params = [];
    if (!empty($attendanceid)) {
        $where = ' AND ats.attendanceid = :attendanceid ';
        $params['attendanceid'] = $attendanceid;
    }

    $erawhere = '';
    if ($era === 'before_fix') {
        $erawhere = ' AND COALESCE(lw.last_grade_write_ts, 0) < :fixts ';
        $params['fixts'] = $fixts;
    } else if ($era === 'after_fix') {
        $erawhere = ' AND COALESCE(lw.last_grade_write_ts, 0) >= :fixts ';
        $params['fixts'] = $fixts;
    }

    $params['eps'] = $eps;

    $sql = "SELECT c.id AS courseid,
                   c.fullname AS coursename,
                   a.id AS attendanceid,
                   a.name AS attendancename,
                   u.id AS userid,
                   u.username,
                   u.firstname,
                   u.lastname,
                   gi.grademax,
                   gg.rawgrade,
                   gg.finalgrade,
                   pt.points_policy,
                   pt.maxpoints_policy,
                   (pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) AS pct_policy,
                   ((pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) * gi.grademax) AS raw_expected_policy,
                   (gg.rawgrade - ((pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) * gi.grademax)) AS delta_raw_vs_policy,
                   COALESCE(lw.last_grade_write_ts, 0) AS last_grade_write_ts
              FROM {grade_items} gi
              JOIN {grade_grades} gg ON gg.itemid = gi.id
              JOIN {attendance} a ON a.id = gi.iteminstance
              JOIN {course} c ON c.id = a.course
              JOIN {user} u ON u.id = gg.userid
              JOIN (
                    SELECT z.attendanceid,
                           z.userid,
                           SUM(z.slot_grade_policy) AS points_policy,
                           SUM(z.slot_maxgrade) AS maxpoints_policy,
                           MAX(CASE WHEN z.sessions_in_slot > 1 AND z.min_grade <> z.max_grade THEN 1 ELSE 0 END) AS has_conflict
                      FROM (
                            SELECT ats.attendanceid,
                                   atl.studentid AS userid,
                                   ats.sessdate,
                                   ats.duration,
                                   COUNT(DISTINCT ats.id) AS sessions_in_slot,
                                   MIN(stg.grade) AS min_grade,
                                   MAX(stg.grade) AS max_grade,
                                   CASE
                                     WHEN MIN(ats.autoassignstatus) = 0 AND MAX(ats.autoassignstatus) = 0
                                       THEN MIN(stg.grade)
                                     ELSE MAX(stg.grade)
                                   END AS slot_grade_policy,
                                   MAX(stm.maxgrade) AS slot_maxgrade
                              FROM {attendance_sessions} ats
                              JOIN {attendance_log} atl ON atl.sessionid = ats.id
                              JOIN {attendance_statuses} stg
                                ON stg.id = atl.statusid
                               AND stg.deleted = 0
                               AND stg.visible = 1
                              JOIN (
                                    SELECT attendanceid, setnumber, MAX(grade) AS maxgrade
                                      FROM {attendance_statuses}
                                     WHERE deleted = 0
                                       AND visible = 1
                                  GROUP BY attendanceid, setnumber
                              ) stm
                                ON stm.attendanceid = ats.attendanceid
                               AND stm.setnumber = ats.statusset
                             WHERE ats.lasttaken <> 0
                                   {$where}
                          GROUP BY ats.attendanceid, atl.studentid, ats.sessdate, ats.duration
                      ) z
                  GROUP BY z.attendanceid, z.userid
              ) pt
                ON pt.attendanceid = gi.iteminstance
               AND pt.userid = gg.userid
              LEFT JOIN (
                    SELECT gi2.iteminstance AS attendanceid,
                           h.userid,
                           MAX(h.timemodified) AS last_grade_write_ts
                      FROM {grade_grades_history} h
                      JOIN {grade_items} gi2
                        ON gi2.id = h.itemid
                       AND gi2.itemmodule = 'attendance'
                  GROUP BY gi2.iteminstance, h.userid
              ) lw
                ON lw.attendanceid = gi.iteminstance
               AND lw.userid = gg.userid
             WHERE gi.itemmodule = 'attendance'
               AND gi.grademax > 0
               AND gg.rawgrade IS NOT NULL
               AND COALESCE(gg.overridden, 0) = 0
               AND COALESCE(gg.excluded, 0) = 0
               AND COALESCE(gg.locked, 0) = 0
               AND pt.has_conflict = 1
               AND ABS(
                    gg.rawgrade - ((pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) * gi.grademax)
               ) > :eps
               {$erawhere}
          ORDER BY ABS(
                    gg.rawgrade - ((pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) * gi.grademax)
               ) DESC,
                   c.id, a.id, u.id";

    $records = [];
    $recordset = $DB->get_recordset_sql($sql, $params);
    foreach ($recordset as $record) {
        $record->pairkey = $record->attendanceid . ':' . $record->userid;
        $records[] = $record;
    }
    $recordset->close();
    return $records;
}

/**
 * Fetch current grade snapshot for attendance/user pairs.
 *
 * @param moodle_database $DB
 * @param array<int, stdClass> $pairs
 * @return array<string, stdClass>
 */
function mod_attendance_get_grade_snapshot(moodle_database $DB, array $pairs): array {
    if (empty($pairs)) {
        return [];
    }
    $attendanceids = [];
    $userids = [];
    $pairkeys = [];
    foreach ($pairs as $pair) {
        $attendanceids[] = (int)$pair->attendanceid;
        $userids[] = (int)$pair->userid;
        $pairkeys[$pair->attendanceid . ':' . $pair->userid] = true;
    }
    $attendanceids = array_values(array_unique($attendanceids));
    $userids = array_values(array_unique($userids));

    [$attinsql, $attparams] = $DB->get_in_or_equal($attendanceids, SQL_PARAMS_NAMED, 'att');
    [$userinsql, $userparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'usr');
    $sql = "SELECT gi.iteminstance AS attendanceid,
                   gg.userid,
                   gg.rawgrade,
                   gg.finalgrade
              FROM {grade_items} gi
              JOIN {grade_grades} gg ON gg.itemid = gi.id
             WHERE gi.itemmodule = 'attendance'
               AND gi.iteminstance {$attinsql}
               AND gg.userid {$userinsql}";
    $params = $attparams + $userparams;
    $snapshot = [];
    $records = $DB->get_recordset_sql($sql, $params);
    foreach ($records as $record) {
        $key = $record->attendanceid . ':' . $record->userid;
        if (isset($pairkeys[$key])) {
            $snapshot[$key] = $record;
        }
    }
    $records->close();
    return $snapshot;
}

$valideras = ['before_fix', 'after_fix', 'all'];
if (!in_array($era, $valideras, true)) {
    $era = 'before_fix';
}
$fixts = strtotime($fixdate . ' 00:00:00');
if ($fixts === false) {
    $fixts = strtotime(date('Y-m-d') . ' 00:00:00');
    $fixdate = date('Y-m-d');
}

$candidates = mod_attendance_get_raw_mismatch_candidates($DB, $attendanceid, $era, $fixts, $eps);
$attendancekeys = [];
$userkeys = [];
foreach ($candidates as $row) {
    $attendancekeys[$row->attendanceid] = true;
    $userkeys[$row->userid] = true;
}
$numattendance = count($attendancekeys);
$numusers = count($userkeys);

$beforesnapshot = mod_attendance_get_grade_snapshot($DB, $candidates);
$aftersnapshot = [];
$applydone = false;
$updatedactivities = 0;
$updatedusers = 0;

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('recalculategrades', 'attendance'));

if ($action === 'apply') {
    require_sesskey();
    if (empty($candidates)) {
        echo $OUTPUT->notification(get_string('recalculategradesnothing', 'attendance'), \core\output\notification::NOTIFY_INFO);
    } else {
        $pairsbyattendance = [];
        foreach ($candidates as $row) {
            if (empty($pairsbyattendance[$row->attendanceid])) {
                $pairsbyattendance[$row->attendanceid] = [];
            }
            $pairsbyattendance[$row->attendanceid][$row->userid] = true;
        }
        foreach ($pairsbyattendance as $attid => $usersmap) {
            $attendance = $DB->get_record('attendance', ['id' => $attid], 'id,grade', MUST_EXIST);
            if (empty($attendance->grade)) {
                continue;
            }
            $userids = array_map('intval', array_keys($usersmap));
            attendance_update_users_grades_by_id((int)$attid, (int)$attendance->grade, $userids);
            $updatedactivities++;
            $updatedusers += count($userids);
        }
        $message = get_string('recalculategradesdone', 'attendance',
            (object)['activities' => $updatedactivities, 'users' => $updatedusers]);
        echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_SUCCESS);
        $applydone = true;
        $aftersnapshot = mod_attendance_get_grade_snapshot($DB, $candidates);
    }
}

$summary = get_string('recalculategradessummarydetailed', 'attendance',
    (object)[
        'activities' => $numattendance,
        'users' => $numusers,
        'fixdate' => $fixdate,
        'era' => $era,
    ]);
echo html_writer::div($summary, 'alert alert-info');

// Filter form (preview mode).
$filterurl = new moodle_url('/mod/attendance/recalculate.php');
$filterform = html_writer::start_tag('form', ['method' => 'get', 'action' => $filterurl->out(false), 'class' => 'mb-3']);
$filterform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'preview']);
$filterform .= html_writer::label(get_string('recalculategradesattendanceid', 'attendance'),
    'recalculate-attendanceid', false, ['class' => 'mr-2']);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'attendanceid',
    'id' => 'recalculate-attendanceid',
    'value' => $attendanceid ?: '',
    'class' => 'mr-2',
    'style' => 'max-width: 160px;',
]);
$filterform .= html_writer::label(get_string('recalculategradesera', 'attendance'),
    'recalculate-era', false, ['class' => 'mr-2']);
$options = [
    'before_fix' => get_string('recalculategradesera_before', 'attendance'),
    'after_fix' => get_string('recalculategradesera_after', 'attendance'),
    'all' => get_string('recalculategradesera_all', 'attendance'),
];
$filterform .= html_writer::select($options, 'era', $era, false, ['id' => 'recalculate-era', 'class' => 'mr-2']);
$filterform .= html_writer::label(get_string('recalculategradesfixdate', 'attendance'),
    'recalculate-fixdate', false, ['class' => 'mr-2']);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'date',
    'name' => 'fixdate',
    'id' => 'recalculate-fixdate',
    'value' => $fixdate,
    'class' => 'mr-2',
]);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'number',
    'step' => '0.00001',
    'name' => 'eps',
    'value' => $eps,
    'style' => 'max-width: 130px;',
    'class' => 'mr-2',
]);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('recalculategradespreview', 'attendance'),
    'class' => 'btn btn-secondary',
]);
$filterform .= html_writer::end_tag('form');
echo $filterform;

if (empty($candidates)) {
    echo html_writer::div(get_string('recalculategradesnothing', 'attendance'), 'alert alert-warning');
} else {
    $applyurl = new moodle_url('/mod/attendance/recalculate.php', [
        'action' => 'apply',
        'attendanceid' => $attendanceid,
        'era' => $era,
        'fixdate' => $fixdate,
        'eps' => $eps,
        'sesskey' => sesskey(),
    ]);
    $button = new single_button($applyurl, get_string('recalculategradesconfirm', 'attendance'), 'post');
    $button->formid = 'attendance-recalculate-form';
    echo $OUTPUT->render($button);
}

if (!empty($candidates)) {
    $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('modulename', 'attendance'),
        get_string('participant', 'attendance'),
        'Raw before',
        'Expected raw',
        'Delta',
        'Last write',
    ];
    if ($applydone) {
        $table->head[] = 'Raw after';
        $table->head[] = 'Final after';
    }
    foreach ($candidates as $row) {
        $key = $row->pairkey;
        $before = $beforesnapshot[$key] ?? null;
        $cells = [];
        $cells[] = format_string($row->coursename);
        $cells[] = format_string($row->attendancename) . ' (#' . $row->attendanceid . ')';
        $cells[] = fullname((object)[
            'firstname' => $row->firstname,
            'lastname' => $row->lastname,
        ]) . ' (' . s($row->username) . ')';
        $cells[] = is_null($before->rawgrade ?? null) ? '-' : format_float((float)$before->rawgrade, 5);
        $cells[] = format_float((float)$row->raw_expected_policy, 5);
        $cells[] = format_float((float)$row->delta_raw_vs_policy, 5);
        $cells[] = userdate((int)$row->last_grade_write_ts);
        if ($applydone) {
            $after = $aftersnapshot[$key] ?? null;
            $cells[] = is_null($after->rawgrade ?? null) ? '-' : format_float((float)$after->rawgrade, 5);
            $cells[] = is_null($after->finalgrade ?? null) ? '-' : format_float((float)$after->finalgrade, 5);
        }
        $table->data[] = $cells;
    }
    echo html_writer::table($table);
}

echo $OUTPUT->footer();

