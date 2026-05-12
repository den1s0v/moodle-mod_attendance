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
require_once($CFG->dirroot . '/mod/attendance/classes/summary.php');

$action = optional_param('action', 'preview', PARAM_ALPHA);
$attendanceid = optional_param('attendanceid', 0, PARAM_INT);
$era = optional_param('era', 'before_fix', PARAM_ALPHA);
$fixdate = optional_param('fixdate', date('Y-m-d'), PARAM_TEXT);
$eps = optional_param('eps', 0.00001, PARAM_FLOAT);
$mode = optional_param('mode', 'strict', PARAM_ALPHA);
$seedtext = optional_param('seed_text', '', PARAM_RAW);

require_login();

$systemcontext = context_system::instance();
if (!is_siteadmin()) {
    throw new required_capability_exception($systemcontext, 'moodle/site:config', 'nopermissions', '');
}

$validmodes = ['strict', 'fallback', 'sql_seeded'];
if (!in_array($mode, $validmodes, true)) {
    $mode = 'strict';
}

$baseurlparams = [
    'attendanceid' => $attendanceid,
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

    $gradebookbase = "FROM {grade_items} gi
        JOIN {grade_grades} gg ON gg.itemid = gi.id
        JOIN {attendance} a ON a.id = gi.iteminstance
        JOIN {course} c ON c.id = a.course
        JOIN {user} u ON u.id = gg.userid
        {$lwsql['sql']}
       WHERE gi.itemmodule = 'attendance'
         AND gi.grademax > 0
         AND gg.rawgrade IS NOT NULL
         AND COALESCE(gg.overridden, 0) = 0
         AND COALESCE(gg.excluded, 0) = 0
         AND COALESCE(gg.locked, 0) = 0
         {$erawhere}";

    $diag = new stdClass();
    $diag->eligible_gradebook = (int) $DB->count_records_sql("SELECT COUNT(1) $gradebookbase", $params);

    $joinpt = "SELECT COUNT(1)
                 $gradebookbase
                 JOIN (
                       {$ptsql['sql']}
                 ) pt ON pt.attendanceid = gi.iteminstance AND pt.userid = gg.userid";
    $diag->with_policy_rows = (int) $DB->count_records_sql($joinpt, $params);

    $mismatchparams = $params + ['eps' => $eps];
    $mismatchsql = "SELECT COUNT(1)
                     $gradebookbase
                     JOIN (
                           {$ptsql['sql']}
                     ) pt ON pt.attendanceid = gi.iteminstance AND pt.userid = gg.userid
                    WHERE pt.maxpoints_policy > 0
                      AND ABS(gg.rawgrade - ((pt.points_policy / NULLIF(pt.maxpoints_policy, 0)) * gi.grademax)) > :eps";
    $diag->sql_mismatch = (int) $DB->count_records_sql($mismatchsql, $mismatchparams);

    $strictsql = "SELECT COUNT(1)
                    $gradebookbase
                    JOIN (
                          {$ptsql['sql']}
                    ) pt ON pt.attendanceid = gi.iteminstance AND pt.userid = gg.userid
                   WHERE pt.maxpoints_policy > 0
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

$diag = null;
if ($mode === 'sql_seeded') {
    $seedpairs = mod_attendance_recalculate_parse_seed_pairs($seedtext);
    $candidates = mod_attendance_recalculate_seed_candidates($DB, $seedpairs, $era, $fixts);
    $candidates = mod_attendance_recalculate_enrich_expected_from_summary($candidates);
    $candidates = mod_attendance_recalculate_apply_display_expected($candidates);
} else {
    $requireconflict = ($mode === 'strict');
    $candidates = mod_attendance_recalculate_query_candidates($DB, $attendanceid, $era, $fixts, $eps, $requireconflict);
    $candidates = mod_attendance_recalculate_enrich_expected_from_summary($candidates);
    $candidates = mod_attendance_recalculate_filter_after_summary($candidates, $eps, $requireconflict);
    $candidates = mod_attendance_recalculate_apply_display_expected($candidates);
}

if (empty($candidates) && $mode !== 'sql_seeded') {
    $diag = mod_attendance_recalculate_diagnostic_counts($DB, $attendanceid, $era, $fixts, $eps);
}

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
            attendance_update_users_grades_by_id((int) $attid, (int) $attendance->grade, $userids);
            $updatedactivities++;
            $updatedusers += count($userids);
        }
        $message = get_string('recalculategradesdone', 'attendance',
            (object) ['activities' => $updatedactivities, 'users' => $updatedusers]);
        echo $OUTPUT->notification($message, \core\output\notification::NOTIFY_SUCCESS);
        $applydone = true;
        $aftersnapshot = mod_attendance_get_grade_snapshot($DB, $candidates);
    }
}

$summary = get_string('recalculategradessummarydetailed2', 'attendance',
    (object) [
        'activities' => $numattendance,
        'users' => $numusers,
        'fixdate' => $fixdate,
        'era' => $era,
        'mode' => $mode,
        'eps' => $eps,
        'attendanceid' => $attendanceid ?: get_string('recalculategradesallinstances', 'attendance'),
    ]);
echo html_writer::div($summary, 'alert alert-info');

// Filter form: POST so seed textarea is reliable.
$filterurl = new moodle_url('/mod/attendance/recalculate.php');
$filterform = html_writer::start_tag('form', ['method' => 'post', 'action' => $filterurl->out(false), 'class' => 'mb-3']);
$filterform .= html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action', 'value' => 'preview']);
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
$filterform .= html_writer::empty_tag('input', [
    'type' => 'submit',
    'value' => get_string('recalculategradespreview', 'attendance'),
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

if ($diag) {
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
        get_string('recalculategradesrawbefore', 'attendance'),
        get_string('recalculategradesexpectedsummary', 'attendance'),
        get_string('recalculategradesdelta', 'attendance'),
        get_string('recalculategradeslastwrite', 'attendance'),
    ];
    if ($mode !== 'sql_seeded') {
        array_splice($table->head, 4, 0, [get_string('recalculategradesexpectedsql', 'attendance')]);
    }
    if ($mode !== 'sql_seeded') {
        $table->head[] = get_string('recalculategradesconflict', 'attendance');
    }
    if ($applydone) {
        $table->head[] = get_string('recalculategradesrawafter', 'attendance');
        $table->head[] = get_string('recalculategradesfinalafter', 'attendance');
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
        $cells[] = is_null($before->rawgrade ?? null) ? '-' : format_float((float) $before->rawgrade, 5);
        if ($mode !== 'sql_seeded') {
            $sqlexp = $row->raw_expected_sql ?? null;
            $cells[] = ($sqlexp === null) ? '-' : format_float((float) $sqlexp, 5);
        }
        $sumexp = $row->raw_expected_policy ?? null;
        $cells[] = ($sumexp === null) ? '-' : format_float((float) $sumexp, 5);
        $delta = $row->delta_raw_vs_policy ?? null;
        $cells[] = ($delta === null) ? '-' : format_float((float) $delta, 5);
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
        echo html_writer::div(get_string('recalculategradesexpectedhelp', 'attendance'), 'text-muted small mt-2');
    }
}

echo $OUTPUT->footer();
