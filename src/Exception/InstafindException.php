<?php

declare(strict_types=1);

namespace Instafind\Sdk\Exception;

/**
 * Base class of everything the SDK throws. Catch it for "the Instafind call
 * did not work"; catch a subclass when the reason matters.
 *
 * - {@see ApiException}: Instafind answered with an error, or with something
 *   that is not the expected JSON.
 * - {@see TransportException}: no answer arrived.
 * - {@see InvalidSchemaException}: an index schema breaks a rule of the API,
 *   found before anything was sent.
 */
class InstafindException extends \RuntimeException {}
