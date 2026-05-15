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
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/attendance/classes/summary.php');

$action = optional_param('action', '', PARAM_ALPHA);
$attendanceid = optional_param('attendanceid', 0, PARAM_INT);
$cmid = optional_param('cmid', 0, PARAM_INT);
$forensic_userid = optional_param('forensic_userid', 0, PARAM_INT);
$reject_lower = optional_param('reject_lower', 1, PARAM_INT);
$era = optional_param('era', 'before_fix', PARAM_ALPHA);
$fixdate = optional_param('fixdate', date('Y-m-d'), PARAM_TEXT);
$eps = optional_param('eps', 0.00001, PARAM_FLOAT);
$mode = optional_param('mode', 'strict', PARAM_ALPHA);
$seedtext = optional_param('seed_text', '', PARAM_RAW);
$reject_lower = $reject_lower ? 1 : 0;

require_login();

$systemcontext = context_system::instance();
if (!is_siteadmin()) {
    throw new required_capability_exception($systemcontext, 'moodle/site:config', 'nopermissions', '');
}

$validmodes = ['strict', 'fallback', 'sql_seeded'];
if (!in_array($mode, $validmodes, true)) {
    $mode = 'strict';
}

$attendance_instance_id = (int) $attendanceid;
$cmidresolvednote = '';
if ($cmid > 0) {
    $cm = get_coursemodule_from_id('attendance', $cmid, 0, false, MUST_EXIST);
    $resolvedfromcm = (int) $cm->instance;
    if ($attendance_instance_id > 0 && $attendance_instance_id !== $resolvedfromcm) {
        $cmidresolvednote = get_string('recalculategradescmidconflict', 'attendance',
            (object) ['cmid' => $cmid, 'fromcm' => $resolvedfromcm, 'fromfield' => $attendance_instance_id]);
    }
    $attendance_instance_id = $resolvedfromcm;
}

$baseurlparams = [
    'attendanceid' => $attendanceid,
    'cmid' => $cmid,
    'forensic_userid' => $forensic_userid,
    'reject_lower' => $reject_lower,
    'era' => $era,
    'fixdate' => $fixdate,
    'eps' => $eps,
    'mode' => $mode,
];
$PAGE->set_context($systemcontext);
$PAGE->set_url(new moodle_url('/mod/attendance/recalculate.php', $baseurlparams));
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('recalculategrades', 'attendance'));
$PAGE->set_heading(get_string('recalculategrades', 'attendance'));

/**
 * Parse lines like "attendanceid,userid" from textarea.
 *
 * @param string $text
 * @return array<int, array{0: int, 1: int}>
 */
function mod_attendance_recalculate_parse_seed_pairs(string $text): array {
    $lines = preg_split('/\r\n|\r|\n/', $text);
    $pairs = [];
    $seen = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '--') === 0) {
            continue;
        }
        if (preg_match('/^\s*(\d+)\s*[,;\t]\s*(\d+)\s*$/', $line, $m)) {
            $key = $m[1] . ':' . $m[2];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $pairs[] = [(int) $m[1], (int) $m[2]];
        }
    }
    return $pairs;
}

/**
 * Policy aggregate (timeslot SQL) for a single attendance instance and user.
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @param int $userid
 * @return stdClass|null fields: points_policy, maxpoints_policy, has_conflict
 */
function mod_attendance_recalculate_get_pt_aggregate_for_pair(
    moodle_database $DB,
    int $attendanceid,
    int $userid
): ?stdClass {
    $sql = "SELECT SUM(z.slot_grade_policy) AS points_policy,
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
                      JOIN {attendance} a_slot ON a_slot.id = ats.attendanceid
                      JOIN {course} c_slot ON c_slot.id = a_slot.course
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
                       AND ats.sessdate >= c_slot.startdate
                       AND ats.attendanceid = :attendanceid
                       AND atl.studentid = :userid
                  GROUP BY ats.attendanceid, atl.studentid, ats.sessdate, ats.duration
              ) z
          GROUP BY z.attendanceid, z.userid";

    return $DB->get_record_sql($sql, ['attendanceid' => $attendanceid, 'userid' => $userid]);
}

/**
 * Raw attendance_log rows with session and status details (basis of grade calculation).
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @param int $userid
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_forensic_get_log_rows(
    moodle_database $DB,
    int $attendanceid,
    int $userid
): array {
    $sql = "SELECT atl.id AS logid,
                   atl.sessionid,
                   atl.timetaken,
                   ats.sessdate,
                   ats.duration,
                   ats.groupid,
                   ats.statusset,
                   ats.autoassignstatus,
                   ats.lasttaken,
                   stg.id AS statusid,
                   stg.acronym,
                   stg.description AS statusdesc,
                   stg.grade AS statusgrade,
                   stm.maxgrade AS setmaxgrade,
                   c.startdate AS coursestartdate,
                   CASE WHEN ats.sessdate < c.startdate THEN 1 ELSE 0 END AS excluded_by_course_start
              FROM {attendance_log} atl
              JOIN {attendance_sessions} ats ON ats.id = atl.sessionid
              JOIN {attendance} a ON a.id = ats.attendanceid
              JOIN {course} c ON c.id = a.course
              JOIN {attendance_statuses} stg
                ON stg.id = atl.statusid
               AND stg.deleted = 0
               AND stg.visible = 1
              LEFT JOIN (
                    SELECT attendanceid, setnumber, MAX(grade) AS maxgrade
                      FROM {attendance_statuses}
                     WHERE deleted = 0
                       AND visible = 1
                  GROUP BY attendanceid, setnumber
              ) stm
                ON stm.attendanceid = ats.attendanceid
               AND stm.setnumber = ats.statusset
             WHERE ats.attendanceid = :attendanceid
               AND atl.studentid = :userid
          ORDER BY ats.sessdate ASC, ats.duration ASC, ats.id ASC, atl.id ASC";

    return $DB->get_records_sql($sql, ['attendanceid' => $attendanceid, 'userid' => $userid]);
}

/**
 * Timeslot-collapsed rows (same rules as summary / policy SQL).
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @param int $userid
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_forensic_get_slot_rows(
    moodle_database $DB,
    int $attendanceid,
    int $userid
): array {
    $sql = "SELECT z.sessdate,
                   z.duration,
                   z.sessions_in_slot,
                   z.session_ids,
                   z.min_grade,
                   z.max_grade,
                   z.min_autoassign,
                   z.max_autoassign,
                   z.slot_grade_policy,
                   z.slot_maxgrade,
                   z.included_in_summary
              FROM (
                    SELECT ats.sessdate,
                           ats.duration,
                           COUNT(DISTINCT ats.id) AS sessions_in_slot,
                           GROUP_CONCAT(DISTINCT ats.id ORDER BY ats.id) AS session_ids,
                           MIN(stg.grade) AS min_grade,
                           MAX(stg.grade) AS max_grade,
                           MIN(ats.autoassignstatus) AS min_autoassign,
                           MAX(ats.autoassignstatus) AS max_autoassign,
                           CASE
                             WHEN MIN(ats.autoassignstatus) = 0 AND MAX(ats.autoassignstatus) = 0
                               THEN MIN(stg.grade)
                             ELSE MAX(stg.grade)
                           END AS slot_grade_policy,
                           MAX(stm.maxgrade) AS slot_maxgrade,
                           MAX(CASE WHEN ats.lasttaken <> 0 AND ats.sessdate >= c_slot.startdate THEN 1 ELSE 0 END) AS included_in_summary
                      FROM {attendance_sessions} ats
                      JOIN {attendance} a_slot ON a_slot.id = ats.attendanceid
                      JOIN {course} c_slot ON c_slot.id = a_slot.course
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
                     WHERE ats.attendanceid = :attendanceid
                       AND atl.studentid = :userid
                  GROUP BY ats.sessdate, ats.duration
              ) z
          ORDER BY z.sessdate ASC, z.duration ASC";

    return $DB->get_records_sql($sql, ['attendanceid' => $attendanceid, 'userid' => $userid]);
}

/**
 * Status scale rows for this attendance instance (per status set).
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_forensic_get_status_rows(moodle_database $DB, int $attendanceid): array {
    $sql = "SELECT id,
                   setnumber,
                   acronym,
                   description,
                   grade,
                   visible,
                   deleted
              FROM {attendance_statuses}
             WHERE attendanceid = :attendanceid
          ORDER BY setnumber ASC, grade ASC, id ASC";

    return $DB->get_records_sql($sql, ['attendanceid' => $attendanceid]);
}

/**
 * HTML tables: log basis, timeslot collapse, status sets.
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @param int $userid
 * @param int $coursestartdate
 * @return string
 */
