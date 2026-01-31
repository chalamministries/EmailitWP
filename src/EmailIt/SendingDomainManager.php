<?php

namespace EmailIt;

class SendingDomainManager extends DomainManager
{
	public function __construct(EmailItClient $client)
	{
		trigger_error(
			'SendingDomainManager is deprecated. Use EmailIt\\DomainManager instead.',
			E_USER_DEPRECATED
		);

		parent::__construct($client);
	}
}