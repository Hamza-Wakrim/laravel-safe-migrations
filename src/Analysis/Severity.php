<?php

namespace HamzaWakrim\SafeMigrations\Analysis;

enum Severity: string
{
    case Error = 'error';
    case Warning = 'warning';
}