function mod_attendance_recalculate_forensic_basis_html(
    moodle_database $DB,
    int $attendanceid,
    int $userid,
    int $coursestartdate
): string {
    $out = '';

    $statusrows = mod_attendance_recalculate_forensic_get_status_rows($DB, $attendanceid);
    if ($statusrows) {
        $stable = new html_table();
        $stable->head = [
            get_string('recalculategradesforensic_status_set', 'attendance'),
            get_string('recalculategradesforensic_status_acronym', 'attendance'),
            get_string('recalculategradesforensic_status_grade', 'attendance'),
            get_string('recalculategradesforensic_status_desc', 'attendance'),
            get_string('recalculategradesforensic_status_visible', 'attendance'),
        ];
        foreach ($statusrows as $sr) {
            $stable->data[] = [
                (int) $sr->setnumber,
                s($sr->acronym),
                format_float((float) $sr->grade, 5),
                format_string($sr->description),
                !empty($sr->visible) && empty($sr->deleted)
                    ? get_string('yes', 'moodle')
                    : get_string('no', 'moodle'),
            ];
        }
        $out .= html_writer::tag('h4', get_string('recalculategradesforensic_status_title', 'attendance'));
        $out .= html_writer::table($stable);
    }

    $logrows = mod_attendance_recalculate_forensic_get_log_rows($DB, $attendanceid, $userid);
    $out .= html_writer::tag('h4', get_string('recalculategradesforensic_log_title', 'attendance'));
    if (empty($logrows)) {
        $out .= html_writer::div(get_string('recalculategradesforensic_log_empty', 'attendance'), 'alert alert-warning');
    } else {
        $ltable = new html_table();
        $ltable->head = [
            get_string('recalculategradesforensic_log_session', 'attendance'),
            get_string('recalculategradesforensic_log_slot', 'attendance'),
            get_string('recalculategradesforensic_log_group', 'attendance'),
            get_string('recalculategradesforensic_log_status', 'attendance'),
            get_string('recalculategradesforensic_log_grade', 'attendance'),
            get_string('recalculategradesforensic_log_setmax', 'attendance'),
            get_string('recalculategradesforensic_log_autoassign', 'attendance'),
            get_string('recalculategradesforensic_log_lasttaken', 'attendance'),
            get_string('recalculategradesforensic_log_insummary', 'attendance'),
        ];
        foreach ($logrows as $lr) {
            $included = (
                !empty($lr->lasttaken) &&
                empty($lr->excluded_by_course_start)
            );
            $ltable->data[] = [
                (int) $lr->sessionid,
                userdate((int) $lr->sessdate) . ' +' . (int) $lr->duration . 's',
                (int) $lr->groupid ?: '—',
                s($lr->acronym) . ' (#' . (int) $lr->statusid . ')',
                format_float((float) $lr->statusgrade, 5),
                format_float((float) $lr->setmaxgrade, 5),
                (int) $lr->autoassignstatus,
                !empty($lr->lasttaken) ? userdate((int) $lr->lasttaken) : '—',
                $included ? get_string('yes', 'moodle') : get_string('no', 'moodle'),
            ];
        }
        $out .= html_writer::table($ltable);
        $out .= html_writer::div(get_string('recalculategradesforensic_log_help', 'attendance',
            userdate($coursestartdate)), 'text-muted small mb-3');
    }

    $slotrows = mod_attendance_recalculate_forensic_get_slot_rows($DB, $attendanceid, $userid);
    $out .= html_writer::tag('h4', get_string('recalculategradesforensic_slot_title', 'attendance'));
    if (empty($slotrows)) {
        $out .= html_writer::div(get_string('recalculategradesforensic_slot_empty', 'attendance'), 'alert alert-warning');
    } else {
        $sumpoints = 0.0;
        $summax = 0.0;
        $stable = new html_table();
        $stable->head = [
            get_string('recalculategradesforensic_log_slot', 'attendance'),
            get_string('recalculategradesforensic_slot_sessions', 'attendance'),
            get_string('recalculategradesforensic_slot_sessionids', 'attendance'),
            get_string('recalculategradesforensic_slot_grades', 'attendance'),
            get_string('recalculategradesforensic_slot_policy', 'attendance'),
            get_string('recalculategradesforensic_log_setmax', 'attendance'),
            get_string('recalculategradesforensic_slot_rule', 'attendance'),
            get_string('recalculategradesforensic_log_insummary', 'attendance'),
        ];
        foreach ($slotrows as $sr) {
            if (!empty($sr->included_in_summary)) {
                $sumpoints += (float) $sr->slot_grade_policy;
                $summax += (float) $sr->slot_maxgrade;
            }
            if ((int) $sr->min_autoassign === 0 && (int) $sr->max_autoassign === 0) {
                $rule = get_string('recalculategradesforensic_slot_rule_min', 'attendance');
            } else {
                $rule = get_string('recalculategradesforensic_slot_rule_max', 'attendance');
            }
            $stable->data[] = [
                userdate((int) $sr->sessdate) . ' +' . (int) $sr->duration . 's',
                (int) $sr->sessions_in_slot,
                s($sr->session_ids),
                format_float((float) $sr->min_grade, 5) . ' … ' . format_float((float) $sr->max_grade, 5),
                format_float((float) $sr->slot_grade_policy, 5),
                format_float((float) $sr->slot_maxgrade, 5),
                $rule,
                !empty($sr->included_in_summary) ? get_string('yes', 'moodle') : get_string('no', 'moodle'),
            ];
        }
        $out .= html_writer::table($stable);
        $pct = $summax > 0 ? ($sumpoints / $summax) : 0;
        $out .= html_writer::div(get_string('recalculategradesforensic_slot_total', 'attendance', (object) [
            'points' => format_float($sumpoints, 5),
            'max' => format_float($summax, 5),
            'pct' => format_float($pct * 100, 2),
        ]), 'alert alert-secondary');
    }

    return $out;
}

