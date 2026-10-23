<?php

namespace Kestrel\Router\Exception;

class MethodNotAllowedException extends RouterException
{
    /** @var string[] */
    private $allowedMethods = array();

    public function __construct(array $allowedMethods, $message = '', $code = 0, $previous = null)
    {
        $this->allowedMethods = $allowedMethods;

        if ($message === '') {
            $message = 'Method not allowed. Allowed methods: ' . implode(', ', $allowedMethods);
        }

        parent::__construct($message, $code, $previous);
    }

    public function getAllowedMethods()
    {
        return $this->allowedMethods;
    }
}
