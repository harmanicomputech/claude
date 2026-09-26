<?php
// Copy this file to volunteer-config.php, ideally ONE LEVEL ABOVE public_html
// (next to it, not inside it), and fill in the values. Never commit the real file.
return [
    // Where new sign-ups are emailed. Leave empty to only save them to the CSV.
    'team_email' => '',
    // Sender address; use one on your own domain, e.g. no-reply@yourdomain.com
    'from_email' => '',
    // Folder for volunteers.csv. The default is outside public_html so it can't be downloaded.
    // 'data_dir' => '/home/USERNAME/volunteer-data',
    // Any long random string (used to scramble IP addresses for the rate limit).
    'ip_salt' => 'change-me-to-a-long-random-string',
    'max_per_ip_hour' => 5,
];
