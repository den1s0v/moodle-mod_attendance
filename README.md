# ABOUT

The Attendance module is supported and maintained by Dan Marsden http://danmarsden.com

The Attendance module was previously developed by
    Dmitry Pupinin, Novosibirsk, Russia,
    Artem Andreev, Taganrog, Russia.

Branches
--------
The following git branches are supported:

| Moodle version        | Branch            |
|-----------------------|-------------------|
| Moodle 4.1            | MOODLE_401_STABLE |
| Moodle 4.2            | MOODLE_402_STABLE |
| Moodle 4.3            | MOODLE_403_STABLE |
| Moodle 4.4            | MOODLE_404_STABLE |

# PURPOSE
The Attendance module allows teachers to maintain a record of attendance, replacing or supplementing a paper-based attendance register.
It is primarily used in blended-learning environments where students are required to attend classes, lectures and tutorials and allows
the teacher to track and optionally provide a grade for the students attendance.

Sessions can be configured to allow students to record their own attendance and a range of different reports are available.

# DOCUMENTATION
https://docs.moodle.org/en/Attendance_activity

## Maintenance helper

Site administrators can run a protected recalculation helper from:

`/mod/attendance/recalculate.php`

Behavior:
- access is restricted to site admins only
- opening the URL does not run recalculation
- recalculation starts only after explicit confirmation (POST + sesskey)

**Candidate selection modes** (form on the same page):

| Mode | Purpose |
|------|---------|
| **Strict** | Only rows where the analytic SQL sees a timeslot conflict *and* the stored raw grade still disagrees with the policy after reconciliation via `mod_attendance_summary` (same code path as live `grade_update`). |
| **Fallback** | Any raw grade mismatch vs `mod_attendance_summary` (no conflict requirement). Use preview before apply; scope can be large. |
| **Explicit list** | One `attendanceid,userid` per line; only matching gradebook rows are recalculated. Use when diagnostics or external SQL already identified pairs. |

If the candidate list is empty, the page shows **diagnostic counts** (eligible gradebook rows, policy join, SQL mismatch vs conflict) to explain why **Strict** returned nothing—for example mismatches without a conflict flag (try **Fallback**).

Additional tools on the same page:

- **Course module ID (`cmid`)**: optional filter using the `id` from `/mod/attendance/manage.php?id=…` (overrides the numeric attendance instance id field when set).
- **Forensic user ID**: when an attendance scope (`cmid` or instance id) and a Moodle user id are set, the page prints a step-by-step checklist (grade flags, era filter, SQL aggregate vs summary, and recent `grade_grades_history`).
- **Reject lowering**: default excludes rows where a recalculation would *decrease* raw; disable only if you intentionally want to apply downward corrections too.

The preview table labels **current raw**, **expected raw after recalculation (summary/apply)**, **potential raw change (expected − current)**, and a separate **SQL diagnostic** column (strict/fallback modes).

## Business rules for overlapping group sessions

When one student has multiple group sessions in the same timeslot (same date/time and duration),
attendance grading treats them as one logical session.

Timeslot score selection rule:
- if all overlapping sessions have `autoassignstatus = 1`, use the maximum status grade
- if all overlapping sessions have `autoassignstatus = 0`, use the minimum status grade
- if overlapping sessions have mixed `autoassignstatus` values, use the maximum status grade

Historical consistency rule:
- summary and grade calculations are based on recorded `attendance_log` data
- current `groups_members` state is not used to re-filter historical taken-session grades
