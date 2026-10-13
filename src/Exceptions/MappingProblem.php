<?php

namespace Jma\Connect\Exceptions;

use RuntimeException;

/**
 * A mapping's settings don't fit the data or the schema. Fixed on the
 * mapping screens, not in code.
 */
class MappingProblem extends RuntimeException {}
