<?php

namespace App\Support\PeopleCounter;

final class PeopleCounterSamplingWindow
{
    public const int StartBufferMinutes = 5;

    public const int EndBufferMinutes = 2;

    public const int SummarizeDelayMinutes = 5;
}
