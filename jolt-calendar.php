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


function updateCalendarListings($numDays = null) {
  if (null === $numDays) {
    $numDays = 3;
  }

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
      $events = addShowSlugsToEvents($events);
      $trimEvents = sortCalendar($events, $numDays);
      $upcomingShows = getScheduleShows($events);
      update_option('jolt_calendarEvents', $trimEvents);
      update_option('jolt_upcomingShows', $upcomingShows);
  }
}

function getCalendarListings() {
  return get_option('jolt_calendarEvents', 'none');
}

function getUpcomingShows() {
  return get_option('jolt_upcomingShows', 'none');
}

function addShowSlugsToEvents($events) {
  $showsArr = [];

  foreach ($events as $event) {
    //Make sure our description is a number
    $description = $event['description'];
    $showID = "0";

    if ( is_numeric($description) ) {
      $showID = $description;
    }

    $showsArr[] = $showID;
  }

  $args = array(
    'post_type' => 'cpt_artist',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'meta_query' => array(
        array(
            'key' => 'calendar_id',
            'value' => $showsArr,
            'compare' => 'IN'
        )
    )
  );

  $query = new WP_Query($args);

  if ( $query->have_posts() ) {
    $showDetails = [];

    while ( $query->have_posts() ) {
      $query->the_post();

      $showDetails[] = array(
        'calendar_id' => get_field('calendar_id'),
        'slug' => get_post_field( 'post_name', get_the_ID() ),
        'post_id' => get_the_ID()
      );
    } //endwhile
  }//endif

  foreach ($showDetails as $show) {
    foreach ($events as &$event) {
      if ($event['description'] === $show['calendar_id']) {
        $event['calendar_id'] = $show['calendar_id'];
        $event['slug'] = $show['slug'];
        $event['post_id'] = $show['post_id'];
      }
    }
  }

  return $events;
} 

function sortCalendar($cal, $numDays) {
  if (null === $numDays) {
    $numDays = 3;
  }

  $numDays = $numDays - 2;

  $newCal = [];
  $i = 0;
  foreach ($cal as $event) {
    $timestamp = new DateTime($event['start']['dateTime']);
    $eventHour = $timestamp->format('g:iA');

    //Set the timestamp to midnight, to group events by date
    $timestamp->setTime(0,0);
    $timestamp = $timestamp->format('D M j');

    //Make sure our description is a number
    $description = $event['description'];
    $showID = "0";

    if ( is_numeric($description) ) {
      $showID = $description;
    }
    
    //Get only what we need to display in the frontend
    $trimEvent = [
      'title' => $event['summary'],
      'startTime' => $eventHour,
      'timeStamp' => strtotime($event['start']['dateTime']),
      //'showID' => $showID
    ];

    if ($event['calendar_id'] !== null ) {
      $postDetails = [
        'calendar_id' => $event['calendar_id'],
        'slug' => $event['slug'],
        'post_id' => $event['post_id']
      ];
      $trimEvent = array_merge($trimEvent, $postDetails);
    }
    
    if ( !isset($newCal[$i] ) ) {
      $newCal[] = [
        'date' => $timestamp,
        'events' =>  [$trimEvent]
      ];
    } else if ( $newCal[$i]['date'] == $timestamp ) {
      $newCal[$i]['events'][] = $trimEvent;
    } else {
      if ($i > $numDays) break;
      $newCal[] = [
        'date' => $timestamp,
        'events' =>  [$trimEvent]
      ];
      $i++;
    }
  }

  return $newCal;
}

function getScheduleShows($events) {
  $showsArr = [];
  
  foreach ($events as $event) {
    $description = $event['description'];
    $showID = "0";

    if ( is_numeric($description) ) {
      $showID = $description;
    }

    $showsArr[] = $showID;
  }

  $args = array(
    'post_type' => 'cpt_artist',
    'posts_per_page' => -1,
    'post_status' => 'publish',
    'meta_query' => array(
        array(
            'key' => 'calendar_id',
            'value' => $showsArr,
            'compare' => 'IN'
        )
    )
  );

  $query = new WP_Query($args);

  if ( $query->have_posts() ) {
    $shows = [];

    while ( $query->have_posts() ) {
      $query->the_post();

      //Build our array to macth Wordpress's default REST response
      $shows[] = array(
        'title' => array('rendered' => get_the_title() ),
        'acf' => array(
          'schedule_text' => get_field('schedule_text'),
          'calendar_id' => get_field('calendar_id')
        ),
        'slug' => get_post_field( 'post_name', get_the_ID() ),
        '_embedded' => array(
          'wp:featuredmedia' => array(
            array(
              'media_details' => array(
                'sizes' => array(
                  'card' => array(
                    'source_url' => get_the_post_thumbnail_url( get_the_ID(), 'card')
                  )
                )
              )
            )
          )
        )
      );

    }
  }

  foreach ($events as $event) {
    $showID = $event['description'];

    if ( is_numeric($showID) ) {

      foreach ($shows as &$show) {
        if ( $show['acf']['calendar_id'] === $showID ) {
          if ( empty($show['start_time']) ) {
            $show['start_time'] = strtotime($event['start']['dateTime']);
          }
        }
      }

    }
  }

  usort($shows, "sortByTime");

  return $shows;
}

function sortByTime( $a, $b ) {
  return $a['start_time'] - $b['start_time'];
}

// Set up the admin settings page.
add_action( 'admin_menu', 'addSettingsPage' );

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

  $gapiKey = get_option('jolt_gapiKey', 'none');
  $gcalId = get_option('jolt_gcalId', 'none');
  $calDays = get_option('jolt_calDays', 'none');

  if ( ! isset( $_POST['jolt_calendar_settings_noncer'] ) 
    || ! wp_verify_nonce( $_POST['jolt_calendar_settings_noncer'], 'jolt_calendar' ) 
  ) {
    print 'Sorry, your nonce did not verify.';
  } else {

    if (isset($_POST['jolt_refresh'])) {
      updateCalendarListings($calDays);
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

    if (isset($_POST['jolt_calDays'])) {
      $calDays = $_POST['jolt_calDays'];
      $calDays = trim($calDays);
      $calDays = strip_tags($calDays);
      if ( preg_match('/^\d+$/', $calDays) ) {
        update_option('jolt_calDays', $calDays);
      }
    }
  }

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
      <p>
        <label>
          Number of days ahead to show in schedule. 
          <input name="jolt_calDays" type="text" value="<?= $calDays ?>" />
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

add_action( 'rest_api_init', function () {
	register_rest_route( 'wp/v2', '/jolt-upcoming', array(
		'methods' => 'GET',
		'callback' => 'getUpcomingShows',
	) );
} );

register_activation_hook( __FILE__, 'jolt_activation' );
register_deactivation_hook( __FILE__, 'jolt_deactivation' );

add_action( 'jolt_update_hourly', 'updateCalendarListings', 10, 2 );

 
function jolt_activation() {
  if ( !wp_next_scheduled( 'jolt_update_hourly' ) ) {
    wp_schedule_event( time(), 'hourly', 'jolt_update_hourly' );
  }
}

function jolt_deactivation() {
    wp_clear_scheduled_hook( 'jolt_update_hourly' );
}