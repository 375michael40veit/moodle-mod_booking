<?php

require_once("$CFG->libdir/formslib.php");
require_once("../../lib/csvlib.class.php");

class importoptions_form extends moodleform {

    //Add elements to form
    public function definition() {
        global $CFG;

        $mform = $this->_form; // Don't forget the underscore! 

        $mform->addElement('filepicker', 'importoptioncsvfile', get_string('importoptioncsvfile', 'booking'), null, array('maxbytes' => $CFG->maxbytes, 'accepted_types' => '*'));
        $mform->addRule('importoptioncsvfile', null, 'required', null, 'client');
        $mform->addHelpButton('importoptioncsvfile', 'importoptioncsvfile', 'mod_booking');

       $choices = csv_import_reader::get_delimiter_list();
        $mform->addElement('select', 'delimiter_name', get_string('csvdelimiter', 'booking'), $choices);
        if (array_key_exists('cfg', $choices)) {
            $mform->setDefault('delimiter_name', 'cfg');
        } else if (get_string('listsep', 'langconfig') == ';') {
            $mform->setDefault('delimter_name', 'semicolon');
        } else {
            $mform->setDefault('delimiter_name', 'comma');
        }
        
        $mform->addElement('text', 'dateparseformat', get_string('dateparseformat', 'booking')); // Add elements to your form
        $mform->setType('dateparseformat', PARAM_NOTAGS);                   //Set type of element
        $mform->setDefault('dateparseformat', get_string('defaultdateformat', 'booking'));
        $mform->addRule('dateparseformat', null, 'required', null, 'client');
        $mform->addHelpButton('dateparseformat', 'dateparseformat', 'mod_booking');


        $this->add_action_buttons(TRUE, get_string('importcsvtitle', 'booking'));
    }

    //Custom validation should be added here
    function validation($data, $files) {
        return array();
    }

}
