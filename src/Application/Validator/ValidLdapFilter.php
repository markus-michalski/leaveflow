<?php

declare(strict_types=1);

/*
 * This file is part of LeaveFlow.
 *
 * (c) Markus Michalski <ich@markus-michalski.net>
 *
 * For the full copyright and license information, please view the LICENSE.md
 * file that was distributed with this source code.
 */

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
