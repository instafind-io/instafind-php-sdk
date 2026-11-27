<?php

declare(strict_types=1);

namespace Instafind\Sdk\Exception;

/**
 * Instafind has no such index for this caller: a deleted index, one being
 * deleted, and one the credential may not see all get this answer (404).
 */
class IndexNotFoundException extends ApiException {}
