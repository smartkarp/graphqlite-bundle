<?php

namespace TheCodingMachine\GraphQLite\Bundle\Tests\Fixtures\Controller;

use Exception;
use GraphQL\Error\ClientAware;

class MyException extends Exception implements ClientAware
{
    /**
     * Returns string describing a category of the error.
     *
     * Value "graphql" is reserved for errors produced by query parsing or validation, do not use it.
     *
     * @return string
     *
     * @api
     */
    public function getCategory(): string
    {
        return 'foobar';
    }

    /**
     * Returns true when exception message is safe to be displayed to a client.
     *
     * @return bool
     *
     * @api
     */
    public function isClientSafe(): bool
    {
        return true;
    }
}