/**
 * Build HTML forensic drill-down for one pair.
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @param int $userid
 * @param string $era
 * @param int $fixts
 * @param float $eps
 * @return string
 */
function mod_attendance_recalculate_forensic_html(
    moodle_database $DB,
    int $attendanceid,
    int $userid,
    string $era,
    int $fixts,
    float $eps
): string {

    $out = html_writer::tag('h3', get_string('recalculategradesforensic_title', 'attendance'));

    $att = $DB->get_record('attendance', ['id' => $attendanceid], '*', IGNORE_MISSING);
    if (!$att) {
        return $out . html_writer::div(get_string('recalculategradesforensic_noattendance', 'attendance'), 'alert alert-warning');
    }

    $course = $DB->get_record('course', ['id' => $att->course], 'id,fullname,startdate', MUST_EXIST);
    $user = $DB->get_record('user', ['id' => $userid], 'id,firstname,lastname,username', IGNORE_MISSING);
    if (!$user) {
        return $out . html_writer::div(get_string('recalculategradesforensic_nouser', 'attendance'), 'alert alert-warning');
    }

    $gi = $DB->get_record('grade_items', [
        'itemtype' => 'mod',
        'itemmodule' => 'attendance',
        'iteminstance' => $attendanceid,
        'courseid' => $course->id,
    ], '*', IGNORE_MISSING);
    if (!$gi) {
        $gi = $DB->get_record('grade_items', [
            'itemmodule' => 'attendance',
            'iteminstance' => $attendanceid,
            'courseid' => $course->id,
        ], '*', IGNORE_MISSING);
    }

    $steps = [];
    $gg = null;

    if (!$gi) {
        $steps[] = get_string('recalculategradesforensic_step_noitem', 'attendance');
    } else {
        $gg = $DB->get_record('grade_grades', ['itemid' => $gi->id, 'userid' => $userid], '*', IGNORE_MISSING);
        if (!$gg) {
            $steps[] = get_string('recalculategradesforensic_step_nograde', 'attendance');
        } else {
            $flags = [];
            if (!empty($gg->overridden)) {
                $flags[] = 'overridden';
            }
            if (!empty($gg->excluded)) {
                $flags[] = 'excluded';
            }
            if (!empty($gg->locked)) {
                $flags[] = 'locked';
            }
            if ($flags) {
                $steps[] = get_string('recalculategradesforensic_step_flags', 'attendance', implode(', ', $flags));
            } else {
                $steps[] = get_string('recalculategradesforensic_step_flagsok', 'attendance');
            }

            $rawdisp = is_null($gg->rawgrade) ? 'NULL' : format_float((float) $gg->rawgrade, 5);
            $finaldisp = is_null($gg->finalgrade) ? 'NULL' : format_float((float) $gg->finalgrade, 5);
            $steps[] = get_string('recalculategradesforensic_step_grades', 'attendance',
                (object) ['raw' => $rawdisp, 'final' => $finaldisp, 'max' => format_float((float) $gi->grademax, 5)]);
        }

        $lastwrite = (int) $DB->get_field_sql(
            "SELECT MAX(h.timemodified)
               FROM {grade_grades_history} h
              WHERE h.itemid = ? AND h.userid = ?",
            [$gi->id, $userid]
        );
        $steps[] = get_string('recalculategradesforensic_step_lastwrite', 'attendance', userdate($lastwrite));

        if ($era === 'before_fix') {
            $pass = ($lastwrite === 0 || $lastwrite < $fixts);
            $steps[] = $pass
                ? get_string('recalculategradesforensic_step_erapass_before', 'attendance')
                : get_string('recalculategradesforensic_step_erafail_before', 'attendance');
        } else if ($era === 'after_fix') {
            $pass = ($lastwrite >= $fixts);
            $steps[] = $pass
                ? get_string('recalculategradesforensic_step_erapass_after', 'attendance')
                : get_string('recalculategradesforensic_step_erafail_after', 'attendance');
        } else {
            $steps[] = get_string('recalculategradesforensic_step_eraall', 'attendance');
        }
    }

    $steps[] = get_string('recalculategradesforensic_step_coursestart', 'attendance',
        userdate((int) $course->startdate));

    $pt = mod_attendance_recalculate_get_pt_aggregate_for_pair($DB, $attendanceid, $userid);
    if (!$pt || (float) $pt->maxpoints_policy <= 0) {
        $steps[] = get_string('recalculategradesforensic_step_nopt', 'attendance');
    } else {
        $grademax = ($gi) ? (float) $gi->grademax : 0.0;
        $sqlexp = $grademax > 0
            ? ((float) $pt->points_policy / (float) $pt->maxpoints_policy) * $grademax
            : null;
        $sqldisp = $sqlexp === null ? '—' : format_float($sqlexp, 5);
        $steps[] = get_string('recalculategradesforensic_step_pt', 'attendance', (object) [
            'points' => format_float((float) $pt->points_policy, 5),
            'maxp' => format_float((float) $pt->maxpoints_policy, 5),
            'conflict' => !empty($pt->has_conflict) ? get_string('yes', 'moodle') : get_string('no', 'moodle'),
            'sqlexp' => $sqldisp,
        ]);
    }

    $attendancegrade = 0;
    $sumexp = null;
    if (!empty($att->grade)) {
        $g = (int) $att->grade;
        if ($g < 0) {
            $scale = $DB->get_record('scale', ['id' => -$g], '*', MUST_EXIST);
            $scalearray = explode(',', $scale->scale);
            $attendancegrade = count($scalearray);
        } else {
            $attendancegrade = $g;
        }
    }
    if ($attendancegrade <= 0) {
        $steps[] = get_string('recalculategradesforensic_step_noactivitygrade', 'attendance');
        $sumexp = null;
    } else {
        $summary = new mod_attendance_summary($attendanceid, [$userid]);
        if ($summary->has_taken_sessions($userid)) {
            $us = $summary->get_taken_sessions_summary_for($userid);
            $sumexp = $us->takensessionspercentage * $attendancegrade;
            $steps[] = get_string('recalculategradesforensic_step_summary', 'attendance', (object) [
                'pct' => format_float($us->takensessionspercentage * 100, 2),
                'exp' => format_float($sumexp, 5),
            ]);
        } else {
            $sumexp = null;
            $steps[] = get_string('recalculategradesforensic_step_summarynone', 'attendance');
        }
    }

    if ($gi && $gg && $pt && (float) $pt->maxpoints_policy > 0 && !is_null($gg->rawgrade)) {
        $sqlexpfull = ((float) $pt->points_policy / (float) $pt->maxpoints_policy) * (float) $gi->grademax;
        $deltasql = (float) $gg->rawgrade - $sqlexpfull;
        if (abs($deltasql) <= $eps) {
            $steps[] = get_string('recalculategradesforensic_step_sqlmatch', 'attendance');
        } else {
            $steps[] = get_string('recalculategradesforensic_step_sqlmismatch', 'attendance', format_float($deltasql, 5));
        }
    }

    if ($gi && $gg && isset($sumexp) && !is_null($gg->rawgrade) && $sumexp !== null) {
        $deltasum = (float) $gg->rawgrade - (float) $sumexp;
        if (abs($deltasum) <= $eps) {
            $steps[] = get_string('recalculategradesforensic_step_summarymatch', 'attendance');
        } else {
            $steps[] = get_string('recalculategradesforensic_step_summarymismatch', 'attendance', format_float($deltasum, 5));
        }
    }

    $olist = html_writer::start_tag('ol', ['class' => 'mb-3']);
    foreach ($steps as $st) {
        $olist .= html_writer::tag('li', $st);
    }
    $olist .= html_writer::end_tag('ol');
    $out .= $olist;

    $out .= mod_attendance_recalculate_forensic_basis_html(
        $DB,
        $attendanceid,
        $userid,
        (int) $course->startdate
    );

    if ($gi) {
        $histcols = $DB->get_columns('grade_grades_history');
        $hassource = array_key_exists('source', $histcols);
        $histsql = "SELECT h.timemodified, h.rawgrade, h.finalgrade, h.usermodified";
        if ($hassource) {
            $histsql .= ", h.source";
        }
        $histsql .= " FROM {grade_grades_history} h
              WHERE h.itemid = ?
                AND h.userid = ?
           ORDER BY h.timemodified DESC";
        $hist = $DB->get_records_sql($histsql, [$gi->id, $userid], 0, 25);
        if ($hist) {
            $table = new html_table();
            $table->head = [
                get_string('recalculategradesforensic_hist_time', 'attendance'),
                get_string('recalculategradesforensic_hist_user', 'attendance'),
                get_string('recalculategradesforensic_hist_raw', 'attendance'),
                get_string('recalculategradesforensic_hist_final', 'attendance'),
            ];
            if ($hassource) {
                $table->head[] = get_string('recalculategradesforensic_hist_source', 'attendance');
            }
            foreach ($hist as $h) {
                $modifier = '';
                if (!empty($h->usermodified)) {
                    $mu = $DB->get_record('user', ['id' => $h->usermodified], 'id,firstname,lastname', IGNORE_MISSING);
                    $modifier = $mu ? fullname($mu) : (string) $h->usermodified;
                }
                $row = [
                    userdate((int) $h->timemodified),
                    s($modifier),
                    is_null($h->rawgrade) ? '-' : format_float((float) $h->rawgrade, 5),
                    is_null($h->finalgrade) ? '-' : format_float((float) $h->finalgrade, 5),
                ];
                if ($hassource) {
                    $row[] = s($h->source ?? '');
                }
                $table->data[] = $row;
            }
            $out .= html_writer::tag('h4', get_string('recalculategradesforensic_hist_title', 'attendance'));
            $out .= html_writer::table($table);
        } else {
            $out .= html_writer::div(get_string('recalculategradesforensic_hist_empty', 'attendance'), 'alert alert-info');
        }
    }

    $out .= html_writer::div(
        format_string($course->fullname) . ' — ' . format_string($att->name) .
        ' (#' . $attendanceid . ') — ' . fullname($user) . ' (' . s($user->username) . ')',
        'text-muted small'
    );

    return $out;
}

