<?php

namespace TheCodingMachine\GraphQLite\Bundle\GraphiQL;

use Overblog\GraphiQLBundle\Config\GraphiQLControllerEndpoint;
use Overblog\GraphiQLBundle\Config\GraphQLEndpoint\GraphQLEndpointInvalidSchemaException;
use Symfony\Component\HttpFoundation\RequestStack;
use Webmozart\Assert\Assert;

final class EndpointResolver implements GraphiQLControllerEndpoint
{
    public function __construct(
        private readonly RequestStack $requestStack
    ) {
    }

    public function getBySchema($name): string
    {
        if ('default' === $name) {
            $request = $this->requestStack->getCurrentRequest();
            Assert::notNull($request);

            return $request->getBaseUrl().'/graphql';
        }

        throw GraphQLEndpointInvalidSchemaException::forSchemaAndResolver($name, self::class);
    }

    public function getDefault(): string
    {
        return $this->getBySchema('default');
    }
}
