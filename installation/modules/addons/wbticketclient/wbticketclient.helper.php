<?php
/**

  wbTicketClient
  (c)2017 Webuddha.com, The Holodyn Corporation - All Rights Reserved

**/

use WHMCS\Session;
use WHMCS\Database\Capsule;

/*
inspect(Capsule::getInstance()->getConnection()->getPdo()->quote('dhunt@website.com'));
die(__LINE__.': '.__FILE__);
inspect(Capsule::raw('123'), get_class_methods(Capsule::table('tbladmins')));
inspect((Capsule::table('tbladmins')->selectRaw('*')->get()));
inspect(get_class_methods(Capsule::getInstance()->getConnection()));die(__LINE__.': '.__FILE__);
inspect(Capsule::table('tblticketreplies')->first());die(__LINE__.': '.__FILE__);
*/

/**
 * Debug
 */

  if( !function_exists('inspect') ){
    function inspect(){
      echo '<pre>' . print_r(func_get_args(), true) . '</pre>';
    }
  }

/**
 * Class Definition
 */

class wbTicketClient_Helper {

  static public function WHMCS_Hook_AdminAreaViewTicketPage($vars) {

    /**
     * Stage
     */
    global $_ADMINLANG;
    $adminId = Session::get('adminid');
    $ticket_id = isset($vars['ticketid']) ? $vars['ticketid'] : (int)@$_REQUEST['id'];

    /**
     * Confirm we're valid
     */
    if ($adminId && $ticket_id) {

      /**
       * HTML Container
       * Inline styles - lazy, small
       */

      $adminTemplate = Capsule::table('tbladmins')->where('id', $adminId)->pluck('template')->first();
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
      if ($ticket = Capsule::table('tbltickets')->where('id', $ticket_id)->first()) {

        /**
         * Import the Language
         */
        $adminLang = Capsule::table('tbladmins')->where('id', $adminId)->pluck('language')->first();
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
            preg_match('/^\d+\:\d+$/', $user_pair = @$_REQUEST['assign_to_user'])
            ||
            ($client_id = (int)@$_REQUEST['assign_to_client'])
            ||
            ($contact_id = (int)@$_REQUEST['assign_to_contact'])
            ) {
            $cc_email = null;
            if (
              $user_pair
              && ($user_pair = explode(':', $user_pair))
              && ($user = Capsule::table('tblusers')->join('tblusers_clients', 'tblusers.id', '=', 'tblusers_clients.auth_user_id')->where(array('tblusers.id' => reset($user_pair), 'tblusers_clients.client_id' => end($user_pair)))->select('tblusers.email','tblusers_clients.client_id')->first())
              ){
              $client_id = $user->client_id;
              $cc_email = $user->email;
            }
            if (
              $contact_id &&
              ($contact = Capsule::table('tblcontacts')->where('id', (int)$contact_id)->first())
              ){
              $client_id = $contact->userid;
              $cc_email = $contact->email;
            }
            if (
              $client_id &&
              ($client = Capsule::table('tblclients')->where('id', (int)$client_id)->first())
              ) {
              $tblTicketUpdates = array(
                'userid' => (int)$client_id
                );
              $tblTicketReplyUpdates = array(
                'userid' => (int)$client_id
                );
              // Create Contact
              if (empty($contact_id)) {
                $name = array_filter(explode(' ', $ticket->name, 2), 'strlen');
                $name_first = reset($name) ?: 'n/a';
                $name_last  = count($name) > 1 ? end($name) : 'n/a';
                $adminUsername = Capsule::table('tbladmins')->where('id', $adminId)->pluck('username')->first();
                $createContactResults = localAPI('AddContact', array(
                  'clientid'  => $client_id,
                  'firstname' => $name_first,
                  'lastname'  => $name_last,
                  'email'     => $ticket->email
                  ), $adminUsername);
                $contact_id = $createContactResults['contactid'];
                $cc_email = $ticket->email;
              }
              if ($cc_email) {
                $cc_email_quoted = Capsule::getInstance()->getConnection()->getPdo()->quote($cc_email);
                $tblTicketUpdates['cc'] = Capsule::raw("IF(length(cc) > 0, IF(find_in_set({$cc_email_quoted}, cast(cc as char)) > 0,cc,CONCAT(cc,',',{$cc_email_quoted})), {$cc_email_quoted})");
              }
              if ($contact_id) {
                $tblTicketUpdates['contactid'] = $contact_id;
                $tblTicketReplyUpdates['contactid'] = $contact_id;
              }
              Capsule::table('tbltickets')
                ->where('id', (int)$ticket_id)
                ->update($tblTicketUpdates);
              Capsule::table('tblticketreplies')
                ->where(array(
                  'tid' => (int)$ticket_id,
                  'userid' => 0,
                  'email' => $ticket->email
                  ))
                ->update($tblTicketReplyUpdates);
            }
            header('Location: supporttickets.php?action=view&id='. $ticket_id);
          }

          /**
           * Was a Creation requested
           * Create new user (directly since we don't have all required information)
           * Update ticket, ticketreplies with the userid
           */
          elseif (@$_REQUEST['create_client']) {

            $name = array_filter(explode(' ', $ticket->name, 2), 'strlen');
            $name_first = reset($name) ?: 'n/a';
            $name_last  = count($name) > 1 ? end($name) : 'n/a';
            $adminUsername = Capsule::table('tbladmins')->where('id', $adminId)->pluck('username')->first();
            $password2 = (time() . rand(10000, 99999));
            // Create Client
            $passwordResults = localAPI('EncryptPassword', array('password2' => $password2), $adminUsername);
            $client_id = Capsule::table('tblclients')->insertGetId(array(
              'firstname'  => $name_first,
              'lastname'   => $name_last,
              'email'      => $ticket->email,
              'password'   => $passwordResults['password'],
              'status'     => 'Active',
              'created_at' => date('Y-m-d H:i:s')
              ));
            if ($client_id) {
              // Create Client User
              $createUserResults = localAPI('AddUser', array(
                'firstname' => $name_first,
                'lastname'  => $name_last,
                'email'     => $ticket->email,
                'password2' => $password2,
                ), $adminUsername);
              if ($createUserResults)
                $client_userRefId = Capsule::table('tblusers_clients')->insertGetId(array(
                  'auth_user_id' => $createUserResults['user_id'],
                  'client_id'    => $client_id,
                  'owner'        => 1,
                  'created_at'   => date('Y-m-d H:i:s'),
                  'updated_at'   => date('Y-m-d H:i:s')
                  ));
              // Apply to Ticket
              $ticket_ids = Capsule::table('tbltickets')->where(array(
                'userid' => 0,
                'email'  => $ticket->email
                ))->pluck('id')->all();
              if ($ticket_ids) {
                foreach ($ticket_ids AS $ticket_ids_id){
                  $res = Capsule::table('tbltickets')->where(array(
                    'id' => $ticket_ids_id
                    ))->update(array(
                    'userid' => $client_id
                    ));
                  $res = Capsule::table('tblticketreplies')->where(array(
                    'tid'    => $ticket_ids_id,
                    'userid' => 0,
                    'email'  => $ticket->email
                    ))->update(array(
                    'userid' => $client_id
                    ));
                }
              }
            }
            header('Location: supporttickets.php?action=view&id='. $ticket_id);
          }

          /**
           * Make Recommendations
           */
          else if($ticketEmail = strtolower($ticket->email)) {

            $clientsFound = array();
            $htmlMatches = array();

            /**
             * We found a contact with the same email
             */
            if ($contact = Capsule::table('tblcontacts')->where('email', $ticketEmail)->first()) {
              $clientsFound[] = $contact->userid;
              $contactName = strlen($contact->firstname) || strlen($contact->lastname) ? trim($contact->firstname.' '.$contact->lastname) : 'N/A';
              $htmlMatches[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&assign_to_contact='. $contact->id .'" class="btn btn-sm btn-primary">'. sprintf($_ADMINLANG['wbticketclient']['assign_to_contact'], $contact->userid, $contactName) .'</a>';
            }

            /**
             * We found a client with the same email
             */
            if ($client = Capsule::table('tblclients')->where('email', $ticketEmail)->first()) {
              $clientsFound[] = $client->id;
              $clientName = strlen($client->firstname) || strlen($client->lastname) ? trim($client->firstname.' '.$client->lastname) : 'N/A';
              $htmlMatches[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&assign_to_client='. $client->id .'" class="btn btn-sm btn-primary">'. sprintf($_ADMINLANG['wbticketclient']['assign_to_client'], $client->id, $clientName) .'</a>';
            }

            /**
             * We found a user with the same email
             */
            if ($users = Capsule::table('tblusers')->join('tblusers_clients', 'tblusers.id', '=', 'tblusers_clients.auth_user_id')->where('tblusers.email', $ticketEmail)->select('tblusers.id','tblusers.first_name','tblusers.last_name','tblusers_clients.client_id')->get()->all()) {
              foreach ($users AS $user) {
                if (!in_array($user->client_id, $clientsFound)) {
                  $clientsFound[] = $user->client_id;
                  $userName = strlen($user->first_name) || strlen($user->last_name) ? trim($user->first_name.' '.$user->last_name) : 'N/A';
                  $htmlMatches[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&assign_to_user='. $user->id.':'.$user->client_id .'" class="btn btn-sm btn-primary">'. sprintf($_ADMINLANG['wbticketclient']['assign_to_user'], $user->client_id, $userName) .'</a>';
                }
              }
            }

            /**
             * Lookup Custom Field Domains
             * or Offer to create a new client
             */
            if ($emailDomain = preg_replace('/^.*\@(.*)$/', '$1', $ticketEmail)) {
              if ($customFieldKey = Capsule::table('tbladdonmodules')->where(array(
                'module' => 'wbticketclient',
                'setting' => 'clientField'
                ))->pluck('value')->first()) {
                $clientDomainValues = Capsule::table('tblcustomfieldsvalues')->where('fieldid', $customFieldKey)->get()->all();
                foreach ($clientDomainValues AS $clientDomainValuesOption) {
                  $domainValues = array_filter(explode("\n", $clientDomainValuesOption->value), 'strlen');
                  array_walk($domainValues, function(&$val){$val = preg_replace('/[^A-Za-z0-9\.\-]/', '', strtolower($val));});
                  if (
                    in_array($emailDomain, $domainValues)
                    && ($client = Capsule::table('tblclients')->where('id', $clientDomainValuesOption->relid)->first())
                    && !in_array($client->id, $clientsFound)
                    ) {
                    $clientsFound[] = $client->id;
                    $clientName = strlen($client->firstname) || strlen($client->lastname) ? trim($client->firstname.' '.$client->lastname) : 'N/A';
                    $htmlMatches[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&assign_to_client='. $client->id .'" class="btn btn-sm btn-primary">'. sprintf($_ADMINLANG['wbticketclient']['assign_to_client_domain'], $client->id, $clientName) .'</a>';
                  }
                }
              }
            }

            // Matches Found
            if ($htmlMatches){
              $html[] = '<div class="btn-group"><div class="btn btn-sm btn-assignToGroup">'.$_ADMINLANG['wbticketclient']['assign_to_group'].'</div>' . implode('', $htmlMatches) . '</div>';
              $html[] = '<style type="text/css">.btn-assignToGroup, .btn-assignToGroup:hover{background:white;border:1px solid rgb(204, 204, 204);}</style>';
            }

            // Create Alternatively
            $html[] = '<a href="supporttickets.php?action=view&id='. $ticket_id .'&create_client=1" class="btn btn-sm '.($htmlMatches?'btn-default':'btn-primary').'">'. $_ADMINLANG['wbticketclient']['create_client'] .'</a>';

          }
        }

        /**
         * Else we're alerady linked
         */
        elseif ($client = Capsule::table('tblclients')->where('id', $ticket->userid)->first()) {

          /**
           * Request to unlink client from ticket
           */
          if (@$_REQUEST['unlink_client']) {
            $name_quoted = Capsule::getInstance()->getConnection()->getPdo()->quote(trim($client->firstname.' '.$client->lastname));
            $email_quoted = Capsule::getInstance()->getConnection()->getPdo()->quote($client->email);
            Capsule::table('tbltickets')->where(array(
              'id' => $ticket->id
              ))->update(array(
              'userid' => 0,
              'name'   => Capsule::raw("IF(length(name) > 0, name, {$name_quoted})"),
              'email'  => Capsule::raw("IF(length(email) > 0, email, {$email_quoted})")
              ));
            Capsule::table('tblticketreplies')->where(array(
              'tid'    => $ticket_ids_row->id,
              'userid' => $client->id
              ))->update(array(
              'userid' => 0,
              'name'   => Capsule::raw("IF(length(name) > 0, name, {$name_quoted})"),
              'email'  => Capsule::raw("IF(length(email) > 0, email, {$email_quoted})")
              ));
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

  }

}