/**
 * Count preview reasons: pool vs after summary filter, and apply direction splits.
 *
 * @param array<int, stdClass> $pool after SQL + enrich + display
 * @param array<int, stdClass> $aftersummary after filter_after_summary
 * @param float $eps
 * @param bool $requireconflict strict mode
 * @return stdClass
 */
function mod_attendance_recalculate_reason_counts(
    array $pool,
    array $aftersummary,
    float $eps,
    bool $requireconflict
): stdClass {
    $o = new stdClass();
    $o->pool_count = count($pool);
    $afterkeys = [];
    foreach ($aftersummary as $r) {
        $afterkeys[$r->pairkey] = true;
    }
    $o->dropped_after_summary = 0;
    $o->dropped_strict_noconflict = 0;
    foreach ($pool as $r) {
        if (isset($afterkeys[$r->pairkey])) {
            continue;
        }
        if ($requireconflict && empty($r->has_conflict)) {
            $o->dropped_strict_noconflict++;
            continue;
        }
        $raw = $r->rawgrade ?? null;
        $exp = $r->raw_expected_summary ?? null;
        if ($exp === null && $raw === null) {
            $o->dropped_after_summary++;
            continue;
        }
        if ($exp === null || $raw === null) {
            $o->dropped_after_summary++;
            continue;
        }
        if (abs((float) $raw - (float) $exp) <= $eps) {
            $o->dropped_after_summary++;
        }
    }

    $o->apply_raise = 0;
    $o->apply_lower = 0;
    $o->apply_edge = 0;
    foreach ($aftersummary as $r) {
        $raw = $r->rawgrade ?? null;
        $exp = $r->raw_expected_summary ?? null;
        if ($raw === null || $exp === null) {
            $o->apply_edge++;
            continue;
        }
        if ((float) $exp > (float) $raw + $eps) {
            $o->apply_raise++;
        } else if ((float) $exp < (float) $raw - $eps) {
            $o->apply_lower++;
        } else {
            $o->apply_edge++;
        }
    }

    return $o;
}

/**
 * Exclude rows where apply would lower numeric raw (optional safety).
 *
 * @param array<int, stdClass> $rows
 * @param float $eps
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_filter_reject_lower(array $rows, float $eps): array {
    $out = [];
    foreach ($rows as $r) {
        $raw = $r->rawgrade ?? null;
        $exp = $r->raw_expected_summary ?? null;
        if ($raw !== null && $exp !== null && (float) $exp < (float) $raw - $eps) {
            continue;
        }
        $out[] = $r;
    }
    return $out;
}

/**
 * SQL fragment: last grade write time per attendance instance and user.
 *
 * @return array{sql: string, empty: bool}
 */
function mod_attendance_recalculate_sql_last_write_subquery(): array {
    $sql = "LEFT JOIN (
                SELECT gi2.iteminstance AS attendanceid,
                       h.userid,
                       MAX(h.timemodified) AS last_grade_write_ts
                  FROM {grade_grades_history} h
                  JOIN {grade_items} gi2
                    ON gi2.id = h.itemid
                   AND gi2.itemmodule = 'attendance'
              GROUP BY gi2.iteminstance, h.userid
            ) lw ON lw.attendanceid = gi.iteminstance AND lw.userid = gg.userid";
    return ['sql' => $sql, 'empty' => false];
}

/**
 * Build policy aggregation subquery (timeslot collapse), aligned with mod_attendance_summary::compute_users_points().
 *
 * @param string $sessionswhere extra AND conditions on ats (with leading space)
 * @return array{sql: string, params: array}
 */
