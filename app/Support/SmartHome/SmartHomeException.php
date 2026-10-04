<?php

namespace App\Support\SmartHome;

use RuntimeException;

/**
 * Home Assistant or evcc could not be reached or refused the request; the message is shown.
 */
class SmartHomeException extends RuntimeException {}
