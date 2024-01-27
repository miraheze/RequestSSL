<?php

namespace Miraheze\RequestSSL\Hooks;

interface RequestSSLDomainCheckHook {
	/**
	 * @param RequestSSLManager &$requestSslManager
	 * @param bool isPointed
	 * @return void
	 */
	public function onRequestSSLDomainCheck( &$requestSslManager, &$isPointed ): void;
}
