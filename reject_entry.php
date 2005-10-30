<?php
include_once 'includes/init.php';
require ( 'includes/classes/WebCalMailer.class' );
$mail = new WebCalMailer;

$error = "";

if ( $readonly == 'Y' ) {
  $error = translate("You are not authorized");
}

// Allow administrators to approve public events
if ( $PUBLIC_ACCESS == "Y" && ! empty ( $public ) && $is_admin )
  $app_user = "__public__";
else
  $app_user = ( $is_assistant || $is_nonuser_admin ? $user : $login );

// If User Access Control is enabled, we check to see if they are
// allowed to approve for the specified user.
if ( access_is_enabled () && ! empty ( $user ) &&
  $user != $login ) {
  if ( access_can_approve_user_calendar ( $user ) )
    $app_user = $user;
}
$type = getGetValue ( 'type' );
if ( ! empty ( $type ) && ( $type == 'T' || $type == 'N' ) ) {
  $log_reject =  LOG_REJECT_T;
  $view_type = "view_task";
} else {
  $log_reject =  LOG_REJECT;
  $view_type = "view_entry";	
}
if ( empty ( $error ) && $id > 0 ) {
  if ( ! dbi_query ( "UPDATE webcal_entry_user SET cal_status = 'R' " .
    "WHERE cal_login = '$app_user' AND cal_id = $id" ) ) {
    $error = translate("Error approving event") . ": " . dbi_error ();
  } else {
    activity_log ( $id, $login, $app_user, $log_reject, "" );
  }

  // Update any extension events related to this one.
  $res = dbi_query ( "SELECT cal_id FROM webcal_entry " .
    "WHERE cal_ext_for_id = $id" );
  if ( $res ) {
    if ( $row = dbi_fetch_row ( $res ) ) {
      $ext_id = $row[0];
      if ( ! dbi_query ( "UPDATE webcal_entry_user SET cal_status = 'R' " .
        "WHERE cal_login = '$app_user' AND cal_id = $ext_id" ) ) {
        $error = translate("Error approving event") . ": " . dbi_error ();
      } 
    }
    dbi_free_result ( $res );
  }

  // Email participants to notify that it was rejected.
  // Get list of participants
  $sql = "SELECT cal_login FROM webcal_entry_user WHERE cal_id = $id and cal_status = 'A'";
  //echo $sql."<br />";
  $res = dbi_query ( $sql );
  if ( $res ) {
    while ( $row = dbi_fetch_row ( $res ) )
      $partlogin[] = $row[0];
    dbi_free_result($res);
  }

  // Get the name of the event
  $sql = "SELECT cal_name, cal_description, cal_date, cal_time FROM webcal_entry WHERE cal_id = $id";
  $res = dbi_query ( $sql );
  if ( $res ) {
    $row = dbi_fetch_row ( $res );
    $name = $row[0];
    $description = $row[1];
    $fmtdate = $row[2];
    $time = $row[3];
    dbi_free_result ( $res );
  }

  if ($time != '-1') {
    $hour = substr($time,0,2);
    $minute = substr($time,2,2);
  } else {
   $hour =  $minute = 0;
 }
  $eventstart = $fmtdate .  sprintf( "%06d", ( $hour * 10000 ) + ( $minute * 100 ) );
  for ( $i = 0; $i < count ( $partlogin ); $i++ ) {
    // does this user want email for this?
    $send_user_mail = get_pref_setting ( $partlogin[$i],
      "EMAIL_EVENT_REJECTED" );
    $htmlmail = get_pref_setting ( $partlogin[$i], "EMAIL_HTML" );
    user_load_variables ( $partlogin[$i], "temp" );
    $user_TIMEZONE = get_pref_setting ( $partlogin[$i], "TIMEZONE" );
    $user_TZ = get_tz_offset ( $user_TIMEZONE, '', $eventstart );
    $user_language = get_pref_setting ( $partlogin[$i], "LANGUAGE" );
    if ( $send_user_mail == "Y" && strlen ( $tempemail ) &&
      $SEND_EMAIL != "N" ) {
        if ( empty ( $user_language ) || ( $user_language == 'none' )) {
          reset_language ( $LANGUAGE );
        } else {
          reset_language ( $user_language );
        }
        $msg = translate("Hello") . ", " . $tempfullname . ".\n\n" .
        translate("An appointment has been rejected by") .
        " " . $login_fullname .  ".\n\n" .
        translate("The subject was") . " \"" . $name . " \"\n" .
        translate("The description is") . " \"" . $description . "\"\n" .
        translate("Date") . ": " . date_to_str ( $fmtdate ) . "\n" .
        ( ( empty ( $hour ) && empty ( $minute ) ? "" : translate("Time") . ": " .
        // Display using user's GMT offset and display TZID
        display_time ( $eventstart, 2, '' , $user_TIMEZONE ) ) ). "\n\n";
      if ( ! empty ( $SERVER_URL ) ) {
				//DON'T change & to &amp; here. email will handle it
        $url = $SERVER_URL .  $view_type . ".php?id=" .  $id . "&em=1";
				if ( $htmlmail == 'Y' ) {
					$url =  activate_urls ( $url ); 
				}
        $msg .= "\n\n" . $url;
      }

      $from = $EMAIL_FALLBACK_FROM;
      if ( strlen ( $login_email ) ) $from = $login_email;

			if ( strlen ( $from ) ) {
				$mail->From = $from;
				$mail->FromName = $login_fullname;
			} else {
				$mail->From = $login_fullname;
			}
			$mail->IsHTML( $htmlmail == 'Y' ? true : false );
			$mail->AddAddress( $tempemail, $tempfullname );
			$mail->Subject = translate($APPLICATION_NAME) . " " .
				translate("Notification") . ": " . $name;
			$mail->Body  = $htmlmail == 'Y' ? nl2br ( $msg ) : $msg;
			$mail->Send();
			$mail->ClearAll();

      activity_log ( $id, $login, $partlogin[$i], LOG_NOTIFICATION,
        "Rejected by $app_user" );
    }
  }
}

if ( empty ( $error ) ) {
  if ( ! empty ( $ret ) && $ret == "listall" )
    do_redirect ( "list_unapproved.php" );
  else if (  ! empty ( $ret ) &&  $ret == "list" )
    do_redirect ( "list_unapproved.php?user=$app_user" );
  else
    do_redirect ( $view_type . ".php?id=$id&amp;user=$app_user" );
  exit;
}
print_header ();
echo "<h2>" . translate("Error") . "</h2>\n";
echo "<p>" . $error . "</p>\n";
print_trailer ();
?>
