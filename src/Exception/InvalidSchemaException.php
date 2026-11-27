<?php

declare(strict_types=1);

namespace Instafind\Sdk\Exception;

/**
 * An index schema breaks a rule the API enforces. Thrown before any request:
 * field types and the langcode cannot change once an index exists, so a
 * mistake found by the API costs a new index.
 */
class InvalidSchemaException extends InstafindException {}
