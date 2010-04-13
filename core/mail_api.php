<?php
	# Mantis - a php based bugtracking system
	# Copyright (C) 2002 - 2004  Mantis Team   - mantisbt-dev@lists.sourceforge.net
	# Copyright (C) 2004  Gerrit Beine - gerrit.beine@pitcom.de
	# Copyright (C) 2007  Rolf Kleef - rolf@drostan.org (IMAP)
	# This program is distributed under the terms and conditions of the GPL
	# See the README and LICENSE files for details

	# --------------------------------------------------------
	# $Id: mail_api.php,v 1.14 2009/12/01 19:17:02 SL-Server\SC Kruiper Exp $
	# --------------------------------------------------------

	require_once( 'bug_api.php' );
	require_once( 'bugnote_api.php' );
	require_once( 'user_api.php' );
	require_once( 'file_api.php' );

	require_once( 'custom_file_api.php' );

	# This page receives an E-Mail via POP3 and generates an Report

	require_once( 'Net/POP3.php' );
	require_once( 'Net/IMAP_1.0.3.php' );
	require_once( 'Mail/Parser.php' );

	# --------------------
	# return all mailboxes
	#  return an empty array if there are none
	function mail_get_mailboxes()
	{
		$t_mailboxes = plugin_config_get( 'mailboxes', array() );

		return $t_mailboxes;
	}

	# --------------------
	# return all mails for an mailbox
	#  return an empty array if there are no new mails
	function mail_process_all_mails( &$p_mailbox, $p_test_only = false )
	{
		$t_mailbox_type = ( ( isset( $p_mailbox[ 'mailbox_type' ] ) ) ? $p_mailbox[ 'mailbox_type' ] : NULL );

		if ( $t_mailbox_type === 'IMAP' )
		{
			$t_result = mail_process_all_mails_imap( $p_mailbox, $p_test_only );
		}
		else // this defaults to the pop3 mailbox type
		{
			$t_result = mail_process_all_mails_pop3( $p_mailbox, $p_test_only );
		}

		return( $t_result );
	}

	# --------------------
	# return all mails for an pop3 mailbox
	#  return an empty array if there are no new mails
	function mail_process_all_mails_pop3( &$p_mailbox, $p_test_only = false )
	{
		$t_mail_fetch_max	= plugin_config_get( 'mail_fetch_max' );

		$t_mailbox_connection = &new Net_POP3();
		$t_mailbox_hostname = explode( ':', $p_mailbox[ 'mailbox_hostname' ], 2 );
		$t_mailbox_username = $p_mailbox[ 'mailbox_username' ];
		$t_mailbox_password = base64_decode( $p_mailbox[ 'mailbox_password' ] );
		$t_mailbox_auth_method = ( ( !empty( $p_mailbox[ 'mailbox_auth_method' ] ) ) ? $p_mailbox[ 'mailbox_auth_method' ] : 'USER' );
		$t_mailbox_connection->connect( $t_mailbox_hostname[ 0 ], ( ( empty( $t_mailbox_hostname[ 1 ] ) || (int) $t_mailbox_hostname[ 1 ] === 0 ) ? 110 : (int) $t_mailbox_hostname[ 1 ] ) );
		$t_result = $t_mailbox_connection->login( $t_mailbox_username, $t_mailbox_password, $t_mailbox_auth_method );

		if ( $p_test_only === false )
		{
			if ( PEAR::isError( $t_result ) )
			{
				echo "\n\nerror: " . $p_mailbox[ 'mailbox_description' ] . "\n";
				echo $t_result->toString();
				return( $t_result );
			}

			for ( $i = 1; $i <= $t_mailbox_connection->numMsg(); $i++ )
			{
				if ( $i > $t_mail_fetch_max )
				{
					break;
				}
				else
				{
					mail_process_single_email( $i, $p_mailbox, $t_mailbox_connection );
				}
			}
		}

		$t_mailbox_connection->disconnect();

		return( $t_result );
	}

	# --------------------
	# return all mails for an imap mailbox
	#  return an empty array if there are no new mails
	function mail_process_all_mails_imap( &$p_mailbox, $p_test_only = false )
	{
		$t_mail_fetch_max	= plugin_config_get( 'mail_fetch_max' );

		$t_mailbox_hostname = explode( ':', $p_mailbox[ 'mailbox_hostname' ], 2 );
		$t_mailbox_connection = &new Net_IMAP( $t_mailbox_hostname[ 0 ], ( ( empty( $t_mailbox_hostname[ 1 ] ) || (int) $t_mailbox_hostname[ 1 ] === 0 ) ? 143 : (int) $t_mailbox_hostname[ 1 ] ) );
		$t_mailbox_username = $p_mailbox[ 'mailbox_username' ];
		$t_mailbox_password = base64_decode( $p_mailbox[ 'mailbox_password' ] );
		$t_mailbox_auth_method = ( ( !empty( $p_mailbox[ 'mailbox_auth_method' ] ) ) ? $p_mailbox[ 'mailbox_auth_method' ] : 'USER' );
		$t_result = $t_mailbox_connection->login( $t_mailbox_username, $t_mailbox_password, $t_mailbox_auth_method );

		if ( $p_test_only === false && PEAR::isError( $t_result ) )
		{
			echo "\n\nerror: " . $p_mailbox[ 'mailbox_description' ] . "\n";
			echo $t_result->toString();
			return( $t_result );
		}

		$t_mailbox_basefolder = $p_mailbox[ 'mailbox_basefolder' ];

		if ( $t_mailbox_connection->mailboxExist( $t_mailbox_basefolder ) )
		{
			if ( $p_test_only === false )
			{
				$t_mailbox_createfolderstructure = $p_mailbox[ 'mailbox_createfolderstructure' ];
				$t_mailbox_project_id = $p_mailbox[ 'mailbox_project' ];

				// There does not seem to be a viable api function which removes this plugins dependability on table column names
				// So if a column name is changed it might cause problems is the code below depends on it.
				// Luckily we only depend on id, name and enabled
				$t_projects = ( ( $t_mailbox_createfolderstructure === true ) ? project_get_all_rows() : array( 0 => project_get_row( $t_mailbox_project_id ) ) );

				foreach ( $t_projects AS $t_project )
				{
					if ( $t_project[ 'enabled' ] == 1 )
					{
						$t_project_name = mail_imap_cleanup_project_names( $t_project[ 'name' ] );

						$t_foldername = $t_mailbox_basefolder . ( ( $t_mailbox_createfolderstructure === true ) ? $t_mailbox_connection->getHierarchyDelimiter() . $t_project_name : NULL );

						if ( $t_mailbox_connection->mailboxExist( $t_foldername ) )
						{
							$t_mailbox_connection->selectMailbox( $t_foldername );

							for ( $i = 1; $i <= $t_mailbox_connection->numMsg(); $i++ )
							{
								if ( $i > $t_mail_fetch_max )
								{
									break 2;
								}
								else
								{
									if ( !$t_mailbox_connection->isDeleted( $i ) )
									{
										mail_process_single_email( $i, $p_mailbox, $t_mailbox_connection, $t_project[ 'id' ] );
									}
								}
							}
						}
						else
						{
							if ( $t_mailbox_createfolderstructure === true )
							{
								// create this mailbox
								$t_mailbox_connection->createMailbox( $t_foldername );
							}
						}
					}
				}
			}
		}
		else
		{
			if ( $p_test_only === false )
			{
				echo "\n\nerror: " . $p_mailbox[ 'mailbox_description' ] . "\n";
				echo 'IMAP basefolder not found.';
				return( $t_result );
			}
			else
			{
				$t_result = array(
					'ERROR_TYPE'	=> 'IMAP_BASEFOLDER_NOTFOUND',
					'ERROR_MESSAGE'	=> "error: " . $p_mailbox[ 'mailbox_description' ] . "\n" . ' -> IMAP basefolder not found.'
				);
				return( $t_result );
			}
		}

		// Rolf Kleef: explicit expunge to remove deleted messages, disconnect() gives an error...
		// EmailReporting 0.7.0: Corrected IMAPProtocol_1.0.3.php on line 704. disconnect() works again
		//$t_mailbox->expunge();
		$t_mailbox_connection->disconnect(true);
		
		return( $t_result );
	}

	function mail_process_single_email( &$p_i, &$p_mailbox, &$p_mailbox_connection , $p_project_id = NULL )
	{
		$t_mail_delete		= plugin_config_get( 'mail_delete' );
		$t_mail_debug		= plugin_config_get( 'mail_debug' );

		$t_msg = $p_mailbox_connection->getMsg( $p_i );

		$t_mail = mail_parse_content( $t_msg );

		if ( $t_mail_debug )
		{
			var_dump( $t_mail );
			mail_save_message_to_file( $t_msg );
		}

		mail_add_bug( $t_mail, $p_mailbox, $p_project_id );

		if ( $t_mail_delete )
		{
			$p_mailbox_connection->deleteMsg( $p_i );
		}
	}

	# --------------------
	# Translate the project name into an IMAP folder name:
	# - translate all accented characters to plain ASCII equivalents
	# - replace all but alphanum chars and space and colon to dashes
	# - replace multiple dots by a single one
	# - strip spaces, dots and dashes at the beginning and end
	# (It should be possible to use UTF-7, but this is working)
	function mail_imap_cleanup_project_names( $p_project_name )
	{
		$t_project_name = $p_project_name;
		$t_project_name = htmlentities( $t_project_name, ENT_QUOTES, 'UTF-8' );
		$t_project_name = preg_replace( "/&(.)(acute|cedil|circ|ring|tilde|uml);/", "$1", $t_project_name );
		$t_project_name = preg_replace( "/([^A-Za-z0-9 ]+)/", "-", html_entity_decode( $t_project_name ) );
		$t_project_name = preg_replace( "/(\.+)/", ".", $t_project_name );
		$t_project_name = trim( $t_project_name, "-. " );

		return( $t_project_name );
	}

	# --------------------
	# return the mail parsed for Mantis
	function mail_parse_content ( &$p_mail ) {
		$t_mail_debug		= plugin_config_get( 'mail_debug' );
		$t_mail_parse_mime	= plugin_config_get( 'mail_parse_mime' );
		$t_mail_parse_html	= plugin_config_get( 'mail_parse_html' );
		$t_mail_use_bug_priority = plugin_config_get( 'mail_use_bug_priority' );
		$t_mail_bug_priority_default = plugin_config_get( 'mail_bug_priority_default' );
		$t_mail_bug_priority	= plugin_config_get( 'mail_bug_priority' );
		$t_mail_add_complete_email	= plugin_config_get( 'mail_add_complete_email' );
		$t_mail_encoding = plugin_config_get( 'mail_encoding' );

		$t_options = array();
		$t_options[ 'parse_mime' ] = $t_mail_parse_mime;
		$t_options[ 'parse_html' ] = $t_mail_parse_html;
		$t_options[ 'mail_encoding' ] = $t_mail_encoding;

		$t_mp = new Mail_Parser( $t_options );

		$t_mp->setInputString( $p_mail );
		$t_mp->parse();

		$t_mail = array();
		$t_mail[ 'From' ] = $t_mp->from();

		$t_mail[ 'Subject' ] = trim( $t_mp->subject() );
		if ( empty( $t_mail[ 'Subject' ] ) ) {
			$t_mail[ 'Subject' ] = plugin_config_get( 'mail_nosubject' );
		}

		$t_mail[ 'X-Mantis-Body' ] = trim( $t_mp->body() );
		if ( empty( $t_mail[ 'X-Mantis-Body' ] ) ) {
			$t_mail[ 'X-Mantis-Body' ] = plugin_config_get( 'mail_nodescription' );
		}

		$t_mail[ 'X-Mantis-Parts' ] = $t_mp->parts();

		if ( true == $t_mail_use_bug_priority ) {
			$t_priority =  strtolower( $t_mp->priority() );
			$t_mail[ 'Priority' ] = $t_mail_bug_priority[ $t_priority ];
		} else {
			$t_mail[ 'Priority' ] = gpc_get_int( 'priority', $t_mail_bug_priority_default );
		}

		if ( true == $t_mail_add_complete_email ) {
			$t_part = array(
				'name' => 'Complete email.txt',
				'ctype' => 'text/plain',
				'body' => $p_mail,
			);

			$t_mail[ 'X-Mantis-Complete' ] = $t_part;
		} else {
			$t_mail[ 'X-Mantis-Complete' ] = null;
		}

		return $t_mail;
	}

	# --------------------
	# return the mailadress from the mail's 'From'
	function mail_parse_address ( $p_mailaddress ) {
		if ( preg_match( "/(.*?)<(.*?)>/", $p_mailaddress, $matches ) )
		{
			$v_mailaddress = array(
				'username' => trim( $matches[ 1 ], '"\' ' ),
				'email' => trim( $matches[ 2 ] ),
			);
		}
		else
		{
			$v_mailaddress = array(
				'name' => '',
				'email' => $p_mailaddress,
			);
		}

		return $v_mailaddress;
	}

	# --------------------
	# return the a valid username from an email address
	function mail_prepare_username ( $p_mailusername ) {
		# I would have liked to validate the username and remove any non-allowed characters
		# using the config user_login_valid_regex but that seems not possible and since
		# it's a config any mantis installation could have a different one
		if ( user_is_name_valid( $p_mailusername[ 'username' ] ) )
		{
			return( $p_mailusername[ 'username' ] );
		}

		return( strtolower( str_replace( array( '@', '.', '-' ), '_', $p_mailusername[ 'email' ] ) ) );
	}

	# --------------------
	# return true if there is a valid mantis bug refererence in subject or return false if not found
	function mail_is_a_bugnote ( $p_mail_subject ) {
		if ( preg_match( "/\[([A-Za-z0-9-_\. ]*\s[0-9]{1,7})\]/", $p_mail_subject ) ){
			$t_bug_id = mail_get_bug_id_from_subject( $p_mail_subject );
			if ( bug_exists( $t_bug_id ) && !bug_is_readonly( $t_bug_id ) ){
				return true;
			}
		}

		return false;
	}

	# --------------------
	# return the bug's id from the subject
	function mail_get_bug_id_from_subject ( $p_mail_subject ) {
		preg_match( "/\[([A-Za-z0-9-_\. ]*\s([0-9]{1,7}?))\]/", $p_mail_subject, $v_matches );

		return $v_matches[ 2 ];
	}
	
	# --------------------
	# return the user id for the mail reporting user
	function mail_get_user ( $p_mailaddress ) {
		$t_mail_use_reporter	= plugin_config_get( 'mail_use_reporter' );
		$t_mail_auto_signup	= plugin_config_get( 'mail_auto_signup' );
		$t_mail_reporter	= plugin_config_get( 'mail_reporter' );
		
		$v_mailaddress = mail_parse_address( $p_mailaddress );

		if ( $t_mail_use_reporter )
		{
			// Always report as mail_reporter
			$t_reporter_id = user_get_id_by_name( $t_mail_reporter );
			$t_reporter = $t_mail_reporter;
		}
		else
		{
			// Try to get the reporting users id
			$t_reporter_id = user_get_id_by_email ( $v_mailaddress[ 'email' ] );

			if ( ! $t_reporter_id )
			{
				if ( $t_mail_auto_signup )
				{
					// So, we have to sign up a new user...
					$t_reporter = mail_prepare_username ( $v_mailaddress );

					if ( user_is_name_valid( $t_reporter ) &&
						user_is_name_unique( $t_reporter ) &&
						email_is_valid( $v_mailaddress[ 'email' ] ) )
					{
						# notify the selected group a new user has signed-up
						if( user_signup( $t_reporter, $v_mailaddress[ 'email' ] ) )
						{
							email_notify_new_account( $t_reporter, $v_mailaddress[ 'email' ] );
							$t_reporter_id = user_get_id_by_name ( $t_reporter );
						}
					}

					if ( ! $t_reporter_id )
					{
						echo 'Failed to create user based on: ' . $p_mailaddress . '<br />Falling back to the mail_reporter<br />';
					}
				}

				if ( ! $t_reporter_id )
				{
					// Fall back to the default mail_reporter
					$t_reporter_id = user_get_id_by_name( $t_mail_reporter );
					$t_reporter = $t_mail_reporter;
				}
			}
			else
			{
				$t_reporter = user_get_field( $t_reporter_id, 'username' );
			}
		}

		echo 'Reporter: ' . $t_reporter_id . '<br />' . $v_mailaddress[ 'email' ] . '<br />';
		auth_attempt_script_login( $t_reporter );

		return $t_reporter_id;
	}

	# --------------------
	# Very dirty: Adds a file to a bug.
	# returns true on success and the filename with reason on error
	function mail_add_file( $p_bug_id, $p_part ) {
		# Handle the file upload
		static $number = 1;
		static $c_bug_id = null;
		static $t_max_file_size = 'empty';

		if ( $t_max_file_size === 'empty' ){
			$t_max_file_size = config_get( 'max_file_size', 2 );
		}

		$t_part_name = ( ( isset( $p_part[ 'name' ] ) ) ? trim( $p_part[ 'name' ] ) : null );
		$t_strlen_body = strlen( $p_part[ 'body' ] );

		if ( empty( $t_part_name ) )
		{
			return( $t_part_name . ' = filename is missing' . "\r\n" );
		}
		elseif( !file_type_check( $t_part_name ) )
		{
			return( $t_part_name . ' = filetype not allowed' . "\r\n" );
		}
		elseif( $t_strlen_body > $t_max_file_size )
		{
			return( $t_part_name . ' = attachment size exceeds maximum allowed file size (' . $t_strlen_body . ' / ' . $t_max_file_size . ')' . "\r\n" );
		}
		else
		{
			if ( $p_bug_id !== $c_bug_id )
			{
				$number = 1;
				$c_bug_id = $p_bug_id;
			}

			while( !file_is_name_unique( $number . '-' . $t_part_name, $p_bug_id ) )
			{
				$number++;
			}

			$t_mail_tmp_directory	= plugin_config_get( 'mail_tmp_directory', '' );
			$t_file_name = ( ( empty( $t_mail_tmp_directory ) ) ? '.' : $t_mail_tmp_directory ) . '/' . md5 ( microtime() );
			$t_method = config_get( 'file_upload_method' );

			file_put_contents( $t_file_name, $p_part[ 'body' ] );

			emailreporting_custom_file_add( $p_bug_id, array(
				'tmp_name' => realpath( $t_file_name ),
				'name' => $number . '-' . $t_part_name,
				'type' => $p_part[ 'ctype' ],
				'error' => null
			), 'bug' );

			if ( $t_method != DISK )
			{
				unlink( $t_file_name );
			}

			$number++;
		}

		return( true );
	}

	# --------------------
	# Saves the complete email to file
	# Only works in debug mode
	function mail_save_message_to_file ( &$p_msg ) {
		$t_mail_debug		= plugin_config_get( 'mail_debug' );
		$t_mail_directory	= plugin_config_get( 'mail_directory' );
		
		if ( $t_mail_debug && is_dir( $t_mail_directory ) && is_writeable( $t_mail_directory ) ) {
			$t_file_name = $t_mail_directory . '/' . time() . md5( microtime() );
			file_put_contents( $t_file_name, $p_msg );
		}
	}

	# --------------------
	# Removes the original mantis email from replies
	# Very experimental new function
	function mail_identify_reply_part ( $p_description )
	{
		$t_mail_identify_reply = plugin_config_get( 'mail_identify_reply' );
		$t_email_separator1 = config_get( 'email_separator1' );

		# The pear mimeDecode.php seems to be removing the last "=" in some versions of the pear package.
		# the version delivered with this package seems to be working OK though
		$t_email_separator1 = substr( $t_email_separator1, 0, -1);

		if ( $t_mail_identify_reply )
		{
			$t_first_occurence = strpos( $p_description, $t_email_separator1 );
			if ( $t_first_occurence !== false && substr_count( $p_description, $t_email_separator1, $t_first_occurence ) >= 3 )
			{
				$t_description = substr( $p_description, 0, $t_first_occurence );

				return( $t_description );
			}
		}

		return( $p_description );
	}

	# --------------------
	# Adds a bug which is reported via email
	# Taken from bug_report.php in MantisBT 1.2.0rc1
	function mail_add_bug ( &$p_mail, &$p_mailbox, $p_imap_project_id = NULL ) {
		$t_mail_save_from	= plugin_config_get( 'mail_save_from' );
		$t_mail_add_complete_email	= plugin_config_get( 'mail_add_complete_email' );

		$t_bug_data = new BugData;
		$t_bug_data->build				= gpc_get_string( 'build', '' );
		$t_bug_data->platform				= gpc_get_string( 'platform', '' );
		$t_bug_data->os					= gpc_get_string( 'os', '' );
		$t_bug_data->os_build				= gpc_get_string( 'os_build', '' );
		$t_bug_data->version			= gpc_get_string( 'product_version', '' );
		$t_bug_data->profile_id			= gpc_get_int( 'profile_id', 0 );
		$t_bug_data->handler_id			= gpc_get_int( 'handler_id', 0 );
		$t_bug_data->view_state			= gpc_get_int( 'view_state', config_get( 'default_bug_view_status' ) );

		$t_bug_data->category_id			= gpc_get_int( 'category_id', $p_mailbox[ 'mailbox_global_category' ] );
		$t_bug_data->reproducibility		= config_get( 'default_bug_reproducibility', 10 );
		$t_bug_data->severity			= config_get( 'default_bug_severity', 50 );
		$t_bug_data->priority			= $p_mail[ 'Priority' ];
		$t_bug_data->projection				= gpc_get_int( 'projection', config_get( 'default_bug_projection' ) );
		$t_bug_data->eta					= gpc_get_int( 'eta', config_get( 'default_bug_eta' ) );
		$t_bug_data->resolution				= config_get( 'default_bug_resolution' );
		$t_bug_data->status					= config_get( 'bug_submit_status' );
		$t_bug_data->summary				= $p_mail[ 'Subject' ];
		
		# Ppostponed the saving of the description until after the bugnote identification
		if ( $t_mail_save_from ) {
			$t_bug_data->description	= 'Email from: ' . $p_mail[ 'From' ] . "\n\n" . $p_mail[ 'X-Mantis-Body' ];
		} else {
			$t_bug_data->description	= $p_mail[ 'X-Mantis-Body' ];
		}

		$t_bug_data->steps_to_reproduce		= gpc_get_string( 'steps_to_reproduce', config_get( 'default_bug_steps_to_reproduce' ) );;
		$t_bug_data->additional_information	= gpc_get_string( 'additional_info', config_get ( 'default_bug_additional_info' ) );
		$t_bug_data->due_date 				= gpc_get_string( 'due_date', '' );
		if ( is_blank ( $t_bug_data->due_date ) ) {
			$t_bug_data->due_date = date_get_null();
		} else {
			$t_bug_data->due_date = $t_bug_data->due_date;
		}

		$t_bug_data->project_id			= ( ( is_null( $p_imap_project_id ) ) ? $p_mailbox[ 'mailbox_project' ] : $p_imap_project_id );

		$t_bug_data->reporter_id		= mail_get_user( $p_mail[ 'From' ] );

		$t_bug_data->summary			= trim( $t_bug_data->summary );

		if ( mail_is_a_bugnote( $p_mail[ 'Subject' ] ) ) {
			# Add a bug note
			$t_bug_id = mail_get_bug_id_from_subject( $p_mail[ 'Subject' ] );

			$t_description = mail_identify_reply_part( $t_bug_data->description );

			bugnote_add ( $t_bug_id, $t_description );

			email_bugnote_add ( $t_bug_id );

			if ( bug_get_field( $t_bug_id, 'status' ) > config_get( 'bug_reopen_status' ) )
			{
				bug_reopen( $t_bug_id );
			}
		} else	{
			# Create the bug
			$t_bug_id = $t_bug_data->create();

			email_new_bug( $t_bug_id );
		}
		
		# Add files
		if ( true == $t_mail_add_complete_email ) {
			array_push( $p_mail[ 'X-Mantis-Parts' ], $p_mail[ 'X-Mantis-Complete' ] );
		}

		if ( null != $p_mail[ 'X-Mantis-Parts' ] ) {
			$t_rejected_files = null;

			foreach ( $p_mail[ 'X-Mantis-Parts' ] as $part ) {
				$t_file_rejected = mail_add_file ( $t_bug_id, $part );

				if ( $t_file_rejected !== true )
				{
					$t_rejected_files .= $t_file_rejected;
				}
			}

			if ( !is_null( $t_rejected_files ) )
			{
				$part = array(
					'name' => 'Rejected files.txt',
					'ctype' => 'text/plain',
					'body' => 'List of rejected files' . "\r\n\r\n" . $t_rejected_files,
				);

				$t_reject_rejected_files = mail_add_file ( $t_bug_id, $part );
				if ( $t_reject_rejected_files !== true )
				{
					echo '"' . $part[ 'name' ] . '" is not allowed as a filetype.' . "\r\n" . $part[ 'body' ];
				}
			}
		}

	}

?>
