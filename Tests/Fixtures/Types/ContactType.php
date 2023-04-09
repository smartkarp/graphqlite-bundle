<?php

namespace TheCodingMachine\GraphQLite\Bundle\Tests\Fixtures\Types;

use TheCodingMachine\GraphQLite\Annotations\ExtendType;
use TheCodingMachine\GraphQLite\Annotations\Field;
use TheCodingMachine\GraphQLite\Bundle\Tests\Fixtures\Entities\Contact;
use function strtoupper;

/**
 * @ExtendType(class=Contact::class)
 */
class ContactType
{
    /**
     * @Field()
     */
    public function uppercaseName(Contact $contact): string
    {
        return strtoupper($contact->getName());
    }
}
