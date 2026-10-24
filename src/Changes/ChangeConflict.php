<?php

namespace Atoria\Alfred\Changes;

use RuntimeException;

/** A change can't be applied / undone in the current state of the files. */
class ChangeConflict extends RuntimeException {}
