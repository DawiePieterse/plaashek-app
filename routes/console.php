<?php

// Modules add their own scheduled jobs from their service providers (e.g. the Weather module's nightly
// weather_days fill). One cPanel cron per farm runs `php artisan schedule:run` every minute.
