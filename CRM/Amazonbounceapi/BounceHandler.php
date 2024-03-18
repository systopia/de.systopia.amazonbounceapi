<?php
/*-------------------------------------------------------+
| SYSTOPIA Mailingtools Extension                        |
| Copyright (C) 2018 SYSTOPIA                            |
| Author: P. Batroff (batroff@systopia.de)               |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

use CRM_Mailingtools_ExtensionUtil as E;


class CRM_Amazonbounceapi_BounceHandler {

  /**
   * SES Permanent bounce types.
   *
   * Hard bounces
   * @access private
   * @var array $ses_permanent_bounce_types
   */
  private $ses_permanent_bounce_types = [ 'Undetermined', 'General', 'NoEmail', 'Suppressed' ];

  /**
   * SES Transient bounce types.
   *
   * Soft bounces
   * @access private
   * @var array $ses_transient_bounce_types
   */
  private $ses_transient_bounce_types = [ 'General', 'MailboxFull', 'MessageTooLarge', 'ContentRejected', 'AttachmentRejected' ];


  /**
   * @var SNS Notifcation Type
   *      (should be 'Bounce'. Other events wont be parsed in this API)
   */
  private $notification_type;

  /**
   * @var Amazon SES Boucne Type
   */
  private $bounce_type;

  /**
   * @var Amazon SES Bounce Sub Type
   */
  private $bounce_sub_type;

  /**
   * @var SMTP Bounce Message
   */
  private $bounced_recipients;

  /**
   * @var Raw Email header from SNS. php Object
   */
  private $headers_raw;

  /**
   * @var Raw Message information. php Object
   */
  private $message_raw;

  /**
   * @var Amazon Message Id
   */
  private $message_id;

  /**
   * @var Amazon SNS Topic ARN
   */
  private $topic_arn;

  /**
   * @var Amazon SES Notification Type
   */
  private $amazon_type;

  /**
   * @var SNS Timestamp
   */
  private $timestamp;

  /**
   * @var amazon cert url for signatures
   */
  private $signature_cert_url;

  /**
   * @var amazon signature for verification
   */
  private $signature;

  /**
   * @var string
   */
  private $localpart;

  /**
   * @var mixed
   */
  private $verp_separator;

  /**
   * @var array
   */
  private $civi_bounce_types;

  /**
   * @var
   */
  private $fail_reason = '';

  /**
   * CRM_Amazonbounceapi_BounceHandler constructor.
   *
   * @param $notification_type
   * @param $bounce_type
   * @param $bounce_sub_type
   * @param $bounced_recipients
   * @param $headers_raw
   * @param $message_raw
   * @param $message_id
   * @param $topic_arn
   * @param $amazon_type
   * @param $timestamp
   * @param $signature
   * @param $signature_cert_url
   */
  public function __construct($params) {

    $this->parse_params($params);
    $this->verp_separator = Civi::settings()->get( 'verpSeparator' );
    $this->localpart = CRM_Core_BAO_MailSettings::defaultLocalpart();
    $this->civi_bounce_types = $this->get_civi_bounce_types();
  }

  /**
   * Runner
   * @return bool
   */
  public function run() {
    if ( ! $this->verify_signature() ) {
      $this->fail_reason = 'Amazon SES Signature Verification failed. Bounce was NOT parsed.';
      $this->log($this->fail_reason);
//      $this->dump_message_content_to_log();
      // for now optional - check if this works first
      //      return FALSE;
    }
    if ($this->amazon_type != 'Notification') {
      $this->fail_reason = "SNS Webhook isn't a notification and wont be parsed here.";
      $this->log($this->fail_reason);
      return FALSE;
    }
    if (in_array( $this->notification_type, ['Bounce'] ) ) {
      $x_400_content_identifier = $this->get_header_value( 'X400-Content-Identifier' );
      if (!empty($x_400_content_identifier)) {
        // TODO custom handling for de.systopia.donrec in here now!
        try{
          $pattern = '/DONREC#(?P<contact_id>[0-9]+)#(?P<contribution_id>[0-9]+)#(?P<timestamp>[0-9]+)#(?P<profile_id>[0-9]+)#/';
          preg_match($pattern, $x_400_content_identifier, $matches);
          $result = civicrm_api3('DonationReceipt', 'handlebounce', [
            'contact_id' => $matches['contact_id'],
            'contribution_id' => $matches['contribution_id'],
            'timestamp' => $matches['timestamp'],
            'profile_id' => $matches['profile_id'],
          ]);
          if($result['is_error'] == '1') {
            $this->log("AmazonBounceApi handle Donation Receipt Bounce API-Error:  -> {$result['error_message']}");
            return FALSE;
          }
          // We are done here. Bounce was parsed
          return TRUE;
        } catch (CRM_Core_Exception $e) {
          $this->log("AmazonBounceApi handle Donation Receipt Bounce. Error:  -> {$e->getMessage()}");
          // do not return here - we might need to parse this bounce the "normal way".
          // Unlikely this will happen, but maybe mass mailings can have that header set as well!
        }
      }
      // start normal bounce handling
      $x_civi_mail_header = $this->get_header_value( 'X-CiviMail-Bounce' );
      if (empty($x_civi_mail_header)) {
        $this->fail_reason = "Failed to extract X-CiviMail-Bounce Header from Bounce message. Might be a transactional bounce, wont process.";
        $this->log($this->fail_reason);
        $this->dump_message_content_to_log();
        return FALSE;
      }
      list( $job_id, $event_queue_id, $hash ) = $this->get_verp_items( $x_civi_mail_header );
      $bounce_params = $this->set_bounce_type_params( [
        'job_id' => $job_id,
        'event_queue_id' => $event_queue_id,
        'hash' => $hash,
      ] );
      if ( CRM_Utils_Array::value( 'bounce_type_id' , $bounce_params ) ) {
        /*
         * This class got renamed in core starting CiviCRM 5.57.
         * We should keep its name, as it would otherwise break backwards compativility
         */
        $bounced = CRM_Mailing_Event_BAO_Bounce::create( $bounce_params );
      }
      return TRUE;
    } else {
      $this->fail_reason = "Error occured parsing the bounce message.";
      $this->log($this->fail_reason);
      $this->dump_message_content_to_log();
      return FALSE;
    }
  }

  /**
   * @return mixed
   */
  public function get_fail_reason() {
    return $this->fail_reason;
  }

  /**
   * CiviCRM log wrapper
   * @param $message
   */
  private function log($message) {
    Civi::log()->debug("AmazonBounceApi (BounceHandler) -> {$message}");
  }

  private function dump_message_content_to_log() {
    $message ="";
    $message .= " | " . $this->notification_type . " | " . $this->bounce_type . " | "
      . $this->bounce_sub_type . " | " . json_encode($this->bounced_recipients) . " | "
      .  json_encode($this->headers_raw) . " | "
      . json_encode($this->message_raw) . " | " . $this->message_id . " | " . $this->topic_arn . " | "
      . $this->amazon_type . " | " . $this->timestamp . " | " . $this->signature . " | " . $this->signature_cert_url;
    Civi::log()->debug("AmazonBounceApi (Message_Dump) -> {$message}");
  }


  /**
   * Verify SNS Message signature.
   *
   * Code reused from https://github.com/mecachisenros/civicrm-ses
   *
   * @see https://docs.aws.amazon.com/sns/latest/dg/SendMessageToHttp.verify.signature.html
   * @return bool $signed true if succesful
   */
  private function verify_signature() {
//    $message ="";
    // static signature, since we only need to parse bounce notifications
    $message_as_json = json_encode($this->message_raw);
    $message = "Message\n{$message_as_json}\n";
    $message .= "MessageId\n{$this->message_id}\n";
    $message .= "Subject\n{$this->get_header_value('Subject')}\n";
    $message .= "Timestamp\n{$this->timestamp}\n";
    $message .= "TopicArn\n{$this->topic_arn}\n";
    $message .= "Type\n{$this->amazon_type}\n";

    // decode SNS signature
    $sns_signature = base64_decode( $this->signature );

    // get certificate from SigningCerURL and extract public key
    $public_key = openssl_get_publickey( file_get_contents( $this->signature_cert_url ) );

    // verify signature
    $signed = openssl_verify( $message, $sns_signature, $public_key, OPENSSL_ALGO_SHA1 );

    if ( $signed && $signed != -1 )
      return true;
    return false;
  }

  /**
   * Get header by name.
   *
   * @param  string $name The header name to retrieve
   * @return string $value The header value
   */
  private function get_header_value( $name ) {
    foreach ( $this->message_raw->mail->headers as $key => $header ) {
        if( $header->name == $name )
        return $header->value;
    }
    return NULL;
    // debug example value
//    return "DONREC#3#1#20220214150355#1#";
  }

  /**
   * Get verp items.
   *
   * @param string $header_value The X-CiviMail-Bounce header
   * @return array $verp_items The verp items [ $job_id, $queue_id, $hash ]
   */
  private function get_verp_items( $header_value ) {
    $verp_items = substr( substr( $header_value, 0, strpos( $header_value, '@' ) ), strlen( $this->localpart ) + 2 );
    return explode( $this->verp_separator, $verp_items );
  }

  /**
   * Get CiviCRM bounce types.
   *
   * @return $array $civi_bounce_types
   */
  private function get_civi_bounce_types() {
    if ( ! empty( $this->civi_bounce_types ) ) return $this->civi_bounce_types;

    $query = 'SELECT id,name FROM civicrm_mailing_bounce_type';
    $dao = CRM_Core_DAO::executeQuery( $query );

    $civi_bounce_types = [];
    while ( $dao->fetch() ) {
      $civi_bounce_types[$dao->id] = $dao->name;
    }

    return $civi_bounce_types;
  }


  /**
   * Set bounce type params.
   *
   * @param array $bounce_params The params array
   * @return array $bounce_params Teh params array
   */
  protected function set_bounce_type_params( $bounce_params ) {
    // hard bounces
    if ( $this->bounce_type == 'Permanent' && in_array( $this->bounce_sub_type, $this->ses_permanent_bounce_types ) )
      switch ( $this->bounce_sub_type ) {
        case 'Undetermined':
          $bounce_params = $this->map_bounce_types( $bounce_params, 'Syntax' );
          break;
        case 'General':
        case 'NoEmail':
        case 'Suppressed':
          $bounce_params = $this->map_bounce_types( $bounce_params, 'Invalid' );
          break;
      }
    // soft bounces
    if ( $this->bounce_type == 'Transient' && in_array( $this->bounce_sub_type, $this->ses_transient_bounce_types ) )
      switch ( $this->bounce_sub_type ) {
        case 'General':
          $bounce_params = $this->map_bounce_types( $bounce_params, 'Syntax' ); // hold_threshold is 3
          break;
        case 'MessageTooLarge':
        case 'MailboxFull':
          $bounce_params = $this->map_bounce_types( $bounce_params, 'Quota' );
          break;
        case 'ContentRejected':
        case 'AttachmentRejected':
          $bounce_params = $this->map_bounce_types( $bounce_params, 'Spam' );
          break;
      }

    return $bounce_params;
  }


  /**
   * Map Amazon bounce types to Civi bounce types.
   *
   * @param  array $bounce_params The params array
   * @param  string $type_to_map_to Civi bounce type to map to
   * @return array $bounce_params The params array
   */
  protected function map_bounce_types( $bounce_params, $type_to_map_to ) {

    $bounce_params['bounce_type_id'] = array_search( $type_to_map_to, $this->civi_bounce_types );
    // it should be one recipient
    $recipient = count( $this->bounced_recipients ) == 1 ? reset( $this->bounced_recipients ) : false;
    if ( $recipient )
      $bounce_params['bounce_reason'] = $recipient->status . ' => ' . $recipient->diagnosticCode;

    return $bounce_params;
  }

  /**
   * Parsing API parameters. All Parameters are mandatory,
   * thus checking/sanitation is not needed
   * @param $params
   */
  private function parse_params($params) {
    $this->message_raw = json_decode($params['message_raw']);
    $this->headers_raw = $this->message_raw->mail->headers;
    $this->notification_type = $this->message_raw->notificationType;
    $this->bounce_type = $this->message_raw->bounce->bounceType;
    $this->bounce_sub_type = $this->message_raw->bounce->bounceSubType;
    $this->bounced_recipients = $this->message_raw->bounce->bouncedRecipients;
    $this->message_id = $params['message_id'];
    $this->topic_arn = $params['topic_arn'];
    $this->amazon_type = $params['amazon_type'];
    $this->timestamp = $params['timestamp'];
    $this->signature = $params['signature'];
    $this->signature_cert_url = $params['signature_cert_url'];
  }

}
