<?php

namespace Marketplace\Services;

use RuntimeException;

/** A core release that cannot be stored; the message is shown to the administrator. */
class CoreReleaseException extends RuntimeException {}
