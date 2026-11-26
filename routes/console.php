<?php

use Illuminate\Support\Facades\Schedule;

// Hourly, so each user's weekly scan starts soon after midnight on their landing day where they live
// (or as soon as the machine wakes up after it). The command skips anyone whose scan has already started.
Schedule::command('events:discover')->hourly();
