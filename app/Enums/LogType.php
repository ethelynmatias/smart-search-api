<?php

namespace App\Enums;

enum LogType: string
{
    case Webhook = 'webhook';
    case Api = 'api';
}