function mod_attendance_recalculate_policy_pt_subquery(string $sessionswhere): array {
    $params = [];
    if ($sessionswhere !== '') {
        // Caller already embeds named params inside $sessionswhere.
    }

    $sql = "SELECT z.attendanceid,
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
                      JOIN {attendance} a_slot ON a_slot.id = ats.attendanceid
                      JOIN {course} c_slot ON c_slot.id = a_slot.course
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
                       AND ats.sessdate >= c_slot.startdate
                           {$sessionswhere}
                  GROUP BY ats.attendanceid, atl.studentid, ats.sessdate, ats.duration
              ) z
          GROUP BY z.attendanceid, z.userid";

    return ['sql' => $sql, 'params' => $params];
}

/**
 * Gradebook + era filters (params: eps only used in outer SELECT for mismatch; fixts for era).
 *
 * @param string $era
 * @return array{erawhere: string, params: array}
 */
function mod_attendance_recalculate_era_clause(string $era, int $fixts): array {
    $params = [];
    $erawhere = '';
    if ($era === 'before_fix') {
        $erawhere = ' AND COALESCE(lw.last_grade_write_ts, 0) < :fixts ';
        $params['fixts'] = $fixts;
    } else if ($era === 'after_fix') {
        $erawhere = ' AND COALESCE(lw.last_grade_write_ts, 0) >= :fixts ';
        $params['fixts'] = $fixts;
    }
    return ['erawhere' => $erawhere, 'params' => $params];
}

/**
 * Candidate rows: rawgrade vs SQL policy expectation, optional strict conflict gate.
 *
 * @param moodle_database $DB
 * @param int $attendanceid 0 = all instances
 * @param string $era before_fix|after_fix|all
 * @param int $fixts
 * @param float $eps
 * @param bool $requireconflict if true, pt.has_conflict = 1
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_query_candidates(
    moodle_database $DB,
    int $attendanceid,
    string $era,
    int $fixts,
    float $eps,
    bool $requireconflict
): array {
    $sessionswhere = '';
    $params = ['eps' => $eps];
    if (!empty($attendanceid)) {
        $sessionswhere = ' AND ats.attendanceid = :attendanceid ';
        $params['attendanceid'] = $attendanceid;
    }

    $ptsql = mod_attendance_recalculate_policy_pt_subquery($sessionswhere);
    $params = $params + $ptsql['params'];

    $eraparts = mod_attendance_recalculate_era_clause($era, $fixts);
    $erawhere = $eraparts['erawhere'];
    $params = $params + $eraparts['params'];

    $lwsql = mod_attendance_recalculate_sql_last_write_subquery();

    $conflictclause = $requireconflict ? ' AND pt.has_conflict = 1 ' : '';

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
                   COALESCE(lw.last_grade_write_ts, 0) AS last_grade_write_ts,
                   pt.has_conflict
              FROM {grade_items} gi
              JOIN {grade_grades} gg ON gg.itemid = gi.id
              JOIN {attendance} a ON a.id = gi.iteminstance
              JOIN {course} c ON c.id = a.course
              JOIN {user} u ON u.id = gg.userid
              {$lwsql['sql']}
              JOIN (
                    {$ptsql['sql']}
              ) pt ON pt.attendanceid = gi.iteminstance AND pt.userid = gg.userid
             WHERE gi.itemmodule = 'attendance'
               AND gi.grademax > 0
               AND gg.rawgrade IS NOT NULL
               AND COALESCE(gg.overridden, 0) = 0
               AND COALESCE(gg.excluded, 0) = 0
               AND COALESCE(gg.locked, 0) = 0
               {$conflictclause}
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
 * Recompute expected raw/final delta using mod_attendance_summary (same path as grade_update).
 *
 * @param array<int, stdClass> $candidates
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_enrich_expected_from_summary(array $candidates): array {
    global $DB;

    if (empty($candidates)) {
        return $candidates;
    }

    $byatt = [];
    foreach ($candidates as $idx => $row) {
        $aid = (int) $row->attendanceid;
        $uid = (int) $row->userid;
        if (!isset($byatt[$aid])) {
            $byatt[$aid] = [];
        }
        $byatt[$aid][$uid] = $idx;
    }

    foreach ($byatt as $attid => $usermap) {
        $userids = array_map('intval', array_keys($usermap));
        $attendance = $DB->get_record('attendance', ['id' => $attid], 'id,grade');
        if (!$attendance || empty($attendance->grade)) {
            foreach ($userids as $uid) {
                $idx = $usermap[$uid];
                $candidates[$idx]->raw_expected_summary = null;
                $candidates[$idx]->delta_raw_vs_summary = null;
            }
            continue;
        }

        $grade = (int) $attendance->grade;
        if ($grade < 0) {
            $scale = $DB->get_record('scale', ['id' => -$grade], '*', MUST_EXIST);
            $scalearray = explode(',', $scale->scale);
            $attendancegrade = count($scalearray);
        } else {
            $attendancegrade = $grade;
        }

        $summary = new mod_attendance_summary($attid, $userids);
        foreach ($userids as $uid) {
            $idx = $usermap[$uid];
            if ($summary->has_taken_sessions($uid)) {
                $us = $summary->get_taken_sessions_summary_for($uid);
                $rawexp = $us->takensessionspercentage * $attendancegrade;
            } else {
                $rawexp = null;
            }
            $candidates[$idx]->raw_expected_summary = $rawexp;
            $raw = $candidates[$idx]->rawgrade ?? null;
            if ($rawexp === null && $raw === null) {
                $candidates[$idx]->delta_raw_vs_summary = 0.0;
            } else if ($rawexp === null) {
                $candidates[$idx]->delta_raw_vs_summary = (float) $raw;
            } else if ($raw === null) {
                $candidates[$idx]->delta_raw_vs_summary = null;
            } else {
                $candidates[$idx]->delta_raw_vs_summary = (float) $raw - (float) $rawexp;
            }
        }
    }

    return $candidates;
}

/**
 * Filter SQL-driven candidates using summary-aligned expectation.
 *
 * @param array<int, stdClass> $candidates
 * @param float $eps
 * @param bool $requireconflict
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_filter_after_summary(
    array $candidates,
    float $eps,
    bool $requireconflict
): array {
    $out = [];
    foreach ($candidates as $row) {
        if ($requireconflict && empty($row->has_conflict)) {
            continue;
        }
        $raw = $row->rawgrade ?? null;
        $exp = $row->raw_expected_summary ?? null;
        if ($exp === null && $raw === null) {
            continue;
        }
        if ($exp === null || $raw === null) {
            $out[] = $row;
            continue;
        }
        if (abs((float) $raw - (float) $exp) > $eps) {
            $out[] = $row;
        }
    }
    return $out;
}

/**
 * Finalize display fields on each row (expected column = summary; keep SQL expected as raw_expected_sql).
 *
 * @param array<int, stdClass> $candidates
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_apply_display_expected(array $candidates): array {
    foreach ($candidates as $idx => $row) {
        if (property_exists($row, 'raw_expected_policy')) {
            $candidates[$idx]->raw_expected_sql = $row->raw_expected_policy;
        }
        if (property_exists($row, 'raw_expected_summary')) {
            $candidates[$idx]->raw_expected_policy = $row->raw_expected_summary;
            if ($row->raw_expected_summary !== null && $row->rawgrade !== null) {
                $candidates[$idx]->delta_raw_vs_policy = (float) $row->rawgrade - (float) $row->raw_expected_summary;
            } else {
                $candidates[$idx]->delta_raw_vs_policy = $row->delta_raw_vs_summary ?? null;
            }
        }
    }
    return $candidates;
}

/**
 * Build candidates from explicit attendanceid/userid pairs (sql_seeded mode).
 *
 * @param moodle_database $DB
 * @param array<int, array{0: int, 1: int}> $pairs
 * @param string $era
 * @param int $fixts
 * @return array<int, stdClass>
 */
