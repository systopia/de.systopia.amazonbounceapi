<?php
use CRM_Amazonbounceapi_ExtensionUtil as E;

/**
 * Amazonbounceapi.Bounceevent API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 * @return void
 * @see http://wiki.civicrm.org/confluence/display/CRMDOC/API+Architecture+Standards
 */
function _civicrm_api3_amazonbounceapi_Bounceevent_spec(&$spec) {
  $spec['notification_type']['api.required'] = 1;
  $spec['bounce_type']['api.required'] = 1;
  $spec['bounce_sub_type']['api.required'] = 1;
  $spec['bouncedRecipients']['api.required'] = 1;
  $spec['headers_raw']['api.required'] = 1;
  $spec['message_raw']['api.required'] = 1;
  $spec['message_id']['api.required'] = 1;
  $spec['topic_arn']['api.required'] = 1;
  $spec['amazon_type']['api.required'] = 1;
  $spec['timestamp']['api.required'] = 1;
  $spec['signature']['api.required'] = 1;
  $spec['signature_cert_url']['api.required'] = 1;
}

/**
 * Amazonbounceapi.Bounceevent API
 *
 * @param array $params
 * @return array API result descriptor
 * @see civicrm_api3_create_success
 * @see civicrm_api3_create_error
 * @throws API_Exception
 */
function civicrm_api3_amazonbounceapi_Bounceevent($params) {

  try {
    $bounce_handler = new CRM_Amazonbounceapi_BounceHandler($params);
    if ($bounce_handler->run()) {
      return civicrm_api3_create_success([], $params, 'Amazonbounceapi', 'bounceevent');
    } else {
      throw new API_Exception($bounce_handler->get_fail_reason());
    }
  } catch (Exception $e) {
    throw new API_Exception($e->getMessage());
  }
}
