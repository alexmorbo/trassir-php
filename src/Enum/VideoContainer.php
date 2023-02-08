<?php

namespace AlexMorbo\Trassir\Enum;

enum VideoContainer: string
{
    case HLS = 'hls';
    case RTSP = 'rtsp';
}