function mod_attendance_recalculate_seed_candidates(
    moodle_database $DB,
    array $pairs,
    string $era,
    int $fixts
): array {
    if (empty($pairs)) {
        return [];
    }

    $eraparts = mod_attendance_recalculate_era_clause($era, $fixts);
    $erawhere = $eraparts['erawhere'];
    $params = $eraparts['params'];

    $lwsql = mod_attendance_recalculate_sql_last_write_subquery();

    $candidates = [];
    foreach ($pairs as [$aid, $uid]) {
        if ($aid < 1 || $uid < 1) {
            continue;
        }
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
                       0 AS points_policy,
                       0 AS maxpoints_policy,
                       0 AS pct_policy,
                       0 AS raw_expected_policy,
                       0 AS delta_raw_vs_policy,
                       COALESCE(lw.last_grade_write_ts, 0) AS last_grade_write_ts,
                       0 AS has_conflict
                  FROM {attendance} a
                  JOIN {course} c ON c.id = a.course
                  JOIN {user} u ON u.id = :userid
                  JOIN {grade_items} gi ON gi.itemmodule = 'attendance' AND gi.iteminstance = a.id
                  JOIN {grade_grades} gg ON gg.itemid = gi.id AND gg.userid = u.id
                  {$lwsql['sql']}
                 WHERE a.id = :attendanceid
                   AND gi.grademax > 0
                   AND gg.rawgrade IS NOT NULL
                   AND COALESCE(gg.overridden, 0) = 0
                   AND COALESCE(gg.excluded, 0) = 0
                   AND COALESCE(gg.locked, 0) = 0
                   {$erawhere}";
        $rowparams = $params + ['attendanceid' => $aid, 'userid' => $uid];
        $row = $DB->get_record_sql($sql, $rowparams);
        if (!$row) {
            continue;
        }
        $row->pairkey = $row->attendanceid . ':' . $row->userid;
        $candidates[] = $row;
    }

    return $candidates;
}

/**
 * Diagnostic counts for empty-result explanation.
 *
 * @param moodle_database $DB
 * @param int $attendanceid
 * @param string $era
 * @param int $fixts
 * @param float $eps
 * @return stdClass
 */
function mod_attendance_recalculate_diagnostic_counts(
    moodle_database $DB,
    int $attendanceid,
    string $era,
    int $fixts,
    float $eps
): stdClass {
    $sessionswhere = '';
    $params = [];
    if (!empty($attendanceid)) {
        $sessionswhere = ' AND ats.attendanceid = :attendanceid ';
        $params['attendanceid'] = $attendanceid;
    }
    $ptsql = mod_attendance_recalculate_policy_pt_subquery($sessionswhere);
    $params = $params + $ptsql['params'];

    $eraparts = mod_attendance_recalculate_era_clause($era, $fixts);
    $erawhere = $eraparts['erawhere'];
    $params = $params + $eraparts['params'];

    $lwsql = mod_attendance_recalculate_sql_last_write_subquery();

    $gradebookfrom = "FROM {grade_items} gi
        JOIN {grade_grades} gg ON gg.itemid = gi.id
        JOIN {attendance} a ON a.id = gi.iteminstance
        JOIN {course} c ON c.id = a.course
        JOIN {user} u ON u.id = gg.userid
        {$lwsql['sql']}";

    $gradebookwhere = "WHERE gi.itemmodule = 'attendance'
         AND gi.grademax > 0
         AND gg.rawgrade IS NOT NULL
         AND COALESCE(gg.overridden, 0) = 0
         AND COALESCE(gg.excluded, 0) = 0
         AND COALESCE(gg.locked, 0) = 0
         {$erawhere}";

    $diag = new stdClass();
    $diag->eligible_gradebook = (int) $DB->count_records_sql("SELECT COUNT(1) {$gradebookfrom} {$gradebookwhere}", $params);

    $joinpt = "SELECT COUNT(1)
                 {$gradebookfrom}
                 JOIN (
                       {$ptsql['sql']}
                 ) pt ON pt.attendanceid = gi.iteminstance AND pt.userid = gg.userid";
    $joinpt .= " {$gradebookwhere}";
    $diag->with_policy_rows = (int) $DB->count_records_sql($joinpt, $params);

    $mismatchparams = $params + ['eps' => $eps];
    $mismatchsql = "SELECT COUNT(1)
                     {$gradebookfrom}
                     JOIN (
                           {$ptsql['sql']}
                     ) pt ON pt.attendanceid = gi.iteminstance AND pt.userid = gg.userid
                    {$gradebookwhere}
                      AND pt.maxpoints_policy > 0
                      AND ABS(gg.rawgrade - ((pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) * gi.grademax)) > :eps";
    $diag->sql_mismatch = (int) $DB->count_records_sql($mismatchsql, $mismatchparams);

    $strictsql = "SELECT COUNT(1)
                    {$gradebookfrom}
                    JOIN (
                          {$ptsql['sql']}
                    ) pt ON pt.attendanceid = gi.iteminstance AND pt.userid = gg.userid
                   {$gradebookwhere}
                     AND pt.maxpoints_policy > 0
                     AND pt.has_conflict = 1
                     AND ABS(gg.rawgrade - ((pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) * gi.grademax)) > :eps";
    $diag->sql_mismatch_conflict = (int) $DB->count_records_sql($strictsql, $mismatchparams);

    $diag->sql_mismatch_no_conflict = max(0, $diag->sql_mismatch - $diag->sql_mismatch_conflict);

    return $diag;
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
        $attendanceids[] = (int) $pair->attendanceid;
        $userids[] = (int) $pair->userid;
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
    $qparams = $attparams + $userparams;
    $snapshot = [];
    $records = $DB->get_recordset_sql($sql, $qparams);
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

$ispost = ($_SERVER['REQUEST_METHOD'] === 'POST');
if ($ispost && $action !== '') {
    require_sesskey();
}

$runsearch = $ispost && in_array($action, ['preview', 'apply'], true);
$runforensic = $ispost && (
    $action === 'forensic' ||
    ($runsearch && $forensic_userid > 0)
);

$candidates = [];
$diag = null;
$reasoncounts = null;
$excludedlower = 0;
$aftersummary = [];
$pool = [];

if ($runsearch && $mode === 'sql_seeded') {
    $seedpairs = mod_attendance_recalculate_parse_seed_pairs($seedtext);
    $pool = mod_attendance_recalculate_seed_candidates($DB, $seedpairs, $era, $fixts);
    $pool = mod_attendance_recalculate_enrich_expected_from_summary($pool);
    $pool = mod_attendance_recalculate_apply_display_expected($pool);
    $aftersummary = $pool;
    $reasoncounts = mod_attendance_recalculate_reason_counts($pool, $aftersummary, $eps, false);
    $candidates = $aftersummary;
    if ($reject_lower) {
        $beforect = count($candidates);
        $candidates = mod_attendance_recalculate_filter_reject_lower($candidates, $eps);
        $excludedlower = $beforect - count($candidates);
    }
} else if ($runsearch) {
    $requireconflict = ($mode === 'strict');
    $pool = mod_attendance_recalculate_query_candidates($DB, $attendance_instance_id, $era, $fixts, $eps, $requireconflict);
    $pool = mod_attendance_recalculate_enrich_expected_from_summary($pool);
    $pool = mod_attendance_recalculate_apply_display_expected($pool);
    $aftersummary = mod_attendance_recalculate_filter_after_summary($pool, $eps, $requireconflict);
    $reasoncounts = mod_attendance_recalculate_reason_counts($pool, $aftersummary, $eps, $requireconflict);
    $candidates = $aftersummary;
    if ($reject_lower) {
        $beforect = count($candidates);
        $candidates = mod_attendance_recalculate_filter_reject_lower($candidates, $eps);
        $excludedlower = $beforect - count($candidates);
    }
}

if ($runsearch && empty($candidates) && $mode !== 'sql_seeded') {
    $diag = mod_attendance_recalculate_diagnostic_counts($DB, $attendance_instance_id, $era, $fixts, $eps);
}

$attendancekeys = [];
$userkeys = [];
foreach ($candidates as $row) {
    $attendancekeys[$row->attendanceid] = true;
    $userkeys[$row->userid] = true;
}
$numattendance = count($attendancekeys);
$numusers = count($userkeys);

$beforesnapshot = $runsearch ? mod_attendance_get_grade_snapshot($DB, $candidates) : [];
$aftersnapshot = [];
$applydone = false;
$updatedactivities = 0;
$updatedusers = 0;
$applymessage = '';

if ($action === 'apply' && $runsearch) {
    if (empty($candidates)) {
        $applymessage = get_string('recalculategradesnothing', 'attendance');
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
            attendance_update_users_grades_by_id((int) $attid, (int) $attendance->grade, $userids);
            $updatedactivities++;
            $updatedusers += count($userids);
        }
        $applymessage = get_string('recalculategradesdone', 'attendance',
            (object) ['activities' => $updatedactivities, 'users' => $updatedusers]);
        $applydone = true;
        $aftersnapshot = mod_attendance_get_grade_snapshot($DB, $candidates);
    }
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('recalculategrades', 'attendance'));

