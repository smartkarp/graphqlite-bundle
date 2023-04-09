<?php

namespace TheCodingMachine\GraphQLite\Bundle\Server;

use GraphQL\Language\AST\DocumentNode;
use GraphQL\Server\OperationParams;
use GraphQL\Validator\DocumentValidator;
use GraphQL\Validator\Rules\ValidationRule;
use function array_merge;
use function is_callable;

/**
 * A slightly modified version of the server config: default validators are added by default when setValidators is
 * called.
 */
class ServerConfig extends \GraphQL\Server\ServerConfig
{
    /**
     * Set validation rules for this server, AND adds by default all the "default" validation rules provided by Webonyx
     *
     * @param ValidationRule[]|callable $validationRules
     *
     * @api
     */
    public function setValidationRules($validationRules): self
    {
        parent::setValidationRules(
            static function (
                OperationParams $params,
                DocumentNode    $doc,
                string          $operationType
            ) use ($validationRules): array {
                $validationRules = is_callable($validationRules)
                    ? $validationRules($params, $doc, $operationType)
                    : $validationRules;

                return array_merge(DocumentValidator::defaultRules(), $validationRules);
            }
        );

        return $this;
    }
}
