<?php

return [
    // Edit the picture directly: # is a pixel, a dot or space is background.
    'drawing_file' => 'DRAW-TEXT.txt',
    // Start on a Sunday. Each column of the drawing takes one week.
    'start_date' => '2026-10-25',
    'timezone' => 'Europe/Kyiv',
    'commits_per_pixel' => 5,

    // Create a separate EMPTY GitHub repository (no README or license).
    'remote' => 'git@github.com:YOUR_USERNAME/YOUR_CANVAS_REPO.git',
    'author_name' => 'Your Name',
    // Copy your GitHub noreply address from https://github.com/settings/emails.
    'author_email' => 'you@example.com',

    // A dedicated working copy. Never point this at a real project's repository.
    'canvas_dir' => 'var/canvas',
];
