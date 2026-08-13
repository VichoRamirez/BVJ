<?php

namespace App\Enums;

enum NewsCategory: string
{
    case Economy = 'economy';
    case Markets = 'markets';
    case Companies = 'companies';
    case Politics = 'politics';
    case International = 'international';
    case Technology = 'technology';
    case Other = 'other';
}
