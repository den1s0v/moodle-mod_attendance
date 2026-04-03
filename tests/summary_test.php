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
 * Summary computation tests.
 *
 * @package    mod_attendance
 * @category   test
 * @copyright  2026
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace mod_attendance;

use advanced_testcase;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/attendance/classes/summary.php');
require_once($CFG->dirroot . '/mod/attendance/classes/structure.php');

/**
 * Tests for attendance summary with overlapping group sessions.
 *
 * @package    mod_attendance
 * @category   test
 * @group      mod_attendance
 */
final class summary_test extends advanced_testcase {
    /**
     * Build one attendance with two overlapping sessions for two groups.
     *
     * @param int $autoassigna
     * @param int $autoassignb
     * @return array
     */
    private function build_overlapping_fixture(int $autoassigna, int $autoassignb): array {
        global $DB;

        $course = $this->getDataGenerator()->create_course();
        $att = $this->getDataGenerator()->create_module('attendance', ['course' => $course->id], ['groupmode' => SEPARATEGROUPS]);
        $cm = get_coursemodule_from_instance('attendance', $att->id, $course->id, false, MUST_EXIST);
        $attstructure = new \mod_attendance_structure($att, $cm, $course);

        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $groupa = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $groupb = $this->getDataGenerator()->create_group(['courseid' => $course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupa->id, 'userid' => $student->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $groupb->id, 'userid' => $student->id]);

        $sessdate = time() - HOURSECS;
        $duration = HOURSECS;
        $common = [
            'sessdate' => $sessdate,
            'duration' => $duration,
            'description' => '',
            'descriptionformat' => 1,
            'descriptionitemid' => 0,
            'timemodified' => time(),
            'statusset' => 0,
            'absenteereport' => 1,
            'calendarevent' => 0,
        ];
        $sessiona = (object)($common + ['groupid' => $groupa->id, 'autoassignstatus' => $autoassigna]);
        $sessionb = (object)($common + ['groupid' => $groupb->id, 'autoassignstatus' => $autoassignb]);
        $attstructure->add_sessions([$sessiona, $sessionb]);

        $sessions = $DB->get_records('attendance_sessions', ['attendanceid' => $att->id], 'id ASC');
        $statuses = attendance_get_statuses($att->id, true, 0);
        $statusset = implode(',', array_keys((array)$statuses));

        $maxstatus = null;
        $minstatus = null;
        foreach ($statuses as $status) {
            if ($maxstatus === null || $status->grade > $maxstatus->grade) {
                $maxstatus = $status;
            }
            if ($minstatus === null || $status->grade < $minstatus->grade) {
                $minstatus = $status;
            }
        }

        foreach ($sessions as $session) {
            $session->lasttaken = time();
            $session->lasttakenby = $teacher->id;
            $DB->update_record('attendance_sessions', $session);
        }

        $sessionids = array_keys($sessions);
        // First overlapping session gets best status, second gets worst status.
        $DB->insert_record('attendance_log', (object)[
            'sessionid' => $sessionids[0],
            'studentid' => $student->id,
            'statusid' => $maxstatus->id,
            'statusset' => $statusset,
            'timetaken' => time(),
            'takenby' => $teacher->id,
            'remarks' => '',
        ]);
        $DB->insert_record('attendance_log', (object)[
            'sessionid' => $sessionids[1],
            'studentid' => $student->id,
            'statusid' => $minstatus->id,
            'statusset' => $statusset,
            'timetaken' => time(),
            'takenby' => $teacher->id,
            'remarks' => '',
        ]);

        return [$att, $student, $groupa, $groupb, $maxstatus, $minstatus];
    }

    /**
     * Overlapping sessions with autoassignstatus=1 should pick max grade.
     */
    public function test_overlapping_sessions_pick_max_when_autoassign_is_max(): void {
        $this->resetAfterTest(true);
        [$att, $student] = $this->build_overlapping_fixture(1, 1);

        $summary = new \mod_attendance_summary($att->id, [$student->id]);
        $usersummary = $summary->get_taken_sessions_summary_for($student->id);

        $this->assertEquals(1, (int)$usersummary->numtakensessions);
        $this->assertEqualsWithDelta(1.0, (float)$usersummary->takensessionspercentage, 0.000001);
    }

    /**
     * Overlapping sessions with autoassignstatus=0 should pick min grade.
     */
    public function test_overlapping_sessions_pick_min_when_autoassign_is_min(): void {
        $this->resetAfterTest(true);
        [$att, $student] = $this->build_overlapping_fixture(0, 0);

        $summary = new \mod_attendance_summary($att->id, [$student->id]);
        $usersummary = $summary->get_taken_sessions_summary_for($student->id);

        $this->assertEquals(1, (int)$usersummary->numtakensessions);
        $this->assertEqualsWithDelta(0.0, (float)$usersummary->takensessionspercentage, 0.000001);
    }

    /**
     * Mixed autoassignstatus values should pick max grade.
     */
    public function test_overlapping_sessions_pick_max_when_autoassign_is_mixed(): void {
        $this->resetAfterTest(true);
        [$att, $student] = $this->build_overlapping_fixture(1, 0);

        $summary = new \mod_attendance_summary($att->id, [$student->id]);
        $usersummary = $summary->get_taken_sessions_summary_for($student->id);

        $this->assertEquals(1, (int)$usersummary->numtakensessions);
        $this->assertEqualsWithDelta(1.0, (float)$usersummary->takensessionspercentage, 0.000001);
    }

    /**
     * Membership changes after class should not affect taken-session summary.
     */
    public function test_membership_change_after_class_does_not_change_taken_summary(): void {
        global $DB;
        $this->resetAfterTest(true);
        [$att, $student, $groupa] = $this->build_overlapping_fixture(1, 1);

        $before = new \mod_attendance_summary($att->id, [$student->id]);
        $beforedata = $before->get_taken_sessions_summary_for($student->id);

        $DB->delete_records('groups_members', ['groupid' => $groupa->id, 'userid' => $student->id]);

        $after = new \mod_attendance_summary($att->id, [$student->id]);
        $afterdata = $after->get_taken_sessions_summary_for($student->id);

        $this->assertEqualsWithDelta((float)$beforedata->takensessionspercentage, (float)$afterdata->takensessionspercentage, 0.000001);
        $this->assertEquals((int)$beforedata->numtakensessions, (int)$afterdata->numtakensessions);
    }
}

