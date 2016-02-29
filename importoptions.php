<?php

/**
 * Import options or just add new users from CSV
 *
 * @package   Booking
 * @copyright 2014 Andraž Prinčič www.princic.net
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * */
require_once("../../config.php");
require_once("lib.php");
require_once('importoptions_form.php');

function fixEncoding($in_str) {
    $cur_encoding = mb_detect_encoding($in_str);
    if ($cur_encoding == "UTF-8" && mb_check_encoding($in_str, "UTF-8")) {
        return $in_str;
    } else {
        return utf8_encode($in_str);
    }
}

$id = required_param('id', PARAM_INT);                 // Course Module ID

$url = new moodle_url('/mod/booking/importoptions.php', array('id' => $id));
$urlRedirect = new moodle_url('/mod/booking/view.php', array('id' => $id));
$PAGE->set_url($url);

if (!$cm = get_coursemodule_from_id('booking', $id)) {
    print_error("Course Module ID was incorrect");
}

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error('coursemisconf');
}

require_course_login($course, false, $cm);
$groupmode = groups_get_activity_groupmode($cm);

if (!$booking = booking_get_booking($cm, '')) {
    error("Course module is incorrect");
}

if (!$context = context_module::instance($cm->id)) {
    print_error('badcontext');
}

require_capability('mod/booking:updatebooking', $context);

$PAGE->navbar->add(get_string("importcsvtitle", "booking"));
$PAGE->set_title(format_string($booking->name));
$PAGE->set_heading($course->fullname);
$PAGE->set_pagelayout('standard');

$mform = new importoptions_form($url);

$completion = new completion_info($course);

