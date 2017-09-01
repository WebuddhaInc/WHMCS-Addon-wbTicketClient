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

    /**
     * Stage
     */
    global $_ADMINLANG;
    $adminId   = Session::get('adminid');
    $ticket_id = (int)@$_REQUEST['id'];

    /**
     * Confirm we're valid
     */
    if ($adminId && $ticket_id) {

      /**
       * Load Database
       */
      if( file_exists(ROOTDIR.'/modules/addons/wbDatabase.php') )
        require_once(ROOTDIR.'/modules/addons/wbDatabase.php');
      $dbh = wbDatabase::getInstance();

      /**
       * HTML Container
       * Inline styles - lazy, small
       */
      $adminTemplate = $dbh->runQuery("SELECT template FROM tbladmins WHERE id = " . $adminId)->getValue();
      $html = array(
        ($adminTemplate == 'v4'
          ? '<style>.addon_wbticketclient{position:relative;float:right;margin-right:.5rem;z-index:1;}</style>'
          : '<style>.addon_wbticketclient{position:relative;float:right;margin-top:-1rem;z-index:1;}</style>'
          ),
        '<div class="addon_wbticketclient">'
        );

      /**
       * Load Ticket
       */
      if ($ticket = $dbh->runQuery("SELECT * FROM tbltickets WHERE id = " . $ticket_id)->getObject()) {

        /**
         * Import the Language
         */
        $adminLang = $dbh->runQuery("SELECT language FROM tbladmins WHERE id = " . $adminId)->getValue();
        if ($adminLang && file_exists(__DIR__ . '/lang/'.$adminLang.'.php'))
          include __DIR__ . '/lang/'.$adminLang.'.php';
        else
          include __DIR__ . '/lang/english.php';

        /**
         * Are we orphaned?
         */
        if (!$ticket->userid) {

          /**
           * Was Client/Contact Assignment requested
           * Update ticket, ticketreplies with userid and cc (contact email)
           */
          if (
            $user_id = (int)@$_REQUEST['assign_to_client']
            || $contact_id = (int)@$_REQUEST['assign_to_contact']
            ) {
            $cc_email - null;
            if ($contact_id) {
              if ($contact = $dbh->runQuery("SELECT * FROM tblcontacts WHERE id = ". (int)$contact_id)->getObject()){
                $user_id = $contact->userid;
                $cc_email = $contact->email;
              }
            }
            if ($user_id) {
              $user = $dbh->runQuery("
                SELECT *
                FROM tblclients
                WHERE id = ". (int)$user_id)->getObject();
              if ($user) {
                $dbh->runQuery("
                  UPDATE tbltickets
                  SET userid = ". (int)$user_id ."
                    ". ($cc_email ? ", cc = IF(length(cc) > 0, IF(find_in_set('". $dbh->getEscaped($cc_email) ."', cast(cc as char)) > 0,cc,CONCAT(cc,',','". $dbh->getEscaped($cc_email) ."')), '". $dbh->getEscaped($cc_email) ."')" : '') ."
                  WHERE id = ". (int)$ticket_id ."
                  ");
                $dbh->runQuery("
                  UPDATE tblticketreplies
                  SET userid = ". (int)$user_id ."
                    ". ($cc_email ? ", cc = IF(length(cc) > 0, IF(find_in_set('". $dbh->getEscaped($cc_email) ."', cast(cc as char)) > 0,cc,CONCAT(cc,',','". $dbh->getEscaped($cc_email) ."')), '". $dbh->getEscaped($cc_email) ."')" : '') ."
                  WHERE tid = ". (int)$ticket_id ."
                    AND userid = 0
                    AND email = '" . $dbh->getEscaped($ticket->email) ."'
                  ");
              }
            }
            header('Location: supporttickets.php?action=view&id='. $ticket_id);
          }

          /**
           * Was a Creation requested
           * Create new user (directly since we don't have all required information)
           * Update ticket, ticketreplies with the userid
           */
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

          /**
           * Make Recommendations
           */
          else {

            /**
             * We found a client with the same email
             */
            if ($user = $dbh->runQuery("SELECT * FROM tblclients WHERE email = '". $dbh->getEscaped($ticket->email) . "'")->getObject()) {
              $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&assign_to_client='. $user->id .'" class="btn btn-sm btn-primary">'. sprintf($_ADMINLANG['wbticketclient']['assign_to_client'], $user->id, trim($user->firstname.' '.$user->lastname)) .'</a>';
            }

            /**
             * We found a contact with the same email
             */
            else if ($contact = $dbh->runQuery("SELECT * FROM tblcontacts WHERE email = '". $dbh->getEscaped($ticket->email) . "'")->getObject()) {
              $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&assign_to_contact='. $contact->id .'" class="btn btn-sm btn-primary">'. sprintf($_ADMINLANG['wbticketclient']['assign_to_contact'], $contact->id, trim($contact->firstname.' '.$contact->lastname)) .'</a>';
            }

            /**
             * Offer to create a new client
             */
            else {
              $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&create_client=1" class="btn btn-sm btn-primary">'. $_ADMINLANG['wbticketclient']['create_client'] .'</a>';
            }

          }
        }

        /**
         * Else we're alerady linked
         */
        elseif ($user = $dbh->runQuery("SELECT * FROM tblclients WHERE id = " . $ticket->userid)->getObject()) {

          /**
           * Request to unlink client from ticket
           */
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

          /**
           * Offer to unlink from client
           */
          $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&unlink_client=1" class="btn btn-sm btn-warning">'. $_ADMINLANG['wbticketclient']['unlink_client'] .'</a>';

        }
      }

      /**
       * Closeout the container
       */
      $html[] = '</div>';

      /**
       * Return outpu
       */
      return implode("\n", $html);

    }

  });
