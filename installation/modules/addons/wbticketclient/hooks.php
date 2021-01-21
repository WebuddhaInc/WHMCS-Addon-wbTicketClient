<?php //o!SYm=#5pM+n
/**

  wbTicketClient
  (c)2017 Webuddha.com, The Holodyn Corporation - All Rights Reserved

**/

defined("WHMCS") or die("wbTicketClient Error: Invalid File Access");

/**
 * WHMCS Hook: AdminAreaViewTicketPage
 */

add_hook('AdminAreaViewTicketPage', 1, function($vars) {
  require_once __DIR__ . '/wbticketclient.helper.php';
  return wbTicketClient_Helper::WHMCS_Hook_AdminAreaViewTicketPage($vars);
});