//Form processing and displaying is done here
if ($mform->is_cancelled()) {
    //Handle form cancel operation, if cancel button is present on form
    redirect($urlRedirect, '', 0);
    die;
} else if ($fromform = $mform->get_data()) {

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');

    $csv_datas =  $mform->get_data('csvdatas');
    $csv_delimiter = $csv_datas->delimiter_name;
        
    if ($csv_delimiter == 'tab') {
        $csv_delimiter = '\\t';
    } else if ($csv_delimiter == 'colon') {
        $csv_delimiter = ':';   
        
    } else if ($csv_delimiter == 'semicolon') {
        $csv_delimiter = ';';
    } else if ($csv_delimiter == 'comma') {
        $csv_delimiter = ',';     
    } else {
            print_error('There is not any csv delimiter.');
    }

    $csv_encode = '/\&\#44/';
    if (isset($CFG->CSV_ENCODE)) {
            $csv_encode = '/\&\#' . $CFG->CSV_ENCODE . '/';
    }
    
    $notifynum = 0;
    
    $csvfile = $mform->get_file_content('csvfile');

    $rawlines = explode(PHP_EOL, $csvfile);
    $csvfile = '';  //unset($csvfile) 

    $optionalDefaults = array("bookingoptionid" => 1); //required for Update
    $required = array("name" => 1);
    
    $optional = array("description" => 1, "coursestarttime" => 1, "courseendtime" => 1, "institution" => 1, "address" => 1, "location" => 1,
            "maxanswers" => 1, "maxoverbooking" => 1, "bookingopeningtime" => 1, "bookingclosingtime" => 1, "showdatetime" => 1,
            "pollurl" => 1, "pollurlteachers" => 1, "courseid" => 1, "connectedform" => 1, "conectedoption" => 1, 
            "notificationoption" => 1, "notificationtext" => 1,  "daystonotify" => 1, "howmanyusers" => 1,
            "removeafterminutes" => 1, "completiontext" => 1, "disablebookingusers" => 1,
             "teacheremail" => 1, "teacheruserid" => 1, "teacherusername" => 1, 
            "useremail" => 1, "userid" => 1, "username" => 1, "finished" => 1,        
            "deletebookingoption" => 1, 
            "deleteteacher" => 1, "deleteallteachers" => 1,            
            "deleteuser" => 1, "deleteallusers" => 1);
            // No permitted: addtocalendar, calendarid, groupid

    // --- get header (field names) ---
    $header = explode($csv_delimiter, array_shift($rawlines));

    // check for valid field names
    foreach ($header as $i => $h) {
        $h = trim($h); $header[$i] = $h; // remove whitespace
       
        if ($h === 'bookingoptionid') {
            $required = array("bookingoptionid" => 1);
            $optionalDefaults = array("name" => 1); 
        }
        
        if (!(isset($required[$h]) or isset($optionalDefaults[$h]) or isset($optional[$h]))) {
                print_error('invalidfieldname', 'error', 'importoptions.php?id='.$id, $h);
            }
        if (isset($required[$h])) {
            $required[$h] = 2;
        }
    }

    // check for required fields
    foreach ($required as $key => $value) {
        if ($value < 2) {
            print_error('fieldrequired', 'error', 'importoptions.php?id='.$id, $key);
        }
    }
    
    $linenum = 1; // since header is line 1
    foreach ($rawlines as $rawline) {
        $linenum = $linenum+1;
        $record = array();
        $bookingObject = new stdClass();
        $newbooking = new stdClass();
        $newbooking->deletebookingoption = FALSE;
        $newbooking->deleteallteachers = FALSE;
        $newbooking->deleteteacher = FALSE;
        $newbooking->deleteallusers = FALSE;
        $newbooking->deleteuser = FALSE;
        
        if  (isset($rawline)) {
            $line = explode($csv_delimiter, $rawline);

            foreach ($line as $key => $value) {
                //decode encoded commas
                $record[$header[$key]] = preg_replace($csv_encode, $csv_delimiter, trim($value));
            }

            foreach ($record as $name => $value) {
                $booking_option = FALSE;

                // check for required values             
                if (isset($required[$name]) and !$value) {
                    print_error('missingfield', 'error', 'importoptions.php?id='.$id, $name);
                } else if ($name == "name") {
                    $newbooking->name = $value;
                } else {
                    if (empty($value)) {
                        $textfields = array('description', 'notificationtext', 'location', 'institution', 'address', 
                                            'pollurl', 'pollurlteachers', 'completiontext', 'useremail', 'teacheremail');
                                                   
                        if (in_array($name, $textfields)) {
                            $newbooking->{$name} = '';
                        } else {
                            $newbooking->{$name} = 0;
                        }
                    } else {
                        $newbooking->{$name} = $value;
                    }
                }
            }

            echo $OUTPUT->box('<h3>' . $newbooking->name . '</h3>');
            
            if (isset($newbooking->bookingopeningtime)) {
                $newbooking->bookingopeningtime = date_create_from_format("!" . $fromform->dateparseformat, $newbooking->bookingopeningtime);
            }

            $dErors = DateTime::getLastErrors();
            if ($dErors['error_count'] > 0) {
                echo $OUTPUT->notification(get_string('dateerror', 'booking')  . $linenum . ': bookingopeningtime');
                $notifynum = $notifynum + 1;
                continue;
            }
                
            if (isset($newbooking->bookingclosingtime)) {
                $newbooking->bookingclosingtime = date_create_from_format("!" . $fromform->dateparseformat, $newbooking->bookingclosingtime);
            }

            $dErors = DateTime::getLastErrors();
            if ($dErors['error_count'] > 0) {
                echo $OUTPUT->notification(get_string('dateerror', 'booking')  . $linenum . ': bookingclosingtime');
                $notifynum = $notifynum + 1;
                continue;
            }
                
            if (isset($newbooking->coursestarttime)) {
                $newbooking->coursestarttime = date_create_from_format("!" . $fromform->dateparseformat, $newbooking->coursestarttime);
            }

            $dErors = DateTime::getLastErrors();
            if ($dErors['error_count'] > 0) {
                echo $OUTPUT->notification(get_string('dateerror', 'booking')  . $linenum . ': coursestarttime');
                $notifynum = $notifynum + 1;
                continue;
            }

            if (isset($newbooking->courseendtime)) {
                $newbooking->courseendtime = date_create_from_format("!" . $fromform->dateparseformat, $newbooking->courseendtime);
            }

            $dErors = DateTime::getLastErrors();
            if ($dErors['error_count'] > 0) {
                echo $OUTPUT->notification(get_string('dateerror', 'booking')  . $linenum . ': courseendtime');
                $notifynum = $notifynum + 1;
                continue;
            }

            if (isset($newbooking->teacheremail )) {
                $teacherusers = $DB->get_records('user', array('email' => $newbooking->teacheremail));
                $teacheremailnum = count($teacherusers);
                if ($teacheremailnum > 1) {
                    if (isset($newbooking->teacheruserid) && $newbooking->teacheruserid > 0) {
                        $teacheruser = $DB->get_record('user', array('email' => $newbooking->teacheremail, 'id' =>  $newbooking->teacheruserid));
                        if (!$teacheruser) {
                            echo $OUTPUT->notification(get_string('falseuserinfo', 'booking') . $newbooking->teacheremail . get_string('and', 'booking') .
                                                       $newbooking->teacheruserid );
                            $notifynum = $notifynum + 1;
                        } else {
                            $newbooking->teacher = $teacheruser;
                        }
                    } else if (isset($newbooking->teacherusername) && $newbooking->teacherusername != '') {
                        $teacheruser = $DB->get_record('user', array('email' => $newbooking->teacheremail, 'username' =>  $newbooking->teacherusername));
                        if (!$teacheruser) {
                            echo $OUTPUT->notification(get_string('falseuserinfo', 'booking') . $newbooking->teacheremail . get_string('and', 'booking') . 
                                                       $newbooking->teacherusername );
                            $notifynum = $notifynum + 1;
                        } else {
                            $newbooking->teacher = $teacheruser;
                        }
                    } else {
                        echo $OUTPUT->notification('"teacheremail": ' . get_string('emailtomany', 'booking') . 
                                                   'teacheruserid ' . get_string('or', 'booking') . ' teacherusername');
                        $notifynum = $notifynum + 1;
                    }    
                } else if ($teacheremailnum === 1) {
                    $teacheruser = $DB->get_record('user', array('email' => $newbooking->teacheremail));
                    $newbooking->teacher = $teacheruser;     
                }
                
            } else {
                $newbooking->teacher = '';
            }

            if (isset($newbooking->useremail)) {
                $users = $DB->get_records('user', array('suspended' => 0, 'deleted' => 0, 'confirmed' => 1, 'email' => $newbooking->useremail));
                $useremailnum = count($users);
                 if ($useremailnum > 1) {
                    if (isset($newbooking->userid) && $newbooking->userid > 0) {
                        $user = $DB->get_record('user', array('suspended' => 0, 'deleted' => 0, 'confirmed' => 1, 'email' => $newbooking->useremail, 'id' =>  $newbooking->userid));
                        if (!$user) {
                            echo $OUTPUT->notification(get_string('falseuserinfo', 'booking') . $newbooking->useremail . get_string('and', 'booking') .
                                                       $newbooking->userid );
                            $notifynum = $notifynum + 1;
                        } else {
                            $newbooking->user = $user;
                        }          
                    } else if (isset ($newbooking->username) && $newbooking->username != '') {
                        $user = $DB->get_record('user', array('suspended' => 0, 'deleted' => 0, 'confirmed' => 1, 'email' => $newbooking->useremail, 'username' =>  $newbooking->username));
                        if (!$user) {
                            echo $OUTPUT->notification(get_string('falseuserinfo', 'booking') . $newbooking->useremail . get_string('and', 'booking') . 
                                                       $newbooking->username );
                            $notifynum = $notifynum + 1;
                        } else {
                            $newbooking->user = $user;
                        }
                    } else {
                        echo $OUTPUT->notification('"useremail": ' . get_string('emailtomany', 'booking') . 
                                                   'userid ' . get_string('or', 'booking') . ' username');
                        $notifynum = $notifynum + 1;
                    } 
                } else if ($useremailnum === 1) {
                    $user = $DB->get_record('user', array('suspended' => 0, 'deleted' => 0, 'confirmed' => 1, 'email' => $newbooking->useremail));
                    $newbooking->user = $user;     
                }
            } else {
                $newbooking->user = '';
            }
            
            if(!isset($newbooking->finished)) {
                        $newbooking->finished = 0;
                    }

            if (isset($newbooking->maxanswers)) {
                if ($newbooking->maxanswers > 0){
                    $newbooking->limitanswers = 1;
                } else {
                    $newbooking->limitanswers = 0;
                }   
            }        

            if (isset($newbooking->bookingoptionid) && $newbooking->bookingoptionid > 0) { // Query for update
                $booking_option = $DB->get_record_sql('SELECT * FROM {booking_options} 
                    WHERE id = :id AND bookingid = :bookingid', // AND text LIKE :text
                    array('id' => $newbooking->bookingoptionid, 'text' => $newbooking->name, 'bookingid' => $booking->id));
            } else { // Query for Insert
                $booking_option = $DB->get_record_sql('SELECT * FROM {booking_options} 
                    WHERE text LIKE :text AND bookingid = :bookingid',
                    array('text' => $newbooking->name, 'bookingid' => $booking->id));
            }

            if ($newbooking->deletebookingoption == 1) {
                if (!$newbooking->bookingoptionid) {
                    if (!isset($newbooking->name) || empty($newbooking->name)) {
                            echo $OUTPUT->box(get_string('bookingoptionunknown', 'booking'));
                    }
                    echo $OUTPUT->notification(get_string('bookingoptionidexistno', 'booking'));
                    $notifynum = $notifynum + 1;
                    continue;
                } else {
                    $bookingoptionexist = $DB->get_record('booking_options', array('id' => $newbooking->bookingoptionid, 'bookingid' => $booking->id));
                    if (!$bookingoptionexist) {
                        if (!isset($newbooking->name) || empty($newbooking->name)) {
                            echo $OUTPUT->box(get_string('bookingoptionunknown', 'booking'));
                        }
                        echo $OUTPUT->notification(get_string('bodeletenotpossible', 'booking'));
                         $notifynum = $notifynum + 1;
                         continue;
                    } else {
                        $deletebookingoption = $DB->delete_records('booking_options', array('id' => $newbooking->bookingoptionid, 'bookingid' => $booking->id, ));
                        if ($deletebookingoption) {
                            $information .= get_string('bodeletesuccessful', 'booking') . '<br />';
                        }     
                    }
                }
            } else {
                if (isset($newbooking->bookingoptionid) && !$booking_option->id == $newbooking->bookingoptionid) {
                    if (!isset($newbooking->name) || empty($newbooking->name)) {
                            echo $OUTPUT->box(get_string('bookingoptionunknown', 'booking'));
                    }
                    echo $OUTPUT->notification(get_string('boupdatenotpossible', 'booking'));
                    $notifynum = $notifynum + 1;
                    continue;
                }
             
                $bookingoptionfields = array('bookingoptionid', 'name', 'description', 'maxanswers', 'maxoverbooking', 'bookingopeningtime', 
                                             'bookingclosingtime', 'coursestarttime', 'courseendtime', 'showdatetime', 'pollurl', 'pollurlteachers', 
                                             'institution', 'address', 'notificationoption', 'notificationtext', 'location', 'courseid', 
                                             'connectedform', 'conectedoption', 'daystonotify', 'howmanyusers', 'removeafterminutes', 
                                             'completiontext', 'disablebookingusers');
                foreach ($record as $name => $value) {
                    // Important that only will be created or updated the fields of the table booking_options
                    if (in_array($name, $bookingoptionfields)) {
                        if ($name == 'bookingoptionid' && ($newbooking->bookingoptionid > 0 || !$newbooking->bookingoptionid == '')) {
                            $bookingObject->id = $newbooking->bookingoptionid;
                        } else if ($name == 'name') {
                            $bookingObject->text = fixEncoding($newbooking->name);
                        } else if ($name == 'maxanswers') {
                            $bookingObject->maxanswers = $newbooking->maxanswers;
                            $bookingObject->limitanswers = $newbooking->limitanswers;
                        } else if ($name == 'coursestarttime' || $name == 'courseendtime') {
                            $bookingObject->{$name} = $newbooking->{$name}->getTimestamp();
                        } else {
                            if (in_array($name, $textfields)) {
                                $bookingObject->{$name} = fixEncoding($newbooking->{$name});
                            } else {
                                $bookingObject->{$name} = $newbooking->{$name};
                            }
                        }
                    $bookingObject->bookingid = $booking->id;
                    }
                }

                if (isset($bookingObject->id)) {    
                    $bid = $DB->update_record('booking_options', $bookingObject, TRUE);
                    if (isset($bid)) {
                        if (empty($newbooking->name)) {
                            $information = '<h3>' . $booking_option->text . '</h3>';
                            $information .= get_string('boupdatesuccessful', 'booking') . '<br />';
                        } else {
                            $information = get_string('boupdatesuccessful', 'booking') . '<br />'; 
                        }
                    }
                } else if (empty($booking_option)){
                    $bid = $DB->insert_record('booking_options', $bookingObject, TRUE);
                    if (isset($bid)) {
                        $information = get_string('insertnewbookingoption', 'booking') . '<br />';
                        $bookingObject->id = $bid;
                    }
                } else {
                    echo $OUTPUT->notification(get_string('bookingoptionnocreated', 'booking') . $newbooking->name);
                    $notifynum = $notifynum + 1;
                    continue;
                }
                $booking_option = $bookingObject;
            }
                     
            // Delete all teachers of table 'booking_teachers' with the relevant bookingoptionid.
            if ($newbooking->deletebookingoption == 1 || ($newbooking->deleteallteachers == 1 && isset($newbooking->bookingoptionid) && $booking_option->id == $newbooking->bookingoptionid)) {
                // Query wether teachers exist in booking_teachers with the optionid. 
                $getotherteachers = $DB->get_records('booking_teachers', array('bookingid' => $booking->id, 'optionid' => $booking_option->id));
                if ($getotherteachers) {
                    foreach($getotherteachers as $getotherteacher) {
                        $deleteteachers = $DB->delete_records('booking_teachers', array('bookingid' => $booking->id, 'userid' => $getotherteacher->userid, 'optionid' => $newbooking->bookingoptionid));
                    }
                    if ($deleteteachers == 1) {
                        $information .= get_string('teacherdeleteall', 'booking') . '<br />';
                    }
                }
            } 
            // Delete relevant teacher of table 'booking_teachers' with the relevant bookingoptionid and userid.
            else if ($newbooking->deleteteacher == 1 && isset($newbooking->bookingoptionid) && $booking_option->id == $newbooking->bookingoptionid) {
                if (!empty($newbooking->teacher)) {                
                    $deleteteacher = $DB->delete_records('booking_teachers', array('bookingid' => $booking->id, 'userid' => $newbooking->teacher->id, 'optionid' => $newbooking->bookingoptionid));
                    if (empty($deleteteacher)) {
                        echo $OUTPUT->notification(get_string('teacherexistno', 'booking'));
                        $notifynum = $notifynum + 1;                    }
                    if ($deleteteacher == 1) {
                        $information .= get_string('teacherdelete', 'booking') . '<br />';
                    }
                }
            } 
            // Otherwise it inserts the teacher with the relevants values in the table 'booking_teachers'.
            else if (!empty($newbooking->teacher)) {
                // Query wether $newbooking->teacher is in the table 'booking_teachers
                $getteacher = $DB->get_record('booking_teachers', array('bookingid' => $booking->id, 'userid' => $newbooking->teacher->id, 'optionid' => $booking_option->id));               
                 // If the teacher is already enrolled.
                if ($getteacher !== FALSE) {
                    $information .= get_string('teacherexist', 'booking') . '<br />';
                }
                // If the trainer does not exist in the database, then he will be created.
                if ($getteacher === FALSE) {
                    $newTeacher = new stdClass();
                    $newTeacher->bookingid = $booking->id;
                    $newTeacher->userid = $newbooking->teacher->id;
                    $newTeacher->optionid = $booking_option->id;
                    $dbteacher = $DB->insert_record('booking_teachers', $newTeacher, TRUE);
                    if (isset($dbteacher)) {
                        $information .= get_string('teacheruploadsuccesfully', 'booking') . '<br />';
                    }    
                }
            } else if (isset($newbooking->teacher) && $newbooking->teacher == '') {
                $information .= get_string('teachernoupload', 'booking') . '<br />';
            }

            // Delete all users of table 'booking_answers' with the relevant bookingoptionid.
            if ($newbooking->deletebookingoption == 1 || ($newbooking->deleteallusers == 1 && isset($newbooking->bookingoptionid) && $booking_option->id == $newbooking->bookingoptionid)) {
                // Query wether users exist in booking_answers with the optionid. 
                $getotherusers = $DB->get_records('booking_answers', array('bookingid' => $booking->id, 'optionid' => $booking_option->id));
                if ($getotherusers) {
                    foreach($getotherusers as $getotheruser) {
                        $deleteusers = $DB->delete_records('booking_answers', array('bookingid' => $booking->id, 'userid' => $getotheruser->userid, 'optionid' => $newbooking->bookingoptionid));
                    }
                    if ($deleteusers == 1) {
                        $information .= get_string('userdeleteall', 'booking') . '<br />';
                    }
                }
            }
            // Delete relevant user of table 'booking_answers' with the relevant bookingoptionid and userid.
            else if ($newbooking->deleteuser == 1 && isset($newbooking->bookingoptionid) && $booking_option->id == $newbooking->bookingoptionid) {
                if (!empty($newbooking->user)) {
                    $getuser = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $newbooking->user->id, 'optionid' => $booking_option->id));
                    if ($getuser){
                        $deleteuser = $DB->delete_records('booking_answers', array('bookingid' => $booking->id, 'userid' => $newbooking->user->id, 'optionid' => $newbooking->bookingoptionid));
                    }
                    if (empty($deleteuser)) {
                        echo $OUTPUT->notification(get_string('userexistno', 'booking'));
                        $notifynum = $notifynum + 1;
                    }
                    if ($deleteuser == 1) {
                        $information .= get_string('userdelete', 'booking') . '<br />';
                    }
                }
            }
            // Otherwise it inserts the user with the relevants values in the table 'booking_answers'.        
            else if (!empty($newbooking->user)) {
                // Query wether $newbooking->user is in the table 'booking_users
                $getuser = $DB->get_record('booking_answers', array('bookingid' => $booking->id, 'userid' => $newbooking->user->id, 'optionid' => $booking_option->id));
                // If the user is already enrolled.
                if ($getuser !== FALSE) {
                    $information .= get_string('userexist', 'booking') . '<br />';
                }
                // If the user does not exist in the database, then he will be created.
                if ($getuser === FALSE) {    
                    $newUser = new stdClass();
                    $newUser->bookingid = $booking->id;
                    $newUser->userid = $newbooking->user->id;
                    $newUser->optionid = $booking_option->id;
                    $newUser->completed = $newbooking->finished;
                    $newUser->timemodified = time();
                    $newUser->timecreated = time();
                    $dbuser = $DB->insert_record('booking_answers', $newUser, TRUE);
                    if ($completion->is_enabled($cm) && $booking->enablecompletion && $newUser->completed == 0) {
                        $completionsuccessful =  $completion->update_state($cm, COMPLETION_INCOMPLETE, $newUser->userid);
                    }
                    if ($completion->is_enabled($cm) && $booking->enablecompletion && $newUser->completed == 1) {
                        $completionsuccessful =  $completion->update_state($cm, COMPLETION_COMPLETE, $newUser->userid);
                    }
                    if ($dbuser) {
                        $information .= get_string('useruploadsuccesfully', 'booking') . '<br />';
                    }    
                }
            } else if (isset($newbooking->user) && empty($newbooking->user)) {
                $information .= get_string('usernoupload', 'booking') . '<br />';
            }
            
            $information .= '<br />';
            echo $OUTPUT->box($information);

        } else {
            // Not ok, write error!
            echo $OUTPUT->notification(get_string('wrongfile', 'booking'));
            $notifynum = $notifynum + 1;
        }
    }
    if ($notifynum == 0) {
        echo $OUTPUT->notification(get_string('importfinished', 'booking'), 'notifysuccess');
    }
    
    //In this case you process validated data. $mform->get_data() returns data posted in form.
} else {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string("importcsvtitle", "booking"), 3, 'helptitle', 'uniqueid');


    // this branch is executed if the form is submitted but the data doesn't validate and the form should be redisplayed
    // or on the first display of the form.
    //displays the form
    $mform->display();
}

echo $OUTPUT->footer();
