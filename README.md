# FogFront

Migrate from [Fogbugz](https://www.fogcreek.com/fogbugz/) (self hosted) to [FrontApp](https://frontapp.com/).

It's entirely possible that we're the only people to ever need to make this migration, but on the off chance we're not, here's some codes.

## Goal

Our target for this migration was "good enough". We'd rather not lose our history with the migration, but we didn't want to devote weeks to it.


## Features

 - Does a half-ass job of getting tickets from Fogbugz into FrontApp
 - Imports both public messages and private comments on tickets
 - Reads the rate limiting headers to slow down


## Limitations

 - FrontApp API currently doesn't have a good way to attach private comments to an imported message, to hack around this we imported all threads while appending the fogbugz bug id to the subject line. So "refund please" -> "refund please(3210)"
 - There's only really basic HTML vs Markdown detection
 - When appending private comments, you can't set them to have a historical date. So all private comments are appended with the timestamp of when you ran the call.
 - Imports everything into one FrontApp inbox


## Instructions

 - Create all your users in FrontApp
 - Install [composer](https://getcomposer.org/) packages `composer install`
 - Tell frontapp you want to import things, ask them to raise your API limit.
 - Edit the PHP script with your database credentials
 - Hack `fogfront.php` to call `getInboxList()` and set your inbox throughout the app.
 - Hack `fogfront.php` to call `getTeamList()` and fill out the array at the top of the script
 - Edit your bearer token into the script (two places)
 - Edit the SQL to select all the rows attached to one bug id, do a test run
 - Edit the SQL back, run it for real. Use screen, it will take a while.


