<?php

namespace Atoria\Alfred\PullRequests;

use RuntimeException;

/** A git provider could not be reached, refused, or the edits no longer fit the base branch. Shown to the user. */
class PullRequestException extends RuntimeException {}
