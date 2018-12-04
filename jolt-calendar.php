<?php
/*
Plugin Name: Jolt Calendar
Plugin URI: http://joltradio.org
Description: A plugin to pull google calendar events and serve them as JSON
Version: 0.1
Author: Rafa
Author URI: ratradio.net
Text Domain: jolt-calendar
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

Copyright © 2018 Rafa

This program is free software; you can redistribute it and/or modify
it under the terms of the GNU General Public License as published by
the Free Software Foundation; either version 2 of the License, or
(at your option) any later version.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

*/

require_once 'gapi/vendor/autoload.php';


function getClient()
{
    $client = new Google_Client();
    $client->setApplicationName('Jolt Calendar');
    $client->setScopes( array(Google_Service_Calendar::CALENDAR_READONLY) );
    $client->setDeveloperKey( get_option('jolt_gapiKey', 'none') );
    $client->setAccessType('online');

    return $client;
}


function updateCalendarListings() {
  $client = getClient();
  $service = new Google_Service_Calendar($client);
  
  //Set our start and end times
  $timeMin = new DateTime('NOW');
  $timeMin->setTimezone(new DateTimeZone('America/New_York'));
  $timeMin->setTime(0,0);

  $timeMax = new DateTime('NOW');
  $timeMax->setTimezone(new DateTimeZone('America/New_York'));
  $timeMax->setTime(0,0);
  $timeMax->modify('+8 days');

  //echo 'min: ' . $timeMin->format('c') . ' max: ' . $timeMax->format('c');

  $calendarId = get_option('jolt_gcalId', 'none');
  $optParams = array(
    'maxResults' => 250,
    'orderBy' => 'startTime',
    'singleEvents' => true,
    'timeMin' => $timeMin->format('c'),
    'timeMax' => $timeMax->format('c'),
  );
  $results = $service->events->listEvents($calendarId, $optParams);
  $events = $results->getItems();

  if (empty($events)) {
      return '{0:"No upcoming events found."}';
  } else {
      $trimEvents = sortCalendar($events);
      update_option('jolt_calendarEvents', $trimEvents);
  }
}

function getCalendarListings() {
  return get_option('jolt_calendarEvents', 'none');
}

function sortCalendar($cal) {
  //$cal = json_decode($cal, true);

  $newCal = [];
  $i = 0;
  foreach ($cal as $event) {
    $timestamp = new DateTime($event['start']['dateTime']);
    $eventHour = $timestamp->format('g:iA');

    //Set the timestamp to midnight, to group events by date
    $timestamp->setTime(0,0);
    $timestamp = $timestamp->format('D M j');
    
    //Get only what we need to display in the frontend
    $trimEvent = [
      'title' => $event['summary'],
      'startTime' => $eventHour
    ];
    
    if ( !isset($newCal[$i] ) ) {
      $newCal[] = [
        'date' => $timestamp,
        'events' =>  [$trimEvent]
      ];
    } else if ( $newCal[$i]['date'] == $timestamp ) {
      $newCal[$i]['events'][] = $trimEvent;
    } else {
      $newCal[] = [
        'date' => $timestamp,
        'events' =>  [$trimEvent]
      ];
      $i++;
    }
  }

  return $newCal;
}


// Set up the admin settings page.
add_action( 'admin_menu', 'addSettingsPage' );
//add_action( 'admin_init', 'add_settings' );
//add_action( 'admin_enqueue_scripts', 'admin_enqueue_scripts' );

function addSettingsPage() {
  add_options_page(
    'Jolt Cal',
    'Jolt Cal',
    'manage_options',
    'jolt-cal',
    'createAdminPage'
  );
}

function createAdminPage() { 
  if (!current_user_can('manage_options')) {
    wp_die('Unauthorized user');
  }

  if ( ! isset( $_POST['jolt_calendar_settings_noncer'] ) 
    || ! wp_verify_nonce( $_POST['jolt_calendar_settings_noncer'], 'jolt_calendar' ) 
  ) {
    print 'Sorry, your nonce did not verify.';
  } else {

    if (isset($_POST['jolt_refresh'])) {
      updateCalendarListings();
      print "refreshed";
    }

    if (isset($_POST['jolt_gapiKey'])) {
      $gapiKey = $_POST['jolt_gapiKey'];
      $gapiKey = trim($gapiKey);
      $gapiKey = strip_tags($gapiKey);
      update_option('jolt_gapiKey', $gapiKey);
    }

    if (isset($_POST['jolt_gcalId'])) {
      $gcalId = $_POST['jolt_gcalId'];
      $gcalId = trim($gcalId);
      $gcalId = strip_tags($gcalId);
      update_option('jolt_gcalId', $gcalId);
    }
  }

  $gapiKey = get_option('jolt_gapiKey', 'none');
  $gcalId = get_option('jolt_gcalId', 'none');

  ?>
  <div>
    <h2>Jolt Calendar</h2>
    <h3>Credentials</h3>
    <form method="post">
      <p>
        <label>
          Google API Key
          <input name="jolt_gapiKey" type="text" value="<?= $gapiKey ?>" />
        </label>
      </p>
      <p>
        <label>
          Google Calendar ID (Must be public)
          <input name="jolt_gcalId" type="text" value="<?= $gcalId ?>" />
        </label>
      </p>
      <?php wp_nonce_field( 'jolt_calendar', 'jolt_calendar_settings_noncer' ); ?>
      <input type="Submit" value="Save" class="button button-primary button-large">
    </form>
    <h3>Refresh Calendar</h3>
    <p>By default, the calendar is pulled from Google once a day.</p>
    <form method="post">
      <?php wp_nonce_field( 'jolt_calendar', 'jolt_calendar_settings_noncer' ); ?>
      <input type="hidden" name="jolt_refresh" value="true" />
      <input type="Submit" value="Refresh" class="button button-primary button-large" />
    </form>
  </div>

<?php }

add_action( 'rest_api_init', function () {
	register_rest_route( 'wp/v2', '/jolt-cal', array(
		'methods' => 'GET',
		'callback' => 'getCalendarListings',
	) );
} );