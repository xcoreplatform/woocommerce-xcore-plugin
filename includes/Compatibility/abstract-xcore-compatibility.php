<?php

defined( 'ABSPATH' ) || exit;

abstract class Abstract_Xcore_Compatibility
{
	private ?WC_Logger_Interface $logger = null;

	protected function isActive($plugins)
	{
		foreach ($plugins as $plugin) {
			if (is_plugin_active( $plugin)) {
				return true;
			}
		}
		return false;
	}

	protected function log($level, $logMsg)
    {
        if (is_null($this->logger)) {
            $this->logger = wc_get_logger();
        }

		if (is_array($logMsg) || is_object( $logMsg)) {
			$logMsg = print_r($logMsg, true);
		}

        if ($this->logger) {
            $this->logger->log($level, $logMsg, ['source' => 'xcore-rest-api']);
        }
    }
}