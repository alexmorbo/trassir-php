<?php

namespace AlexMorbo\Trassir\Enum;

enum ConnectionState: int
{
    case INIT = 0;
    case HAVE_SID = 1;
    case AUTH_ERROR = 2;
}