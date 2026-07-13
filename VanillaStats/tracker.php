<?php
// Serve tracker.js with headers that work on restrictive shared hosts / Chrome ORB
header("Content-Type: application/javascript; charset=UTF-8");
header("X-Content-Type-Options: nosniff");
header("Cross-Origin-Resource-Policy: cross-origin");
readfile(__DIR__ . "/tracker.js");