if ($cmidresolvednote !== '') {
    echo $OUTPUT->notification($cmidresolvednote, \core\output\notification::NOTIFY_WARNING);
}

if (!$ispost) {
    echo html_writer::div(get_string('recalculategrades_form_intro', 'attendance'), 'alert alert-info');
}

if ($applymessage !== '') {
    $notifytype = $applydone
        ? \core\output\notification::NOTIFY_SUCCESS
        : \core\output\notification::NOTIFY_INFO;
    echo $OUTPUT->notification($applymessage, $notifytype);
}

// Filter form: POST so seed textarea is reliable.
$filterurl = new moodle_url('/mod/attendance/recalculate.php');
$filterform = html_writer::start_tag('form', ['method' => 'post', 'action' => $filterurl->out(false), 'class' => 'mb-3']);
$filterform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);

$modeopts = [
    'strict' => get_string('recalculategradesmode_strict', 'attendance'),
    'fallback' => get_string('recalculategradesmode_fallback', 'attendance'),
    'sql_seeded' => get_string('recalculategradesmode_seeded', 'attendance'),
];
$filterform .= html_writer::div(
    html_writer::label(get_string('recalculategradesmode', 'attendance'), 'recalculate-mode', false, ['class' => 'd-block'])
    . html_writer::select($modeopts, 'mode', $mode, false, ['id' => 'recalculate-mode', 'class' => 'form-control d-inline-block w-auto']),
    'mb-2'
);

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
$filterform .= html_writer::label(get_string('recalculategradescmid', 'attendance'),
    'recalculate-cmid', false, ['class' => 'mr-2']);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'cmid',
    'id' => 'recalculate-cmid',
    'value' => $cmid ?: '',
    'class' => 'mr-2',
    'style' => 'max-width: 140px;',
    'title' => get_string('recalculategradescmid_help', 'attendance'),
]);
$filterform .= html_writer::label(get_string('recalculategradesforensic_userid', 'attendance'),
    'recalculate-forensic', false, ['class' => 'mr-2']);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'number',
    'name' => 'forensic_userid',
    'id' => 'recalculate-forensic',
    'value' => $forensic_userid ?: '',
    'class' => 'mr-2',
    'style' => 'max-width: 140px;',
]);
$rjopts = [
    '1' => get_string('recalculategrades_rejectlower_yes', 'attendance'),
    '0' => get_string('recalculategrades_rejectlower_no', 'attendance'),
];
$filterform .= html_writer::label(get_string('recalculategrades_rejectlower', 'attendance'),
    'recalculate-rejectlower', false, ['class' => 'mr-2']);
$filterform .= html_writer::select($rjopts, 'reject_lower', (string) $reject_lower, false,
    ['id' => 'recalculate-rejectlower', 'class' => 'mr-2']);
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
$filterform .= html_writer::label(get_string('recalculategradesepsilon', 'attendance'),
    'recalculate-eps', false, ['class' => 'mr-2']);
$filterform .= html_writer::empty_tag('input', [
    'type' => 'number',
    'step' => '0.00001',
    'name' => 'eps',
    'id' => 'recalculate-eps',
    'value' => $eps,
    'style' => 'max-width: 130px;',
    'class' => 'mr-2',
]);
$filterform .= html_writer::tag('button', get_string('recalculategradesforensic_submit', 'attendance'), [
    'type' => 'submit',
    'name' => 'action',
    'value' => 'forensic',
    'class' => 'btn btn-outline-secondary mr-2',
]);
$filterform .= html_writer::tag('button', get_string('recalculategradespreview', 'attendance'), [
    'type' => 'submit',
    'name' => 'action',
    'value' => 'preview',
    'class' => 'btn btn-secondary',
]);

$filterform .= html_writer::div(
    html_writer::label(get_string('recalculategradesseedhelp', 'attendance'), 'recalculate-seed', false, ['class' => 'd-block mt-3'])
    . html_writer::tag('textarea', s($seedtext), [
        'name' => 'seed_text',
        'id' => 'recalculate-seed',
        'rows' => 6,
        'cols' => 60,
        'class' => 'form-control',
        'placeholder' => '42,1001',
    ]),
    'mt-2'
);

$filterform .= html_writer::end_tag('form');
echo $filterform;

if ($runforensic) {
    if ($forensic_userid > 0 && $attendance_instance_id > 0) {
        echo html_writer::div(
            mod_attendance_recalculate_forensic_html($DB, $attendance_instance_id, $forensic_userid, $era, $fixts, $eps),
            'card card-body mb-3'
        );
    } else {
        echo $OUTPUT->notification(get_string('recalculategradesforensic_needscope', 'attendance'),
            \core\output\notification::NOTIFY_WARNING);
    }
}

