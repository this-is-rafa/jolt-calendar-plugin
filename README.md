# Jolt Google Calendar Wordpress Plugin

A plugin for Jolt Radio to pull events from Google Calendar, sort them, grab corresponding posts, and serve the output through the Wordpress API.

## Usage

You will need to download the Google Calendar PHP client and put it in subdirectory `/gapi`.

Place the plugin folder in your Wordpress plugin folder, activate it, and then configure it via the options. Once configured, calendar data will be available at `example.com/wp/v2/jolt-cal/` and upcoming shows will be at `example.com/wp/v2/jolt-uopcoming/`.