<?php
/**

  wbTicketClient
  (c)2017 Webuddha.com, The Holodyn Corporation - All Rights Reserved

**/

use WHMCS\Session;
use WHMCS\Database\Capsule;

defined("WHMCS") or die("wbTicketClient Error: Invalid File Access");

/**
 * [wbticketclient_config description]
 * @return [type] [description]
 */

  function wbticketclient_config() {

    global $_ADMINLANG;

    /**
     * Import the Language
     */
    $adminLang = Capsule::table('tbladmins')->where('id', Session::get('adminid'))->pluck('language')->first();
    if ($adminLang && file_exists(__DIR__ . '/lang/'.$adminLang.'.php'))
      include __DIR__ . '/lang/'.$adminLang.'.php';
    else
      include __DIR__ . '/lang/english.php';

    /**
     * Get Custom Fields
     */
    $customFields = Capsule::table('tblcustomfields')->where('type','client')->pluck('fieldname','id')->all();
    $customFieldOptions = array('0' => '--');
    foreach ($customFields AS $key => $val)
      $customFieldOptions[$key] = $val;

    /**
     * Build Config
     */
    $configarray = array(
      "name"        => "wbTicketClient",
      "description" => "",
      "version"     => "0.4.0",
      "author"      => "Holodyn, Inc.",
      "language"    => "english",
      "fields"      => array(
        'clientField' => array(
          'FriendlyName' => $_ADMINLANG['wbticketclient']['config_customfield_name'],
          'Type'         => 'dropdown',
          'Options'      => $customFields,
          'Default'      => 0
          // 'Multiple'     => false,
          // 'Size'         => 1
          )
        )
    );
    return $configarray;

  }
