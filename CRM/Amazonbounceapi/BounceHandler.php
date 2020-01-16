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
   * @var X-CiviMail-Bounce header
   */
  private $bounce_recipient_address;

  /**
   * @var SMTP Bounce Message
   */
  private $bounce_diagnostic_code;

  /**
   * @var SMTP error code
   */
  private $bounce_status;

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
   * CRM_Amazonbounceapi_BounceHandler constructor.
   *
   * @param $notification_type
   * @param $bounce_type
   * @param $bounce_sub_type
   * @param $bounce_recipient_address
   * @param $bounce_diagnostic_code
   * @param $bounce_status
   * @param $headers_raw
   * @param $message_raw
   * @param $message_id
   * @param $topic_arn
   * @param $amazon_type
   */
  public function __construct($notification_type, $bounce_type, $bounce_sub_type,
                              $bounce_recipient_address, $bounce_diagnostic_code,
                              $bounce_status, $headers_raw, $message_raw,
                              $message_id, $topic_arn, $amazon_type, $timestamp,
                              $signature, $signature_cert_url) {
    $this->notification_type = $notification_type;
    $this->bounce_type = $bounce_type;
    $this->bounce_sub_type = $bounce_sub_type;
    $this->bounce_recipient_address = $bounce_recipient_address;
    $this->bounce_diagnostic_code = $bounce_diagnostic_code;
    $this->bounce_status = $bounce_status;
    $this->headers_raw = json_decode($headers_raw);
    $this->message_raw = json_decode($message_raw);
    $this->message_id = $message_id;
    $this->topic_arn = $topic_arn;
    $this->amazon_type = $amazon_type;
    $this->timestamp = $timestamp;
    $this->signature = $signature;
    $this->signature_cert_url = $signature_cert_url;
  }

  public function run() {
    if ( ! $this->verify_signature() ) {
      $this->log('Amazon SES Signature Verification failed. Bounce was NOT parsed.');
      $this->dump_message_content_to_log();
      return FALSE;
    }

  }

  /**
   * CiviCRM log wrapper
   * @param $message
   */
  private function log($message) {
    CRM_Core_Error::debug_log_message("AmazonBounceApi (BounceHandler) -> {$message}");
  }

  private function dump_message_content_to_log() {
    $message .+ " | " . $this->notification_type . " | " . $this->bounce_type . " | "
      . $this->bounce_sub_type . " | " . $this->bounce_recipient_address . " | "
      . $this->bounce_diagnostic_code . " | " . $this->bounce_status . " | " . json_encode($this->headers_raw) . " | "
      . json_encode($this->message_raw) . " | " . $this->message_id . " | " . $this->topic_arn . " | "
      . $this->amazon_type . " | " . $this->timestamp . " | " . $this->signature . " | " . $this->signature_cert_url;
    CRM_Core_Error::debug_log_message("AmazonBounceApi (Message_Dump) -> {$message}");
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
    // static signature, since we only need to parse bounce notifications
    $message .= "Message\n{$this->message_raw}\n";
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
  }

}
