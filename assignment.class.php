<?php // $Id$
require_once($CFG->libdir.'/formslib.php');

// Statuses for locking setting.
define('ASSIGNMENT_MAHARA_SETTING_DONTLOCK', 0);
define('ASSIGNMENT_MAHARA_SETTING_KEEPLOCKED', 1);
define('ASSIGNMENT_MAHARA_SETTING_UNLOCK', 2);

/**
 * Extend the base assignment class for mahara portfolio assignments
 *
 */
class assignment_mahara extends assignment_base {

    // These constants indicate our knowledge of the page's status *in Mahara*.
    // We have not changed the page's status on the Mahara side.
    const MAHARA_STATUS_NORMAL = 'selected';

    // We've locked the page in Mahara
    // (And, if it's a non-upgraded Mahara, we've been issued an access token)
    const MAHARA_STATUS_LOCKED = 'submitted';

    // We've locked the page in Mahara, and subsequently unlocked it.
    // (If we're dealing with a non-upgraded Mahara, the access token probably still exists)
    const MAHARA_STATUS_RELEASED = 'released';
    private $remotehost;

    function assignment_mahara($cmid='staticonly', $assignment=NULL, $cm=NULL, $course=NULL) {
        parent::assignment_base($cmid, $assignment, $cm, $course);
        $this->type = 'mahara';
    }

    /**
     * Prints the full detailed view of the submission. Used for grading
     * as well as for the student's own creation of submissions.
     * @see assignment_base::view()
     */
    function view() {

        global $CFG, $USER;

        $saved = optional_param('saved', 0, PARAM_BOOL);

        $context = get_context_instance(CONTEXT_MODULE, $this->cm->id);
        require_capability('mod/assignment:view', $context);

        $submission = $this->get_submission();

        $editable = has_capability('mod/assignment:submit', $context) && $this->isopen()
            && (!$submission->data2 || $this->assignment->resubmit || !$submission->timemarked);

        if ($editable) {
            $viewid = optional_param('view', null, PARAM_INTEGER);
            $iscoll = optional_param('iscoll', false, PARAM_BOOL);
            if ($viewid !== null && $this->submit_view($viewid, $iscoll)) {
                //TODO fix log actions - needs db upgrade
                $submission = $this->get_submission();
                add_to_log($this->course->id, 'assignment', 'upload',
                           'view.php?a='.$this->assignment->id, $this->assignment->id, $this->cm->id);
                $this->email_teachers($submission);
                //redirect to get updated submission date and word count
                redirect('view.php?id='.$this->cm->id.'&saved=1');
            }
        }

        add_to_log($this->course->id, "assignment", "view", "view.php?id={$this->cm->id}", $this->assignment->id, $this->cm->id);

        $this->view_header();

        $this->view_intro();


        print_box_start('generalbox boxwidthnormal boxaligncenter', 'dates');
        $this->view_dates();
        print_box_end();


        print_box_start('generalbox boxwidthnormal boxaligncenter centerpara');

        if ($submission->data2) {
            if ($saved) {
                notify(get_string('submissionsaved', 'assignment'), 'notifysuccess');
            }
            $data = unserialize($submission->data2);
            echo '<div><strong>' . get_string('selectedview', 'assignment_mahara') . ': </strong>'
              . '<a href="' . $CFG->wwwroot . '/auth/mnet/jump.php?hostid=' . $this->remote_mnet_host_id()
              . '&amp;wantsurl=' . urlencode($data['mneturl']) . '">'
              . $data['title'] . '</a>';
            if ($editable) {
                echo ' <small><a href="?id=' . $this->cm->id. '&view=0&$iscoll=0">(deselect?)</a></small>';
            }
            echo '</div>';
        }

        if ($submission->data2 && $editable) {
            echo '<hr>';
        }

        if ($editable) {

            $query = optional_param('q', null, PARAM_TEXT);
            list($error, $views) = $this->get_views($query);

            if ($error) {
                echo $error;
            } else {
                $this->remotehost = get_record('mnet_host', 'id', $this->remote_mnet_host_id());
                $this->remotehost->jumpurl = $CFG->wwwroot . '/auth/mnet/jump.php?hostid=' . $this->remotehost->id;
                echo '<form><div>' . get_string('selectmaharaview', 'assignment_mahara', $this->remotehost) . '</div><br/>'
                  . '<input type="hidden" name="id" value="' . $this->cm->id . '">'
                  . '<label for="q">' . get_string('search') . ':</label> <input type="text" name="q" value="' . $query . '">'
                  . '</form>';
                if ($views['count'] < 1 && $views['collections']['count'] < 1) {
                    echo get_string('noviewsfound', 'assignment_mahara', $this->remotehost->name);
                } else {
                    echo '<h4>' . $this->remotehost->name . ': ' . get_string('viewsby', 'assignment_mahara', $views['displayname']) . '</h4>';
                    echo '<table class="formtable"><thead>'
                      . '<tr><th>' . get_string('preview', 'assignment_mahara') . '</th>'
                      . '<th>' . get_string('submit') . '</th></tr>'
                      . '<tr><td style="padding:0 5px 0 5px;">(' . get_string('clicktopreview', 'assignment_mahara') . ')</td>'
                      . '<td style="padding:0 5px 0 5px;">(' . get_string('clicktoselect', 'assignment_mahara') . ')</td></tr>'
                      . '</thead><tbody>';
                    // Print views
                    foreach ($views['data'] as &$v) {
                        $windowname = 'view' . $v['id'];
                        $viewurl = $this->remotehost->jumpurl . '&wantsurl=' . urlencode($v['url']);
                        $js = "this.target='$windowname';window.open('" . $viewurl . "', '$windowname', 'resizable,scrollbars,width=920,height=600');return false;";
                        echo '<tr><td><a href="' . $viewurl . '" target="_blank" onclick="' . $js . '">'
                          . '<img align="top" src="'.$CFG->pixpath.'/f/html.gif" height="16" width="16" alt="html" /> ' . $v['title'] . '</a></td>'
                          . '<td><a href="?id=' . $this->cm->id. '&view=' . $v['id'] . '&iscoll=0">' . get_string('submit') . '</a></td></tr>';
                    }
                    // Print collections
                    foreach ($views['collections']['data'] as &$v) {
                        $windowname = 'view' . $v['id'];
                        $viewurl = $this->remotehost->jumpurl . '&wantsurl=' . urlencode($v['url']);
                        $js = "this.target='$windowname';window.open('" . $viewurl . "', '$windowname', 'resizable,scrollbars,width=920,height=600');return false;";
                        echo '<tr><td><a href="' . $viewurl . '" target="_blank" onclick="' . $js . '">'
                          . '<img align="top" src="'.$CFG->pixpath.'/f/folder.gif" height="16" width="16" alt="html" /> ' . $v['name'] . ' (';
                        if ($v['numviews'] == 1) {
                            echo get_string('1viewincollection', 'assignment_mahara');
                        } else {
                            echo get_string('numviewsincollection', 'assignment_mahara', $v['numviews']);
                        }
                        echo ')</a></td><td><a href="?id=' . $this->cm->id. '&view=' . $v['id'] . '&iscoll=1">' . get_string('submit') . '</a></td></tr>';
                    }
                    echo '</tbody></table>';
                }
            }

        }
        print_box_end();

        $this->view_feedback();

        $this->view_footer();
    }

