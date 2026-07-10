<?php
require_once __DIR__ . '/includes/helpers.php';
startSecureSession();
session_destroy();
redirect('/admissions');
