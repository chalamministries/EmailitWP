<?php

namespace EmailIt;

/**
 * @deprecated Use DomainManager instead.
 */
class SendingDomainManager extends DomainManager
{
    public function __construct(EmailItClient $client)
    {
        parent::__construct($client);
        trigger_error(
            'SendingDomainManager is deprecated. Use DomainManager instead.',
            E_USER_DEPRECATED
        );
    }
}