    /*
     * Display the assignment dates
     */
    function view_dates() {
        global $USER, $CFG;

        if (!$this->assignment->timeavailable && !$this->assignment->timedue) {
            return;
        }

        echo '<table>';
        if ($this->assignment->timeavailable) {
            echo '<tr><td class="c0">'.get_string('availabledate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timeavailable).'</td></tr>';
        }
        if ($this->assignment->timedue) {
            echo '<tr><td class="c0">'.get_string('duedate','assignment').':</td>';
            echo '    <td class="c1">'.userdate($this->assignment->timedue).'</td></tr>';
        }
        $submission = $this->get_submission($USER->id);
        if ($submission) {
            echo '<tr><td class="c0">'.get_string('lastedited').':</td>';
            echo '    <td class="c1">'.userdate($submission->timemodified).'</td></tr>';
        }
        echo '</table>';
    }

    /**
     * Prints a summary of the student's submission, e.g. for the gradebook
     * @param int $userid
     * @param string $return
     * @return string
     */
    function print_student_answer($userid, $return=false){
        global $CFG;
        if (!$submission = $this->get_submission($userid)) {
            return '';
        }
        $data = unserialize($submission->data2);
        return '<div><a href="' . $CFG->wwwroot . '/auth/mnet/jump.php?hostid=' . $this->remote_mnet_host_id()
          . '&amp;wantsurl=' . urlencode($data['mneturl']) . '">'
          . $data['title'] . '</a></div>';
    }

    function print_user_files($userid, $return=false) {
        echo $this->print_student_answer($userid, $return);
    }

    function setup_elements(&$mform) {
        global $CFG, $COURSE;

        // Get Mahara hosts we are doing SSO with
        $sql = "
             SELECT DISTINCT
                 h.id,
                 h.name
             FROM
                 {$CFG->prefix}mnet_host h,
                 {$CFG->prefix}mnet_application a,
                 {$CFG->prefix}mnet_host2service h2s_IDP,
                 {$CFG->prefix}mnet_service s_IDP,
                 {$CFG->prefix}mnet_host2service h2s_SP,
                 {$CFG->prefix}mnet_service s_SP
             WHERE
                 h.id != '{$CFG->mnet_localhost_id}' AND
                 h.id = h2s_IDP.hostid AND
                 h.deleted = 0 AND
                 h.applicationid = a.id AND
                 h2s_IDP.serviceid = s_IDP.id AND
                 s_IDP.name = 'sso_idp' AND
                 h2s_IDP.publish = '1' AND
                 h.id = h2s_SP.hostid AND
                 h2s_SP.serviceid = s_SP.id AND
                 s_SP.name = 'sso_idp' AND
                 h2s_SP.publish = '1' AND
                 a.name = 'mahara'
             ORDER BY
                 h.name";

        if ($hosts = get_records_sql($sql)) {
            foreach ($hosts as &$h) {
                $h = $h->name;
            }
            $mform->addElement('select', 'var2', get_string("site"), $hosts);
            $mform->setHelpButton('var2', array('site', get_string('site'), 'assignment_mahara'));
            $mform->setDefault('var2', key($hosts));
        }
        else {
            $mform->addElement('static', '', '', get_string('nomaharahostsfound', 'assignment'));
        }

        $ynoptions = array( 0 => get_string('no'), 1 => get_string('yes'));

        $mform->addElement('select', 'emailteachers', get_string("emailteachers", "assignment"), $ynoptions);
        $mform->setHelpButton('emailteachers', array('emailteachers', get_string('emailteachers', 'assignment'), 'assignment'));
        $mform->setDefault('emailteachers', 0);

        $mform->addElement('select', 'resubmit', get_string("allowresubmit", "assignment"), $ynoptions);
        $mform->setHelpButton('resubmit', array('resubmit', get_string('allowresubmit', 'assignment'), 'assignment'));
        $mform->setDefault('resubmit', 0);

        $mform->addElement(
                'select',
                'var3',
                get_string('lockpages', 'assignment_mahara'),
                array(
                    ASSIGNMENT_MAHARA_SETTING_DONTLOCK => get_string('no'),
                    ASSIGNMENT_MAHARA_SETTING_KEEPLOCKED => get_string('yeskeeplocked', 'assignment_mahara'),
                    ASSIGNMENT_MAHARA_SETTING_UNLOCK => get_string('yesunlock', 'assignment_mahara')
                )
        );
        $mform->setHelpButton(
                'var3',
                array(
                        'lockpages',
                        get_string('lockpages', 'assignment_mahara'),
                        'assignment_mahara'
                )
        );
        $mform->setDefault(
                'var3',
                ASSIGNMENT_MAHARA_SETTING_UNLOCK
        );
    }

    function remote_mnet_host_id() {
        return $this->assignment->var2;
    }

    function get_mnet_sp() {
        global $CFG, $MNET;
        require_once $CFG->dirroot . '/mnet/peer.php';
        $mnet_sp = new mnet_peer();
        $mnet_sp->set_id($this->remote_mnet_host_id());
        return $mnet_sp;
    }

    /**
     * Add MNet access params to a view/collection URL.
     *
     * When a properly upgraded Mahara site sees these params in a request
     * coming from a user who has authenticated to Mahara via MNet, it will
     * use the params to phone back to Moodle to check whether that Moodle
     * user has permission to view the specified page as part of a Moodle
     * assigment submission.
     *
     * @param string $url Basic Moodle view URL
     * @param int $viewid ID of the view or collection
     * @param bool $iscollection Whether it's a view or a collection
     * @param int $submissionid ID of the assignment submission it's part of
     * @return string a URL with additional params
     */
    public function mnet_access_url($url, $viewid, $iscollection, $submissionid) {
        global $DB;
        return $url
            . '&assignment=' . $submissionid
            . '&mnet' . ($iscollection ? 'coll' : 'view') . 'id=' . $viewid;
    }

    function submit_view($viewid, $iscollection = false) {
        global $CFG, $USER, $MNET;

        $submission = $this->get_submission($USER->id, true);
        if ($submission->data2) {
            $olddata = unserialize($submission->data2);
            $oldviewid = $olddata['id'];
            $oldiscoll = $olddata['iscollection'];
            $oldviewstatus = $olddata['viewstatus'];
            // If they're submitting a different view, unlock the old view
            if (
                    !($viewid == $oldviewid && $iscollection == $oldiscoll)
                    && $oldviewstatus == self::MAHARA_STATUS_LOCKED
            ) {
                $this->mnet_release_view($oldviewid, $oldiscoll);
            }
        }

        $update = new stdClass();
        $update->id = $submission->id;
        $update->timemodified = time();

        // This means they chose to deselect the current submission, without
        // selecting a replacement.
        if ($viewid == 0) {
            $update->data2 = '';
        } else {

            $lock = (bool)(
                    $this->assignment->var3 == ASSIGNMENT_MAHARA_SETTING_UNLOCK
                    || $this->assignment->var3 == ASSIGNMENT_MAHARA_SETTING_KEEPLOCKED
            );

            require_once $CFG->dirroot . '/mnet/xmlrpc/client.php';
            $mnet_sp = $this->get_mnet_sp();
            $mnetrequest = new mnet_xmlrpc_client();
            $mnetrequest->set_method('mod/mahara/rpclib.php/submit_view_for_assessment');
            $mnetrequest->add_param($USER->username);
            $mnetrequest->add_param($viewid);
            $mnetrequest->add_param($iscollection);
            $mnetrequest->add_param('moodle-assignsubmission-mahara:2');
            $mnetrequest->add_param($lock);

            if ($mnetrequest->send($mnet_sp) !== true) {
                return false;
            }
            $data = $mnetrequest->response;
            $data['iscollection'] = $iscollection;
            // Assume we're dealing with a fully upgraded Mahara
            // that uses Mnet for access control, instead of
            // Mahara secreturl access tokens
            if ($lock) {
                $data['viewstatus'] = self::MAHARA_STATUS_LOCKED;
            } else {
                $data['viewstatus'] = self::MAHARA_STATUS_NORMAL;
            }

            // data1 column is never actually used anywhere... and I can't think
            // of one value that would be consistently useful by itself.
    //        $update->data1 = addslashes('<a href="' . $data['fullurl'], '">' . clean_text($data['title']) . '</a>');

            // Add mnet access flags to the page's URL
            $data['mneturl'] = $this->mnet_access_url(
                    $data['url'],
                    $viewid,
                    $iscollection,
                    $submission->id
            );
            $update->data2 = addslashes(serialize($data));
        }

        if (!update_record('assignment_submissions', $update)) {
            return false;
        }

        $submission = $this->get_submission($USER->id);
        $this->update_grade($submission);
        return true;
    }

    /**
     * Get all the views & collections the user can pick for this submission
     *
     * @param string $query A search query, to get only views/collections with matching titles
     * @return boolean[]|string[]
     */
    function get_views($query) {
        global $CFG, $USER, $MNET;

        // Get info about which view/collection is already selected (if any)
        $submission = $this->get_submission();
        $data = unserialize($submission->data2);
        $selectediscollection = $data['iscollection'];
        $selectedid = $data['id'];
        unset($data);

        $error = false;
        $viewdata = array();
        if (!is_enabled_auth('mnet')) {
            $error = get_string('authmnetdisabled', 'mnet');
        } else if (!has_capability('moodle/site:mnetlogintoremote', get_context_instance(CONTEXT_SYSTEM), NULL, false)) {
            $error = get_string('notpermittedtojump', 'mnet');
        } else {
            // set up the RPC request
            require_once $CFG->dirroot . '/mnet/xmlrpc/client.php';
            $mnet_sp = $this->get_mnet_sp();
            $mnetrequest = new mnet_xmlrpc_client();
            $mnetrequest->set_method('mod/mahara/rpclib.php/get_views_for_user');
            $mnetrequest->add_param($USER->username);
            $mnetrequest->add_param($query);

            if ($mnetrequest->send($mnet_sp) === true) {
                $views = $mnetrequest->response;
                    // Filter out collection views, special views, already-submitted views, and the currently selected view
                    foreach ($views['data'] as $i => $view) {
                        if (
                                $view['collid']
                                || $view['type'] != 'portfolio'
                                || $view['submittedtime']
                                || (!$selectediscollection && $view['id'] == $selectedid)
                        ) {
                            unset($views['ids'][$i]);
                            unset($views['data'][$i]);
                            $views['count']--;
                        }
                    }
                    // Filter out empty or submitted collections, and the currently selected collections
                    foreach ($views['collections']['data'] as $i => $coll) {
                        if (
                                (
                                        array_key_exists('numviews', $coll)
                                        && $coll['numviews'] == 0
                                )
                                || $coll['submittedtime']
                                || ($selectediscollection && $coll['id'] == $selectedid)
                        ) {
                            unset($views['collections']['data'][$i]);
                            $views['collections']['count']--;
                        }
                    }
            } else {
                $error = "RPC mod/mahara/rpclib.php/get_views_for_user:<br/>";
                foreach ($mnetrequest->error as $errormessage) {
                    list($code, $errormessage) = array_map('trim',explode(':', $errormessage, 2));
                    $error .= "ERROR $code:<br/>$errormessage<br/>";
                }
            }
        }
        return array($error, $views);
    }

    function process_outcomes($userid) {
        global $CFG, $MNET, $USER;
        parent::process_outcomes($userid);

        // Export outcomes to the mahara site
        $grading_info = grade_get_grades($this->course->id, 'mod', 'assignment', $this->assignment->id, $userid);

        if (empty($grading_info->outcomes)) {
            return;
        }

        if (!$submission = $this->get_submission($userid)) {
            return;
        }

        $data = unserialize($submission->data2);

        $viewoutcomes = array();

        foreach($grading_info->outcomes as $o) {
            $scale = make_grades_menu(-$o->scaleid);
            if (!isset($scale[$o->grades[$userid]->grade])) {
                continue;
            }
            // Save array keys; they get lost on the way
            foreach ($scale as $k => $v) {
                $scale[$k] = array('name' => $v, 'value' => $k);
            }
            $viewoutcomes[] = array(
                'name' =>  $o->name,
                'scale' => $scale,
                'grade' => $o->grades[$userid]->grade,
            );
        }

        require_once $CFG->dirroot . '/mnet/xmlrpc/client.php';
        $this->mnet_release_view($data['id'], $data['iscollection']);
    }

    /**
     * Hook function for when a submission's grade gets updated
     * {@inheritDoc}
     * @see assignment_base::update_grade()
     */
    function update_grade($submission) {
        parent::update_grade($submission);
        $submission = get_record('assignment_submissions', 'id', $submission->id);
        // If they haven't been graded yet, nothing to do
        if (false === assignment_get_user_grades($this->assignment, $submission->userid)) {
            return;
        }

        // If they have been graded, check to see if we need to unlock the submitted page
        if (!$submission->data2) {
            return;
        }
        $data = unserialize($submission->data2);
        if (
                $data['viewstatus'] == self::MAHARA_STATUS_LOCKED
                && $this->assignment->var3 != ASSIGNMENT_MAHARA_SETTING_KEEPLOCKED
        ) {
            $this->mnet_release_view($data['id'], $data['iscollection']);
        }
    }

    /**
     * Delete an assignment activity
     * {@inheritDoc}
     * @see assignment_base::delete_instance()
     */
    function delete_instance($assignment) {
        // Unlock any pages & collections locked by this assignment.
        $submissions = $this->get_submissions();
        if ($submissions) {
            foreach ($submissions as $submission) {
                if ($submission->data2) {
                    $data = unserialize($submission->data2);
                    if ($data['viewstatus'] == self::MAHARA_STATUS_LOCKED) {
                        $this->mnet_release_view($data['id'], $data['iscollection']);
                    }
                }
            }
        }
        parent::delete_instance($assignment);
    }

    function mnet_release_view($viewid, $iscollection) {
        global $USER, $CFG, $MNET;
        require_once $CFG->dirroot . '/mnet/xmlrpc/client.php';
        $mnet_sp = $this->get_mnet_sp();
        $mnetrequest = new mnet_xmlrpc_client();
        $mnetrequest->set_method('mod/mahara/rpclib.php/release_submitted_view');
        $mnetrequest->add_param($viewid);
        $mnetrequest->add_param(null);
        $mnetrequest->add_param($USER->username);
        $mnetrequest->add_param($iscollection);
        // Do something if this fails?  Or use cron to export the same data later?
        $mnetrequest->send($mnet_sp);
    }
}
