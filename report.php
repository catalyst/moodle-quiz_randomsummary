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
 * This file defines the quiz random summary report class.
 *
 * @package   quiz_randomsummary
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/report/randomsummary/classes/form/randomsummary_form.php');
require_once($CFG->dirroot . '/mod/quiz/report/randomsummary/classes/randomsummary_options.php');
require_once($CFG->dirroot . '/mod/quiz/report/randomsummary/classes/randomsummary_table.php');

use mod_quiz\local\reports\attempts_report;
use quiz_randomsummary\quiz_randomsummary_options;
use quiz_randomsummary\quiz_randomsummary_table;

/**
 * Quiz report subclass for the randomsummary report.
 *
 * @copyright 1999 onwards Martin Dougiamas and others {@link http://moodle.com}
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class quiz_randomsummary_report extends attempts_report {

    /**
     * @var bool whether there are actually students to show, given the options.
     */
    protected $hasgroupstudents;

    /**
     * Display the random summary form.
     * @param stdClass $quiz record from quiz table.
     * @param stdClass $cm course module.
     * @param stdClass $course course record.
     * @return prints string
     */
    public function display($quiz, $cm, $course) {
        global $DB, $OUTPUT;

        list($currentgroup, $students, $groupstudents, $allowed)
            = $this->init('randomsummary', 'quiz_randomsummary\form\quiz_randomsummary_settings_form', $quiz, $cm, $course);
        $options = new quiz_randomsummary_options('randomsummary', $quiz, $cm, $course);

        if ($fromform = $this->form->get_data()) {
            $options->process_settings_from_form($fromform);

        } else {
            $options->process_settings_from_params();
        }

        $this->form->set_data($options->get_initial_form_data());

        if ($options->attempts == self::ALL_WITH) {
            // This option is only available to users who can access all groups in
            // groups mode, so setting allowed to empty (which means all quiz attempts
            // are accessible, is not a security porblem.
            $allowed = new \core\dml\sql_join();
        }
        $quizcontext = context_module::instance($cm->id);
        $sql = "SELECT DISTINCT qu.id AS questionusageid, q.*, qa.maxmark, slots.slot
                           FROM {question_usages} qu
                      LEFT JOIN {question_attempts} qa ON qa.questionusageid = qu.id
                      LEFT JOIN {quiz_slots} slots ON slots.slot = qa.slot
                      LEFT JOIN {question_set_references} qsr ON qsr.usingcontextid = qu.contextid AND qsr.itemid = slots.id
                      LEFT JOIN {question} q ON q.id = qa.questionid
                          WHERE qu.contextid = :quizcontextid AND qu.component = 'mod_quiz'
                            AND slots.quizid = :quizid AND qsr.filtercondition IS NOT NULL";
        // Using record set, mostly because of same qu.id may happen.
        $rs = $DB->get_recordset_sql($sql, ['quizcontextid' => $quizcontext->id, 'quizid' => $quiz->id]);
        $questions = [];
        if ($rs->valid()) {
            // The recordset contains some records.
            foreach ($rs as $record) {
                $questions[] = $record;
            }
            $rs->close();
        }
        $rs->close();
        // Sorting questions by slots.
        usort($questions, function($a, $b) {
            return $a->slot - $b->slot;
        });
        // Giving a unique identifier for every question usage for questions array.
        foreach ($questions as $key => $question) {
            $newkey = $question->questionusageid . ',' . $question->slot . ',' . $question->id;
            $questions[$newkey] = $question;
            unset($questions[$key]);
        }
        // Prepare for downloading, if applicable.
        $courseshortname = format_string($course->shortname, true,
                ['context' => context_course::instance($course->id)]);
        $table = new quiz_randomsummary_table($quiz, $this->context, $this->qmsubselect,
                $options, $groupstudents, $students, $questions, $options->get_url());
        $filename = quiz_report_download_filename(get_string('randomsummaryfilename', 'quiz_randomsummary'),
                $courseshortname, $quiz->name);
        $table->is_downloading($options->download, $filename,
                $courseshortname . ' ' . format_string($quiz->name, true));
        if ($table->is_downloading()) {
            raise_memory_limit(MEMORY_EXTRA);
        }

        $this->course = $course; // Hack to make this available in process_actions.
        $this->process_actions($quiz, $cm, $currentgroup, $groupstudents, $allowed, $options->get_url());

        // Start output.
        if (!$table->is_downloading()) {
            // Only print headers if not asked to download data.
            $this->print_header_and_tabs($cm, $course, $quiz, $this->mode);
        }

        if (groups_get_activity_groupmode($cm)) {
            // Groups are being used, so output the group selector if we are not downloading.
            if (!$table->is_downloading()) {
                groups_print_activity_menu($cm, $options->get_url());
            }
        }

        // Print information on the number of existing attempts.
        if (!$table->is_downloading()) {
            // Do not print notices when downloading.
            if ($strattemptnum = quiz_num_attempt_summary($quiz, $cm, true, $currentgroup)) {
                echo '<div class="quizattemptcounts">' . $strattemptnum . '</div>';
            }
        }

        $hasquestions = quiz_has_questions($quiz->id);
        if (!$table->is_downloading()) {
            if (!$hasquestions) {
                echo quiz_no_questions_message($quiz, $cm, $this->context);
            } else if (!$students) {
                echo $OUTPUT->notification(get_string('nostudentsyet'));
            } else if ($currentgroup && !$groupstudents) {
                echo $OUTPUT->notification(get_string('nostudentsingroup'));
            }

            // Print the display options.
            $this->form->display();
        }

        $hasstudents = $students && (!$currentgroup || $groupstudents);
        if ($hasquestions && ($hasstudents || $options->attempts == self::ALL_WITH)) {
            // Construct the SQL.
            $fields = $DB->sql_concat('u.id', "'#'", 'COALESCE(quiza.attempt, 0)') .
                    ' AS uniqueid, ';

            list($fields, $from, $where, $params) = $table->base_sql($allowed);

            $table->set_count_sql("SELECT COUNT(1) FROM $from WHERE $where", $params);

            // Test to see if there are any regraded attempts to be listed.
            $fields .= ", COALESCE((
                                SELECT MAX(qqr.regraded)
                                  FROM {quiz_overview_regrades} qqr
                                 WHERE qqr.questionusageid = quiza.uniqueid
                          ), -1) AS regraded";
            $table->set_sql($fields, $from, $where, $params);

            if (!$table->is_downloading()) {
                // Print information on the grading method.
                if ($strattempthighlight = quiz_report_highlighting_grading_method(
                        $quiz, $this->qmsubselect, $options->onlygraded)) {
                    echo '<div class="quizattemptcounts">' . $strattempthighlight . '</div>';
                }
            }

            // Define table columns.
            $columns = [];
            $headers = [];

            if (!$table->is_downloading() && $options->checkboxcolumn) {
                $columnname = 'checkbox';
                $columns[] = $columnname;
                $headers[] = $table->checkbox_col_header($columnname);
            }

            $this->add_user_columns($table, $columns, $headers);
            $this->add_state_column($columns, $headers);
            $this->add_time_columns($columns, $headers);

            $this->add_grade_columns($quiz, $options->usercanseegrades, $columns, $headers, false);

            foreach ($questions as $key => $question) {
                $qid = explode(',', $key)[2];
                // We need only one column per question id.
                if (!in_array('qsgrade' . $qid, $columns)) {
                    $columns[] = 'qsgrade' . $qid;
                    $header = get_string('qbrief', 'quiz', $question->slot);
                    if (!$table->is_downloading()) {
                        $header .= '<br />';
                    } else {
                        $header .= ' ';
                    }
                    $header .= '/ ' . $question->name;
                    $headers[] = $header;
                }
            }

            $this->set_up_table_columns($table, $columns, $headers, $this->get_base_url(), $options, false);
            $table->set_attribute('class', 'generaltable generalbox grades');

            $table->out($options->pagesize, true);
        }

        return true;
    }
}
