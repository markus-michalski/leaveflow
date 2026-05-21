<?php

declare(strict_types=1);

namespace App\Application\Validator;

use Symfony\Component\Validator\Constraint;

#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class ValidLdapFilter extends Constraint
{
    public const string MSG_NO_PLACEHOLDER = 'admin.ldap_filter.error.no_placeholder';
    public const string MSG_DUPLICATE_PLACEHOLDER = 'admin.ldap_filter.error.duplicate_placeholder';
    public const string MSG_UNBALANCED_PARENS = 'admin.ldap_filter.error.unbalanced_parens';
    public const string MSG_ILLEGAL_CHARS = 'admin.ldap_filter.error.illegal_chars';
}
