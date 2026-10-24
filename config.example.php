<?php

return [
    // The first real run chooses Sunday automatically and saves it in var/state.json.
    // Fixed GMT+0, without daylight-saving time.
    'timezone' => 'GMT',
    'commits_per_pixel' => 5,

    // Create a separate EMPTY GitHub repository (no README or license).
    'remote' => 'git@github.com:YOUR_USERNAME/YOUR_CANVAS_REPO.git',
    'author_name' => 'Your Name',
    // Copy your GitHub noreply address from https://github.com/settings/emails.
    'author_email' => 'you@example.com',
];