if ($runsearch) {
    $summary = get_string('recalculategradessummarydetailed3', 'attendance',
        (object) [
            'activities' => $numattendance,
            'users' => $numusers,
            'fixdate' => $fixdate,
            'era' => $era,
            'mode' => $mode,
            'eps' => $eps,
            'attendanceid' => $attendanceid ?: get_string('recalculategradesallinstances', 'attendance'),
            'cmid' => $cmid > 0 ? (string) $cmid : '—',
            'instance' => $attendance_instance_id > 0 ? (string) $attendance_instance_id : '—',
            'rejectlower' => $reject_lower ? get_string('yes', 'moodle') : get_string('no', 'moodle'),
        ]);
    echo html_writer::div($summary, 'alert alert-info');

    if ($reasoncounts !== null) {
        $reasonlines = [
            get_string('recalculategrades_reason_title', 'attendance'),
            get_string('recalculategrades_reason_pool', 'attendance', $reasoncounts->pool_count),
            get_string('recalculategrades_reason_droppedsummary', 'attendance', $reasoncounts->dropped_after_summary),
            get_string('recalculategrades_reason_mismatchsummary', 'attendance', count($aftersummary)),
            get_string('recalculategrades_reason_raise', 'attendance', $reasoncounts->apply_raise),
            get_string('recalculategrades_reason_lower', 'attendance', $reasoncounts->apply_lower),
            get_string('recalculategrades_reason_edge', 'attendance', $reasoncounts->apply_edge),
        ];
        if ($reject_lower && $excludedlower > 0) {
            $reasonlines[] = get_string('recalculategrades_reason_excludedlower', 'attendance', $excludedlower);
        }
        $reasonlines[] = get_string('recalculategrades_reason_applynote', 'attendance');
        echo html_writer::div(implode(html_writer::empty_tag('br'), $reasonlines), 'alert alert-light border mb-3');
    }
}

if ($runsearch && $diag) {
    $lines = [
        get_string('recalculategradesdiag_title', 'attendance'),
        get_string('recalculategradesdiag_eligible', 'attendance', $diag->eligible_gradebook),
        get_string('recalculategradesdiag_withpolicy', 'attendance', $diag->with_policy_rows),
        get_string('recalculategradesdiag_sqlmismatch', 'attendance', $diag->sql_mismatch),
        get_string('recalculategradesdiag_sqlmismatch_conflict', 'attendance', $diag->sql_mismatch_conflict),
        get_string('recalculategradesdiag_sqlmismatch_noconflict', 'attendance', $diag->sql_mismatch_no_conflict),
        get_string('recalculategradesdiag_summaryhint', 'attendance'),
    ];
    echo html_writer::div(implode(html_writer::empty_tag('br'), $lines), 'alert alert-secondary');
}

if ($runsearch) {
    if ($mode === 'sql_seeded' && mod_attendance_recalculate_parse_seed_pairs($seedtext) === []) {
        echo html_writer::div(get_string('recalculategradesseedempty', 'attendance'), 'alert alert-warning');
    } else if (empty($candidates)) {
        echo html_writer::div(get_string('recalculategradesnothing', 'attendance'), 'alert alert-warning');
    } else {
        $applyurl = new moodle_url('/mod/attendance/recalculate.php');
        $applyform = html_writer::start_tag('form', ['method' => 'post', 'action' => $applyurl->out(false), 'class' => 'mb-3']);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'apply']);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => sesskey()]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'attendanceid', 'value' => $attendanceid]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'cmid', 'value' => $cmid]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'forensic_userid', 'value' => $forensic_userid]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'reject_lower', 'value' => $reject_lower]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'era', 'value' => $era]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'fixdate', 'value' => $fixdate]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'eps', 'value' => $eps]);
        $applyform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'mode', 'value' => $mode]);
        $applyform .= html_writer::tag('textarea', s($seedtext), [
            'name' => 'seed_text',
            'style' => 'display:none',
            'aria-hidden' => 'true',
        ]);
        $applyform .= html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('recalculategradesconfirm', 'attendance'),
            'class' => 'btn btn-danger',
        ]);
        $applyform .= html_writer::end_tag('form');
        echo $applyform;
    }

    if (!empty($candidates)) {
        $table = new html_table();
    $table->head = [
        get_string('course'),
        get_string('modulename', 'attendance'),
        get_string('participant', 'attendance'),
        get_string('recalculategrades_col_currentraw', 'attendance'),
    ];
    if ($mode !== 'sql_seeded') {
        $table->head[] = get_string('recalculategrades_col_sqldiag', 'attendance');
    }
    $table->head[] = get_string('recalculategrades_col_expectedapply', 'attendance');
    $table->head[] = get_string('recalculategrades_col_potentialrawdelta', 'attendance');
    $table->head[] = get_string('recalculategrades_col_lastwrite', 'attendance');
    if ($mode !== 'sql_seeded') {
        $table->head[] = get_string('recalculategrades_col_conflict', 'attendance');
    }
    if ($applydone) {
        $table->head[] = get_string('recalculategrades_col_rawafter', 'attendance');
        $table->head[] = get_string('recalculategrades_col_finalafter', 'attendance');
    }
    foreach ($candidates as $row) {
        $key = $row->pairkey;
        $before = $beforesnapshot[$key] ?? null;
        $cells = [];
        $cells[] = format_string($row->coursename);
        $cells[] = format_string($row->attendancename) . ' (#' . $row->attendanceid . ')';
        $cells[] = fullname((object) [
            'firstname' => $row->firstname,
            'lastname' => $row->lastname,
        ]) . ' (' . s($row->username) . ')';
        $curraw = $before->rawgrade ?? null;
        $cells[] = is_null($curraw) ? '-' : format_float((float) $curraw, 5);
        if ($mode !== 'sql_seeded') {
            $sqlexp = $row->raw_expected_sql ?? null;
            $cells[] = ($sqlexp === null) ? '-' : format_float((float) $sqlexp, 5);
        }
        $sumexp = $row->raw_expected_policy ?? null;
        $cells[] = ($sumexp === null) ? '-' : format_float((float) $sumexp, 5);
        if ($sumexp !== null && $curraw !== null) {
            $potential = (float) $sumexp - (float) $curraw;
            $cells[] = format_float($potential, 5);
        } else {
            $cells[] = '-';
        }
        $cells[] = userdate((int) $row->last_grade_write_ts);
        if ($mode !== 'sql_seeded') {
            $cells[] = !empty($row->has_conflict) ? get_string('yes', 'moodle') : get_string('no', 'moodle');
        }
        if ($applydone) {
            $after = $aftersnapshot[$key] ?? null;
            $cells[] = is_null($after->rawgrade ?? null) ? '-' : format_float((float) $after->rawgrade, 5);
            $cells[] = is_null($after->finalgrade ?? null) ? '-' : format_float((float) $after->finalgrade, 5);
        }
        $table->data[] = $cells;
    }
        echo html_writer::table($table);
        if ($mode !== 'sql_seeded') {
            echo html_writer::div(get_string('recalculategrades_tablehelp', 'attendance'), 'text-muted small mt-2');
        }
    }
}

echo $OUTPUT->footer();
