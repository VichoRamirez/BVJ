<?php

namespace App\Exceptions;

use App\Contracts\AnalyzerUnavailable;

class OpenRouterConfigurationException extends NewsAnalysisException implements AnalyzerUnavailable {}
