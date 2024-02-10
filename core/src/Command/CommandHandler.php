<?php

namespace Saucy\Core\Command;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class CommandHandler {}
