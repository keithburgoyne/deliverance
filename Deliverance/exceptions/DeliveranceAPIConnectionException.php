<?php

require_once 'Deliverance/exceptions/DeliveranceException.php';

/**
 * Exception caused by Deliverance Campaign API calls that have connection
 * issues.
 *
 * Example exception causes are API endpoint being unavailable or return invald
 * results.
 *
 * @package   Deliverance
 * @copyright 2011-2015 silverorange
 */
class DeliveranceAPIConnectionException extends DeliveranceException
{
}

?>
