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
 * A scheduled task for scripted database integrations.
 *
 * @package    local_assessmentsettings - template
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_assessmentsettings\task;
use stdClass;

defined('MOODLE_INTERNAL') || die;
require_once($CFG->dirroot.'/local/extdb/classes/task/extdb.php');

/**
 * A scheduled task for scripted external database integrations.
 *
 * @copyright  2016 ROelmann
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assessmentsettings extends \core\task\scheduled_task {

    /**
     * Get a descriptive name for this task (shown to admins).
     *
     * @return string
     */
    public function get_name() {
        return get_string('pluginname', 'local_assessmentsettings');
    }

    /**
     * Run sync.
     */
    public function execute() {

        global $CFG, $DB;

        $externaldb = new \local_extdb\extdb();
        $name = $externaldb->get_name();

        $externaldbtype = $externaldb->get_config('dbtype');
        $externaldbhost = $externaldb->get_config('dbhost');
        $externaldbname = $externaldb->get_config('dbname');
        $externaldbencoding = $externaldb->get_config('dbencoding');
        $externaldbsetupsql = $externaldb->get_config('dbsetupsql');
        $externaldbsybasequoting = $externaldb->get_config('dbsybasequoting');
        $externaldbdebugdb = $externaldb->get_config('dbdebugdb');
        $externaldbuser = $externaldb->get_config('dbuser');
        $externaldbpassword = $externaldb->get_config('dbpass');
        $tableassm = get_string('assessmentstable', 'local_assessmentsettings');
        $tablegrades = get_string('stuassesstable', 'local_assessmentsettings');

        // Database connection and setup checks.
        // Check connection and label Db/Table in cron output for debugging if required.
        if (!$externaldbtype) {
            echo 'Database not defined.<br>';
            return 0;
        } else {
            echo 'Database: ' . $externaldbtype . '<br>';
        }
        // Check remote assessments table - usr_data_assessments.
        if (!$tableassm) {
            echo 'Assessments Table not defined.<br>';
            return 0;
        } else {
            echo 'Assessments Table: ' . $tableassm . '<br>';
        }
        // Check remote student grades table - usr_data_student_assessments.
        if (!$tablegrades) {
            echo 'Student Grades Table not defined.<br>';
            return 0;
        } else {
            echo 'Student Grades Table: ' . $tablegrades . '<br>';
        }
        echo 'Starting connection...<br>';

        // Report connection error if occurs.
        if (!$extdb = $externaldb->db_init(
            $externaldbtype,
            $externaldbhost,
            $externaldbuser,
            $externaldbpassword,
            $externaldbname)) {
            echo 'Error while communicating with external database <br>';
            return 1;
        }

        // Get duedate and gradingduedate from assign table where assignment has link code.
        /********************************************************
         * ARRAY (LINK CODE-> StdClass Object)                  *
         *     idnumber                                         *
         *     id                                               *
         *     name                                             *
         *     duedate (UNIX timestamp)                         *
         *     gradingduedate (UNIX timestamp)                  *
         ********************************************************/
        $sqldates = $DB->get_records_sql('SELECT a.id as id,m.id as cm, m.idnumber as linkcode,a.name,a.duedate,a.gradingduedate
            FROM {course_modules} m
            JOIN {assign} a ON m.instance = a.id
            JOIN {modules} mo ON m.module = mo.id
            WHERE m.idnumber IS NOT null AND m.idnumber != "" AND mo.name = "assign"');
        // Create reference array of assignment id and link code from mdl.
        $assignmdl = array();
        foreach ($sqldates as $sd) {
            $assignmdl[$sd->linkcode]['id'] = $sd->id;
            $assignmdl[$sd->linkcode]['cm'] = $sd->cm;
            $assignmdl[$sd->linkcode]['lc'] = $sd->linkcode;
            $assignmdl[$sd->linkcode]['name'] = $sd->name;
        }

        $assessments = array();
        // Read assessment data from external table.
        /********************************************************
         * ARRAY                                                *
         *     id                                               *
         *     mav_idnumber                                     *
         *     assessment_number                                *
         *     assessment_name                                  *
         *     assessment_type                                  *
         *     assessment_weight                                *
         *     assessment_idcode - THIS IS THE MAIN LINK ID     *
         *     assessment_markscheme_name                       *
         *     assessment_markscheme_code                       *
         *     assessment_duedate                               *
         *     assessment_feedbackdate                          *
         ********************************************************/
        $sql = $externaldb->db_get_sql($tableassm, array(), array(), true);
        if ($rs = $extdb->Execute($sql)) {
            if (!$rs->EOF) {
                while ($fields = $rs->FetchRow()) {
                    $fields = array_change_key_case($fields, CASE_LOWER);
                    $fields = $externaldb->db_decode($fields);
                    $assessments[] = $fields;
                }
            }
            $rs->Close();
        } else {
            // Report error if required.
            $extdb->Close();
            echo 'Error reading data from the external course table<br>';
            return 4;
        }
        // Create reference array of assignment id and link code from data warehouse.
        $assessext = array();
        foreach ($assessments as $am) {
            $assessext[$am['assessment_idcode']]['id'] = $am['id'];
            $assessext[$am['assessment_idcode']]['lc'] = $am['assessment_idcode'];
            $assessext[$am['assessment_idcode']]['name'] = $am['assessment_name'];
            $assessext[$am['assessment_idcode']]['dd'] = $am['assessment_duedate'];
            $assessext[$am['assessment_idcode']]['fb'] = $am['assessment_feedbackdate'];
            $assessext[$am['assessment_idcode']]['ms'] = $am['assessment_markscheme_code'];
        }

        /* Set assignment settings *
         * ----------------------- */
        foreach ($assignmdl as $k => $v) {
            // Error trap - ensure we have an assessment link id.
            if (!empty($assignmdl[$k]['id'])) {
                echo '<br>'.$assignmdl[$k]['id'].': '.$assignmdl[$k]['lc'].' - Assignment Settings<br>';

                // Set MarkingWorkflow. ON.
                if ($DB->get_field('assign', 'markingworkflow', array('id' => $assignmdl[$k]['id'])) == 0) {
                    $DB->set_field('assign', 'markingworkflow', 1, array('id' => $assignmdl[$k]['id']));
                    echo 'Marking Workflow set <strong>ON</strong> for '.$assignmdl[$k]['id'].'<br>';
                }
                /*
                 * BlindMarking. OFF. Commented out, so not currently set by default.
                 * if ($DB->get_field('assign', 'blindmarking', array('id'=>$assignmdl[$k]['id'])) == 1) {
                 *   $DB->set_field('assign', 'blindmarking', 0, array('id'=>$assignmdl[$k]['id']));
                 *   echo 'Blind Marking set <strong>OFF</strong> for '.$assignmdl[$k]['id'].'<br>';
                 * }
                 */

/*
                if (strpos($assignmdl[$k]['idnumber'], '19/20') > 1 ) {
                    $duedate = date('Y-m-d', $assignmdl[$k]['duedate']);
                    $mdlduetime = date('H:i:s', $assignmdl[$k]['duedate']);
                    $duetime = date('H:i:s', strtotime('3pm'));
                    if ($mdlduetime !== $duetime) {
                        $assignmdl[$k]['duedate'] = strtotime($duedate.' '.$gradingduetime)
                        $DB->set_field('assign', 'duedate', $assignmdl[$k]['duedate'], array('id' => $assignmdl[$k]['id']));
                    }
                }
*/

                /*
                 * Set submit button and submission statement OFF if physical hand in (ie requires coversheet)
                 * ON otherwise
                 */
                if ($DB->get_field('assign_plugin_config', 'value',
                    array('assignment' => $assignmdl[$k]['id'], 'plugin' => 'physical', 'name' => 'enabled')) == 1) {
                    // Require submit button. OFF.
                    if ($DB->get_field('assign', 'submissiondrafts', array('id' => $assignmdl[$k]['id'])) == 1) {
                        $DB->set_field('assign', 'submissiondrafts', 0, array('id' => $assignmdl[$k]['id']));
                        echo 'Submit Button set <strong>OFF</strong> for '.$assignmdl[$k]['id'].'<br>';
                    }
                    // Require submission statment. OFF.
                    if ($DB->get_field('assign', 'requiresubmissionstatement', array('id' => $assignmdl[$k]['id'])) == 1) {
                        $DB->set_field('assign', 'requiresubmissionstatement', 0, array('id' => $assignmdl[$k]['id']));
                        echo 'Require Submission Statement set <strong>OFF</strong> for '.$assignmdl[$k]['id'].'<br>';
                    }
                } else {
                    // Require submit button. ON.
                    if ($DB->get_field('assign', 'submissiondrafts', array('id' => $assignmdl[$k]['id'])) == 0) {
                        $DB->set_field('assign', 'submissiondrafts', 1, array('id' => $assignmdl[$k]['id']));
                        echo 'Submit Button set <strong>ON</strong> for '.$assignmdl[$k]['id'].'<br>';
                    }
                    // Require submission statment. ON.
                    if ($DB->get_field('assign', 'requiresubmissionstatement', array('id' => $assignmdl[$k]['id'])) == 0) {
                        $DB->set_field('assign', 'requiresubmissionstatement', 1, array('id' => $assignmdl[$k]['id']));
                        echo 'Require Submission Statement set <strong>ON</strong> for '.$assignmdl[$k]['id'].'<br>';
                    }
                }
                // Notify graders - standard. OFF.
                if ($DB->get_field('assign', 'sendnotifications', array('id' => $assignmdl[$k]['id'])) == 1) {
                    $DB->set_field('assign', 'sendnotifications', 0, array('id' => $assignmdl[$k]['id']));
                    echo 'Notify Graders - Standard set <strong>OFF</strong> for '.$assignmdl[$k]['id'].'<br>';
                }
                // Notify graders - late. OFF.
                if ($DB->get_field('assign', 'sendlatenotifications', array('id' => $assignmdl[$k]['id'])) == 0) {
                    $DB->set_field('assign', 'sendlatenotifications', 0, array('id' => $assignmdl[$k]['id']));
                    echo 'Notify Graders - Late set <strong>OFF</strong> for '.$assignmdl[$k]['id'].'<br>';
                }
                // Notify students. ON.
                // This is controlled by workflow - notification is not released until Workflow is marked as 'Released'.
                if ($DB->get_field('assign', 'sendstudentnotifications', array('id' => $assignmdl[$k]['id'])) == 0) {
                    $DB->set_field('assign', 'sendstudentnotifications', 1, array('id' => $assignmdl[$k]['id']));
                    echo 'Notify students set <strong>ON</strong> for '.$assignmdl[$k]['id'].'<br>';
                }
                // Attempts reopened. Never.
                // This is controlled by workflow - notification is not released until Workflow is marked as 'Released'.
                if ($DB->get_field('assign', 'attemptreopenmethod', array('id' => $assignmdl[$k]['id'])) !== 'none') {
                    $DB->set_field('assign', 'attemptreopenmethod', 'none', array('id' => $assignmdl[$k]['id']));
                    echo 'Attempts reopened set <strong>ON</strong> for '.$assignmdl[$k]['id'].'<br>';
                }

                // TurnItIn Settings.
                if ($DB->get_field('assign_plugin_config', 'value',
                    array('assignment' => $assignmdl[$k]['id'], 'plugin' => 'file', 'subtype' => 'assignsubmission', 'name' => 'enabled')) == 1) {

                    echo 'TurnItIn not set<br>';
                    // Use_TurnItIn. ON.
                    if ($DB->get_field('plagiarism_turnitin_config', 'name',
                        array('cm' => $assignmdl[$k]['cm'], 'name' => 'use_turnitin')) == 0) {
                        $DB->set_field('plagiarism_turnitin_config', 'value', 1,
                        array('cm' => $assignmdl[$k]['cm'], 'name' => 'use_turnitin'));
                        echo 'Use TurnItIn <strong>ON</strong> for '.$assignmdl[$k]['id'].'<br>';
                    }
                    /* TII Site default settings - no need for code
                     * --------------------------------------------
                     * Display reports to students. plagiarism_show_student_report NO.
                     * When submitted to TII. SUBMIT WHEN FIRST UPLOADED.
                     * Any file type. plagiarism_allow_non_or_submissions YES.
                     * Store papers. plagiarism_submitpapersto STANDARD REPOSITORY.
                     * Check against stored papers. plagiarism_compare_student_papers YES (locked).
                     * Check against internet. plagiarism_compare_internet YES (locked).
                     * Check against publications. plagiarism_compare_journals YES (locked).
                     * Report generation speed. plagiarism_report_gen GENERATE REPORTS IMMEDIATELY (RESUBMISSION ALLOWED).
                     * Exclude bibliography. plagiarism_exclude_biblio NO.
                     * Exclude quoted. plagiarism_exclude_quoted NO.
                     * Exclude small matches. plagiarism_exclude_matches NO.
                     */

                }

                // Set grading scale.
                $gradeitem = $DB->get_record('grade_items', array('idnumber' => $assignmdl[$k]['lc']));
                $gradetypemdl = $gradeitem->scaleid;
                $gradetypedw = $assessext[$assignmdl[$k]['lc']]['ms'];
                $gradetypeid = $DB->get_field('scale', 'id', array('name' => $gradetypedw));
                if ($gradetypemdl != $gradetypeid) {
                    if ($gradetypeid > 0 ) {
                        $DB->set_field('grade_items', 'scaleid', $gradetypeid, array('idnumber' => $assignmdl[$k]['lc']));
                        $DB->set_field('assign', 'grade', -$gradetypeid, array('id' => $assignmdl[$k]['id']));
                        $DB->set_field('grade_items', 'gradetype', 2, array('idnumber' => $assignmdl[$k]['lc']));
                    } else {
                        $DB->set_field('grade_items', 'scaleid', null, array('idnumber' => $assignmdl[$k]['lc']));
                        $DB->set_field('assign', 'grade', 100, array('id' => $assignmdl[$k]['id']));
                        $DB->set_field('grade_items', 'gradetype', 1, array('idnumber' => $assignmdl[$k]['lc']));
                    }
                    echo 'Grade scale set as '.$gradetypeid.' = '.$gradetypedw.'<br>';
                }
            }
        }

        // Free memory.
        $extdb->Close();
    }

}
