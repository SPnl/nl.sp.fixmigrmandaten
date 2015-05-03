<?php

/**
 * Fixmigrmandaten.Do API
 * Voer de correcties uit (zie CRM_Fixmigrmandaten_Do)
 *
 * @param array $params
 *
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_fixmigrmandaten_do($params = array()) {

	CRM_Fixmigrmandaten_Do::execute();
	return civicrm_api3_create_success(array('message' => 'Correcties uitgevoerd.'));
}

/**
 * Fixmigrmandaten.Do API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRM/API+Architecture+Standards
 */
function _civicrm_api3_fixmigrmandaten_do_spec(&$spec) {

}