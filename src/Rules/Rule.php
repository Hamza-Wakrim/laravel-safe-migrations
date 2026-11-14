<?php

namespace HamzaWakrim\SafeMigrations\Rules;

use HamzaWakrim\SafeMigrations\Analysis\Context;
use HamzaWakrim\SafeMigrations\Analysis\Finding;
use HamzaWakrim\SafeMigrations\Analysis\Statement;

interface Rule
{
    /** Stable identifier used in config, the AllowUnsafe attribute and output. */
    public function id(): string;

    /** @return iterable<Finding> */
    public function inspect(Statement $statement, Context $context): iterable;
}
