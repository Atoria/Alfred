<?php

namespace Atoria\Alfred\Tickets;

use RuntimeException;

/** A ticket tracker could not be reached or refused the request. The message is shown to the user. */
class TrackerException extends RuntimeException {}
