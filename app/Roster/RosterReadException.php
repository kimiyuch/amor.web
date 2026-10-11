<?php

namespace App\Roster;

use RuntimeException;

/** A reading failure whose message can be shown to the user as is. */
final class RosterReadException extends RuntimeException {}
