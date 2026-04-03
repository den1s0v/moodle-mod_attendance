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
 * Class that computes summary of users points
 *
 * @package   mod_attendance
 * @copyright  2016 Antonio Carlos Mariani http://antonio.c.mariani@gmail.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();
require_once($CFG->dirroot . '/mod/attendance/locallib.php');

/**
 * Class that computes summary of users points
 *
 * @package   mod_attendance
 * @copyright  2016 Antonio Carlos Mariani http://antonio.c.mariani@gmail.com
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_attendance_summary {
    /** @var int attendance instance identifier */
    private $attendanceid;

    /** @var stdclass course course data*/
    private $course;

    /** @var int groupmode*/
    private $groupmode;

    /** @var array userspoints (userid, numtakensessions, points, maxpoints) */
    private $userspoints;

    /** @var array pointsbygroup (groupid, numsessions, maxpoints) */
    private $maxpointsbygroupsessions;

    /** @var array userstakensessionsbyacronym */
    private $userstakensessionsbyacronym;

    /**
     * Initializes the class
     *
     * @param int $attendanceid instance identifier
     * @param array $userids user instances identifier
     * @param int $startdate Attendance sessions startdate
     * @param int $enddate Attendance sessions enddate
     */
    public function __construct($attendanceid, $userids = [], $startdate = '', $enddate = '') {
        $this->attendanceid = $attendanceid;

        $this->compute_users_points($userids, $startdate, $enddate);
        $this->compute_users_taken_sessions_by_acronym($userids, $startdate, $enddate);
    }

    /**
     * Returns true if the user has some session with points
     *
     * @param int $userid User instance id
     *
     * @return boolean
     */
    public function has_taken_sessions($userid) {
        return isset($this->userspoints[$userid]);
    }

    /**
     * Returns true if the corresponding attendance instance is currently configure to work with grades (points)
     *
     * @return boolean
     */
    public function with_groups() {
        return $this->groupmode > 0;
    }

    /**
     * Returns the groupmode of the corresponding attendance instance
     *
     * @return int
     */
    public function get_groupmode() {
        return $this->groupmode;
    }

    /**
     * Returns the percentages of each user related to the taken sessions
     *
     * @return array
     */
    public function get_user_taken_sessions_percentages() {
        $percentages = [];

        foreach ($this->userspoints as $userid => $userpoints) {
            $percentages[$userid] = attendance_calc_fraction($userpoints->points, $userpoints->maxpoints);
        }

        return $percentages;
    }

    /**
     * Returns a summary of the points assigned to the user related to the taken sessions
     *
     * @param int $userid User instance id
     *
     * @return stdClass
     */
    public function get_taken_sessions_summary_for($userid) {
        $usersummary = new stdClass();
        if ($this->has_taken_sessions($userid)) {
            $usersummary->numtakensessions = $this->userspoints[$userid]->numtakensessions;
            $usersummary->takensessionspoints = $this->userspoints[$userid]->points;
            $usersummary->takensessionsmaxpoints = $this->userspoints[$userid]->maxpoints;
        } else {
            $usersummary->numtakensessions = 0;
            $usersummary->takensessionspoints = 0;
            $usersummary->takensessionsmaxpoints = 0;
        }
        $usersummary->takensessionspercentage = attendance_calc_fraction(
            $usersummary->takensessionspoints,
            $usersummary->takensessionsmaxpoints
        );
        if (isset($this->userstakensessionsbyacronym[$userid])) {
            $usersummary->userstakensessionsbyacronym = $this->userstakensessionsbyacronym[$userid];
        } else {
            $usersummary->userstakensessionsbyacronym = [];
        }

        $usersummary->pointssessionscompleted = format_float($usersummary->takensessionspoints, 1, true, true) . ' / ' .
            format_float($usersummary->takensessionsmaxpoints, 1, true, true);

        $usersummary->percentagesessionscompleted = format_float($usersummary->takensessionspercentage * 100) . '%';

        return $usersummary;
    }

    /**
     * Returns a summary of the points assigned to the user, both related to taken sessions and related to all sessions
     *
     * @param int $userid User instance id
     *
     * @return array
     */
    public function get_all_sessions_summary_for($userid) {
        $usersummary = $this->get_taken_sessions_summary_for($userid);

        if (!isset($this->maxpointsbygroupsessions)) {
            $this->compute_maxpoints_by_group_session();
        }

        $usersummary->numallsessions = $this->maxpointsbygroupsessions[0]->numsessions;
        $usersummary->allsessionsmaxpoints = $this->maxpointsbygroupsessions[0]->maxpoints;

        if ($this->with_groups()) {
            $groupids = array_keys(groups_get_all_groups($this->course->id, $userid));
            foreach ($groupids as $gid) {
                if (isset($this->maxpointsbygroupsessions[$gid])) {
                    $usersummary->numallsessions += $this->maxpointsbygroupsessions[$gid]->numsessions;
                    $usersummary->allsessionsmaxpoints += $this->maxpointsbygroupsessions[$gid]->maxpoints;
                }
            }
        }
        $usersummary->allsessionspercentage = attendance_calc_fraction(
            $usersummary->takensessionspoints,
            $usersummary->allsessionsmaxpoints
        );
        $usersummary->allsessionspercentage = format_float($usersummary->allsessionspercentage * 100) . '%';

        $deltapoints = $usersummary->allsessionsmaxpoints - $usersummary->takensessionsmaxpoints;

        $usersummary->maxpossiblepoints = $usersummary->takensessionspoints + $deltapoints;
        $usersummary->maxpossiblepoints = format_float($usersummary->maxpossiblepoints, 1, true, true) . ' / ' .
            format_float($usersummary->allsessionsmaxpoints, 1, true, true);

        $usersummary->maxpossiblepercentage = attendance_calc_fraction(
            ($usersummary->takensessionspoints + $deltapoints),
            $usersummary->allsessionsmaxpoints
        );
        $usersummary->maxpossiblepercentage = format_float($usersummary->maxpossiblepercentage * 100) . '%';

        $usersummary->pointssessionscompleted = format_float($usersummary->takensessionspoints, 1, true, true) . ' / ' .
            format_float($usersummary->takensessionsmaxpoints, 1, true, true);

        $usersummary->percentagesessionscompleted = format_float($usersummary->takensessionspercentage * 100) . '%';

        $usersummary->pointsallsessions = format_float($usersummary->takensessionspoints, 1, true, true) . ' / ' .
            format_float($usersummary->allsessionsmaxpoints, 1, true, true);

        return $usersummary;
    }

    /**
     * Computes the summary of points for the users that have some taken session
     *
     * @param array $userids user instances identifier
     * @param int $startdate Attendance sessions startdate
     * @param int $enddate Attendance sessions enddate
     * @return  (userid, numtakensessions, points, maxpoints)
     */
    private function compute_users_points($userids = [], $startdate = '', $enddate = '') {
        global $DB;

        [$this->course, $cm] = get_course_and_cm_from_instance($this->attendanceid, 'attendance');
        $this->groupmode = $cm->effectivegroupmode;

        $params = [
            'attid'      => $this->attendanceid,
            'attid2'     => $this->attendanceid,
            'cstartdate' => $this->course->startdate,
            ];

        $where = '';
        if (!empty($userids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $where .= ' AND atl.studentid ' . $insql;
            $params = array_merge($params, $inparams);
        }
        if (!empty($startdate)) {
            $where .= ' AND ats.sessdate >= :startdate';
            $params['startdate'] = $startdate;
        }
        if (!empty($enddate)) {
            $where .= ' AND ats.sessdate < :enddate ';
            $params['enddate'] = $enddate;
        }

        // Group sessions by student + timeslot and collapse parallel groups into one logical session.
        // When all sessions in a timeslot have autoassignstatus=0, the "best" outcome is the minimum grade.
        // Otherwise (all=1 or mixed values), the "best" outcome is the maximum grade.
        $sql = "SELECT slot.userid,
                       COUNT(1) AS numtakensessions,
                       SUM(slot.slotgrade) AS points,
                       SUM(slot.slotmaxgrade) AS maxpoints
                  FROM (
                        SELECT atl.studentid AS userid,
                               ats.sessdate,
                               ats.duration,
                               CASE
                                   WHEN MIN(ats.autoassignstatus) = 0 AND MAX(ats.autoassignstatus) = 0
                                       THEN MIN(stg.grade)
                                   ELSE MAX(stg.grade)
                               END AS slotgrade,
                               MAX(stm.maxgrade) AS slotmaxgrade
                          FROM {attendance_sessions} ats
                          JOIN {attendance_log} atl ON (atl.sessionid = ats.id)
                          JOIN {attendance_statuses} stg
                            ON (stg.id = atl.statusid AND stg.deleted = 0 AND stg.visible = 1)
                          JOIN (SELECT setnumber, MAX(grade) AS maxgrade
                                  FROM {attendance_statuses}
                                 WHERE attendanceid = :attid2
                                   AND deleted = 0
                                   AND visible = 1
                              GROUP BY setnumber) stm
                            ON (stm.setnumber = ats.statusset)
                         WHERE ats.attendanceid = :attid
                           AND ats.sessdate >= :cstartdate
                           AND ats.lasttaken != 0
                           {$where}
                      GROUP BY atl.studentid, ats.sessdate, ats.duration
                 ) slot
              GROUP BY slot.userid";
        $this->userspoints = $DB->get_records_sql($sql, $params);
    }

    /**
     * Computes the summary of taken sessions by acronym
     *
     * @param array $userids user instances identifier
     * @param int $startdate Attendance sessions startdate
     * @param int $enddate Attendance sessions enddate
     * @return  null
     */
    private function compute_users_taken_sessions_by_acronym($userids = [], $startdate = '', $enddate = '') {
        global $DB;

        [$this->course, $cm] = get_course_and_cm_from_instance($this->attendanceid, 'attendance');
        $this->groupmode = $cm->effectivegroupmode;

        $params = [
            'attid'      => $this->attendanceid,
            'cstartdate' => $this->course->startdate,
            ];

        $where = '';
        if (!empty($userids)) {
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $where .= ' AND atl.studentid ' . $insql;
            $params = array_merge($params, $inparams);
        }
        if (!empty($startdate)) {
            $where .= ' AND ats.sessdate >= :startdate';
            $params['startdate'] = $startdate;
        }
        if (!empty($enddate)) {
            $where .= ' AND ats.sessdate < :enddate ';
            $params['enddate'] = $enddate;
        }

        // Build acronym summary from timeslot-collapsed results so parallel group sessions count once.
        $sql = "SELECT atl.studentid AS userid,
                       ats.sessdate,
                       ats.duration,
                       ats.autoassignstatus,
                       sts.setnumber,
                       sts.acronym,
                       sts.grade
                  FROM {attendance_sessions} ats
                  JOIN {attendance_log} atl ON (atl.sessionid = ats.id)
                  JOIN {attendance_statuses} sts
                    ON (sts.attendanceid = ats.attendanceid AND
                        sts.id = atl.statusid AND
                        sts.deleted = 0 AND sts.visible = 1)
                 WHERE ats.attendanceid = :attid
                   AND ats.sessdate >= :cstartdate
                   AND ats.lasttaken != 0
                   {$where}
              ORDER BY atl.studentid, ats.sessdate, ats.duration";

        $this->userstakensessionsbyacronym = [];
        $records = $DB->get_recordset_sql($sql, $params);
        $slots = [];
        foreach ($records as $rec) {
            $slotkey = $rec->userid . ':' . $rec->sessdate . ':' . $rec->duration;
            if (!isset($slots[$slotkey])) {
                $slots[$slotkey] = (object)[
                    'userid' => $rec->userid,
                    'minauto' => (int)$rec->autoassignstatus,
                    'maxauto' => (int)$rec->autoassignstatus,
                    'mingrade' => (float)$rec->grade,
                    'maxgrade' => (float)$rec->grade,
                    'minsetnumber' => (int)$rec->setnumber,
                    'maxsetnumber' => (int)$rec->setnumber,
                    'minacronym' => $rec->acronym,
                    'maxacronym' => $rec->acronym,
                ];
                continue;
            }

            $slot = $slots[$slotkey];
            $autoassign = (int)$rec->autoassignstatus;
            $grade = (float)$rec->grade;
            if ($autoassign < $slot->minauto) {
                $slot->minauto = $autoassign;
            }
            if ($autoassign > $slot->maxauto) {
                $slot->maxauto = $autoassign;
            }
            if ($grade < $slot->mingrade) {
                $slot->mingrade = $grade;
                $slot->minsetnumber = (int)$rec->setnumber;
                $slot->minacronym = $rec->acronym;
            }
            if ($grade > $slot->maxgrade) {
                $slot->maxgrade = $grade;
                $slot->maxsetnumber = (int)$rec->setnumber;
                $slot->maxacronym = $rec->acronym;
            }
            $slots[$slotkey] = $slot;
        }
        $records->close();

        foreach ($slots as $slot) {
            $usemin = ($slot->minauto === 0 && $slot->maxauto === 0);
            $setnumber = $usemin ? $slot->minsetnumber : $slot->maxsetnumber;
            $acronym = $usemin ? $slot->minacronym : $slot->maxacronym;
            if (empty($this->userstakensessionsbyacronym[$slot->userid][$setnumber][$acronym])) {
                $this->userstakensessionsbyacronym[$slot->userid][$setnumber][$acronym] = 0;
            }
            $this->userstakensessionsbyacronym[$slot->userid][$setnumber][$acronym]++;
        }
    }

    /**
     * Computes and store the maximum points possible for each group session
     *
     * @return null
     */
    private function compute_maxpoints_by_group_session() {
        global $DB;

        $params = [
            'attid'      => $this->attendanceid,
            'attid2'     => $this->attendanceid,
            'cstartdate' => $this->course->startdate,
            ];

        $where = '';
        if (!$this->with_groups()) {
            $where = 'AND sess.groupid = 0';
        }

        $sql = "SELECT sess.groupid, COUNT(*) AS numsessions, SUM(stamax.maxgrade) AS maxpoints
                  FROM {attendance_sessions} sess
                  JOIN (SELECT setnumber, MAX(grade) AS maxgrade
                                             FROM {attendance_statuses}
                                            WHERE attendanceid = :attid2
                                              AND deleted = 0
                                              AND visible = 1
                                           GROUP BY setnumber) stamax
                    ON (stamax.setnumber = sess.statusset)
                 WHERE sess.attendanceid = :attid
                   AND sess.sessdate >= :cstartdate
                   {$where}
              GROUP BY sess.groupid";
        $this->maxpointsbygroupsessions = $DB->get_records_sql($sql, $params);

        if (!isset($this->maxpointsbygroupsessions[0])) {
            $gpoints = new stdClass();
            $gpoints->numsessions = 0;
            $gpoints->maxpoints = 0;
            $this->maxpointsbygroupsessions[0] = $gpoints;
        }
    }
}
