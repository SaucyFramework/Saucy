<?php

namespace Saucy\Core\Projections;

enum ProjectorType: string
{
    case AllStream = 'allStream';
    case AggregateInstance = 'aggregateInstance';
}
