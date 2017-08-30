<?php
/**

  wbTicketClient
  (c)2017 Webuddha.com, The Holodyn Corporation - All Rights Reserved

**/

defined("WHMCS") or die("wbTicketClient Error: Invalid File Access");

/**
 * Debug
 */

  if( !function_exists('inspect') ){
    function inspect(){
      echo '<pre>' . print_r(func_get_args(), true) . '</pre>';
    }
  }

/**
 * WHMCS Hook: AdminAreaViewTicketPage
 */

  use WHMCS\Session;
  use WHMCS\Ticket\Watchers;
  add_hook('AdminAreaViewTicketPage', 1, function($vars) {
    global $_ADMINLANG;
    $html = array('<div class="addon_wbticketclient" style="position:relative;float:right;margin-top:-1rem;z-index:1;">');
    $adminId   = Session::get('adminid');
    $ticket_id = (int)@$_REQUEST['id'];
    if ($adminId && $ticket_id) {
      if( file_exists(ROOTDIR.'/modules/addons/wbDatabase.php') )
        require_once(ROOTDIR.'/modules/addons/wbDatabase.php');
      $dbh = wbDatabase::getInstance();
      if ($ticket = $dbh->runQuery("SELECT * FROM tbltickets WHERE id = " . $ticket_id)->getObject()) {
        $adminLang = $dbh->runQuery("SELECT language FROM tbladmins WHERE id = " . $adminId)->getValue();
        if ($adminLang && file_exists(__DIR__ . '/lang/'.$adminLang.'.php'))
          include __DIR__ . '/lang/'.$adminLang.'.php';
        else
          include __DIR__ . '/lang/english.php';
        if (!$ticket->userid) {
          if ($user_id = (int)@$_REQUEST['assign_to_client']) {
            $dbh->runUpdate('tbltickets', array(
              'userid' => $user_id
              ), array(
              "id = " . $ticket_id
              ));
            $dbh->runUpdate('tblticketreplies', array(
              'userid' => $user_id
              ), array(
              "tid = " . $ticket_id,
              "userid = 0",
              "email = '" . $dbh->getEscaped($ticket->email) ."'"
              ));
            header('Location: supporttickets.php?action=view&id='. $ticket_id);
          }
          elseif (@$_REQUEST['create_client']) {
            $name = explode(' ', $ticket->name);
            if (count($name) > 1) {
              $name_last  = array_pop($name);
              $name_first = implode(' ',$name);
            }
            else {
              $name_first = $ticket->name;
            }
            $adminUsername = $dbh->runQuery("SELECT username FROM tbladmins WHERE id = " . $adminId)->getValue();
            $results = localAPI('EncryptPassword', array('password2' => (time() . rand(10000, 99999))), $adminUsername);
            $result = $dbh->runInsert('tblclients', array(
              'firstname'  => $name_first ?: '',
              'lastname'   => $name_last ?: '',
              'email'      => $ticket->email,
              'password'   => $results['password'],
              'status'     => 'Active',
              'created_at' => date('Y-m-d H:i:s')
              ));
            if ($result && $user_id = $dbh->getLastID()) {
              $ticket_ids = $dbh->runQuery("
                SELECT id
                FROM tbltickets
                WHERE userid = 0
                  AND email = '" . $dbh->getEscaped($ticket->email) ."'
                ")->getObjects();
              if ($ticket_ids) {
                foreach ($ticket_ids AS $ticket_ids_row){
                  $dbh->runUpdate('tbltickets', array(
                    'userid' => $user_id
                    ), array(
                    "id = " . $ticket_ids_row->id
                    ));
                  $dbh->runUpdate('tblticketreplies', array(
                    'userid' => $user_id
                    ), array(
                    "tid = " . $ticket_ids_row->id,
                    "userid = 0",
                    "email = '" . $dbh->getEscaped($ticket->email) ."'"
                    ));
                }
              }
            }
            header('Location: supporttickets.php?action=view&id='. $ticket_id);
          }
          else {
            if ($user = $dbh->runQuery("SELECT * FROM tblclients WHERE email = '". $dbh->getEscaped($ticket->email) . "'")->getObject()) {
              $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&assign_to_client='. $user->id .'" class="btn btn-sm btn-primary">'. sprintf($_ADMINLANG['wbticketclient']['assign_to_client'], $user->id, trim($user->firstname.' '.$user->lastname)) .'</a>';
            }
            else {
              $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&create_client=1" class="btn btn-sm btn-primary">'. $_ADMINLANG['wbticketclient']['create_client'] .'</a>';
            }
          }
        }
        elseif ($user = $dbh->runQuery("SELECT * FROM tblclients WHERE id = " . $ticket->userid)->getObject()) {
          if (@$_REQUEST['unlink_client']) {
            $ticket_ids = $dbh->runQuery("
              SELECT id
              FROM tbltickets
              WHERE userid = ". $user->id ."
              ")->getObjects();
            if ($ticket_ids) {
              foreach ($ticket_ids AS $ticket_ids_row){
                $dbh->runUpdate('tbltickets', array(
                  'userid' => 0
                  ), array(
                  "id = " . $ticket_ids_row->id
                  ));
                $dbh->runUpdate('tblticketreplies', array(
                  'userid' => 0,
                  'name' => trim($user->firstname.' '.$user->lastname),
                  ), array(
                  "tid = " . $ticket_ids_row->id,
                  "userid = " . $user->id
                  ));
              }
            }
            header('Location: supporttickets.php?action=view&id='. $ticket_id);
          }
          $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&unlink_client=1" class="btn btn-sm btn-warning">'. $_ADMINLANG['wbticketclient']['unlink_client'] .'</a>';
        }
      }
    }
    $html[] = '</div>';
    return implode("\n", $html);
  });
