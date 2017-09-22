<?php
/**

  wbTicketClient
  (c)2017 Webuddha.com, The Holodyn Corporation - All Rights Reserved

**/

defined("WHMCS") or die("wbTicketClient Error: Invalid File Access");

/**
 * [wbticketclient_config description]
 * @return [type] [description]
 */

  function wbticketclient_config() {
    $configarray = array(
      "name"        => "wbTicketClient",
      "description" => "",
      "version"     => "0.1.0",
      "author"      => "Holodyn, Inc.",
      "language"    => "english",
      "fields"      => array()
    );
    return $configarray;
  }
